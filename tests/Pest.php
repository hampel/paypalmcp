<?php

use App\Mcp\Servers\PayPalServer;
use App\PayPal\DailyBudget;
use App\PayPal\Gateway;
use App\PayPal\MeteredClient;
use App\PayPal\TransactionHistory;
use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses()->beforeEach(function () {
    // No test may reach the network. An inventory on a developer's machine names real accounts with
    // real apps, so a fake that failed to intercept would read real payers.
    Http::preventStrayRequests();

    $state = sys_get_temp_dir().'/paypalmcp-test-'.getmypid().'-'.bin2hex(random_bytes(4));

    config([
        'paypalmcp.timezone' => 'UTC',
        'paypalmcp.state_path' => $state,
        'paypalmcp.state_path_set' => true,
        // a fixture, not a location: nothing parses it, useAccounts() supplies the accounts
        'paypalmcp.accounts_path' => '/nonexistent/accounts.toml',
        'paypalmcp.daily_budget' => 500,
        // tokens, budgets and months in memory, never on disk
        'cache.stores.paypalmcp' => ['driver' => 'array', 'serialize' => true],
    ]);

    app('cache')->forgetDriver('paypalmcp');

    foreach ([DailyBudget::class, TransactionHistory::class, Gateway::class, PayPalManager::class, MeteredClient::class, 'paypal.http_client', 'paypal.token_store'] as $binding) {
        app()->forgetInstance($binding);
    }

    // three days after the fixtures' rows, so November is still open
    Carbon::setTestNow('2026-11-21T00:00:00Z');

    useAccounts([
        'acme' => ['name' => 'Acme Example Pty Ltd', 'environment' => 'sandbox'],
        'other' => ['name' => 'Other Example Pty Ltd', 'environment' => 'live', 'daily_budget' => 20],
    ]);
})->in('Feature');

uses()->afterEach(function () {
    Carbon::setTestNow();
})->in('Feature');

const SECRET = 'secret-that-must-never-be-shown';

/**
 * Bind an inventory that answers from an array instead of reading a file. Each account gets an app,
 * client-<key> and SECRET, unless it names its own; a null leaves that setting out.
 *
 * @param  array<string, mixed>|AccountInventoryException  $accounts
 */
function useAccounts(array|AccountInventoryException $accounts): void
{
    if (is_array($accounts)) {
        foreach ($accounts as $key => $row) {
            $accounts[$key] = array_filter(
                $row + ['client_id' => 'client-'.$key, 'secret' => SECRET],
                fn (mixed $value): bool => $value !== null,
            );
        }
    }

    app()->instance(AccountInventory::class, new class($accounts) extends AccountInventory
    {
        public function __construct(private array|AccountInventoryException $fixture) {}

        public function raw(): array
        {
            if ($this->fixture instanceof AccountInventoryException) {
                throw $this->fixture;
            }

            return $this->fixture;
        }
    });

    app()->forgetInstance(Gateway::class);
}

/** @return array<mixed> */
function paypalFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fake PayPal, sandbox and live alike. Keys are paths after the host, values a fixture name, a
 * [fixture|body, status] pair, a ready response, or a closure. A token is always faked, first,
 * because every client asks for one before its first request.
 *
 * @param  array<string, mixed>  $routes
 */
function fakePayPal(array $routes, mixed $token = 'token'): void
{
    $fakes = [];

    foreach (['v1/oauth2/token' => $token] + $routes as $path => $answer) {
        $response = match (true) {
            is_string($answer) => Http::response(paypalFixture($answer)),
            is_array($answer) => Http::response(is_string($answer[0]) ? paypalFixture($answer[0]) : $answer[0], $answer[1]),
            default => $answer,
        };

        $fakes['api-m.sandbox.paypal.com/'.$path] = $response;
        $fakes['api-m.paypal.com/'.$path] = $response;
    }

    Http::fake($fakes);
}

/**
 * A transaction search page holding these rows, as PayPal answers one.
 *
 * @param  list<array<mixed>>  $rows
 * @return array<mixed>
 */
function searchPage(array $rows, int $totalPages = 1, ?string $endDate = null): array
{
    return [
        'transaction_details' => $rows,
        'account_number' => 'XZXSPECPDZHZU',
        'last_refreshed_datetime' => $endDate ?? '2026-11-20T22:59:59Z',
        'end_date' => $endDate,
        'page' => 1,
        'total_items' => count($rows),
        'total_pages' => $totalPages,
    ];
}

/** @return list<array<mixed>> the fixture's twelve sandbox rows */
function fixtureRows(): array
{
    return paypalFixture('transactions')['transaction_details'];
}

/** @return list<Request> every request that reached the (faked) wire */
function sentRequests(): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->all();
}

/** @return list<Request> the requests that were not for a token */
function dataRequests(): array
{
    return array_values(array_filter(sentRequests(), fn (Request $r): bool => ! str_contains($r->url(), '/oauth2/token')));
}

/**
 * Run a command and return its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

/** @return array<mixed> the structured answer of a tool call that succeeded */
function answer(string $tool, array $arguments): array
{
    $response = PayPalServer::tool($tool, $arguments);
    $response->assertHasNoErrors();

    $content = (fn () => $this->response->toArray())->call($response);

    return $content['result']['structuredContent'];
}

/** The text of a tool call that failed. */
function refusal(string $tool, array $arguments): string
{
    $response = PayPalServer::tool($tool, $arguments);
    $response->assertHasErrors();

    $content = (fn () => $this->response->toArray())->call($response);

    return $content['result']['content'][0]['text'];
}

/**
 * Fake transaction search month by month: each search is answered with the rows of $byMonth whose
 * initiation date falls inside the window asked for, as PayPal would. A month with an `end` cuts
 * the answer back to it, as PayPal does at its last refresh.
 *
 * @param  list<array<mixed>>  $rows
 */
function fakeSearch(array $rows, ?string $refreshedTo = null, array $extra = []): void
{
    fakePayPal($extra + ['v1/reporting/transactions*' => function (Request $request) use ($rows, $refreshedTo) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $start = new DateTimeImmutable($query['start_date']);
        $end = new DateTimeImmutable($query['end_date']);

        if ($refreshedTo !== null && $end > new DateTimeImmutable($refreshedTo)) {
            $end = new DateTimeImmutable($refreshedTo);
        }

        $inside = array_values(array_filter($rows, function (array $row) use ($start, $end, $query): bool {
            $at = new DateTimeImmutable($row['transaction_info']['transaction_initiation_date']);

            return $at >= $start && $at <= $end
                && (! isset($query['transaction_id']) || $row['transaction_info']['transaction_id'] === $query['transaction_id']);
        }));

        return Http::response(searchPage($inside, 1, $end->format('Y-m-d\TH:i:s\Z')));
    }]);
}

/** One fixture row, moved to another moment and given another id. */
function rowAt(string $at, string $id, string $code = 'T0006', string $amount = '10.00', string $currency = 'USD', array $payer = []): array
{
    return [
        'transaction_info' => [
            'transaction_id' => $id,
            'transaction_event_code' => $code,
            'transaction_initiation_date' => $at,
            'transaction_updated_date' => $at,
            'transaction_amount' => ['currency_code' => $currency, 'value' => $amount],
            'fee_amount' => ['currency_code' => $currency, 'value' => '-0.50'],
            'transaction_status' => 'S',
        ],
        'payer_info' => $payer + [
            'account_id' => 'PAYER'.$id,
            'email_address' => 'someone@example.com',
            'payer_name' => ['given_name' => 'Sam', 'surname' => 'Example'],
            'phone_number' => ['country_code' => '61', 'national_number' => '400000000'],
            'address' => ['line1' => '1 Secret Street', 'city' => 'Hidden'],
            'country_code' => 'AU',
        ],
        'shipping_info' => ['name' => 'Sam Example', 'address' => ['line1' => '1 Secret Street']],
    ];
}
