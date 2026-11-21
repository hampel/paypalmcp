<?php

use App\Mcp\Tools\AccountAccess;
use App\Mcp\Tools\Balances;
use App\Mcp\Tools\FindPayer;
use App\Mcp\Tools\GetSubscription;
use App\Mcp\Tools\GetTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\TransactionTotals;
use Illuminate\Support\Facades\Http;

it('lists the accounts without contacting PayPal, and without a credential', function () {
    $answer = answer(ListAccounts::class, []);

    expect($answer['count'])->toBe(2)
        ->and($answer['accounts'][1])->toMatchArray(['account' => 'other', 'environment' => 'live', 'daily_budget' => 20, 'used_last_24h' => 0])
        ->and(json_encode($answer))->not->toContain('client-acme')->not->toContain(SECRET)
        ->and(Http::recorded())->toBeEmpty();
});

it('says whether the app has Transaction Search, from the token alone', function () {
    fakePayPal([]);

    $answer = answer(AccountAccess::class, ['account' => 'acme']);

    expect($answer)->toMatchArray(['accepted' => true, 'app_id' => 'APP-80W284485P519543T', 'can_search_transactions' => true, 'can_read_subscriptions' => true])
        ->and($answer['account'])->toBe(['account' => 'acme', 'name' => 'Acme Example Pty Ltd', 'environment' => 'sandbox'])
        ->and(dataRequests())->toBeEmpty()
        ->and(sentRequests()[0]->url())->toStartWith('https://api-m.sandbox.paypal.com/');
});

it('sends a live account to the live host', function () {
    fakePayPal([]);

    answer(AccountAccess::class, ['account' => 'other']);

    expect(sentRequests()[0]->url())->toStartWith('https://api-m.paypal.com/');
});

it('says the secret is wrong when the token is refused, and sends nothing more', function () {
    fakePayPal([], token: [['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401]);

    $text = refusal(Balances::class, ['account' => 'acme']);

    expect($text)->toContain('client id or secret is wrong')->toContain('invalid_client')->not->toContain(SECRET)
        ->and(sentRequests())->toHaveCount(1);
});

it('names Transaction Search when PayPal refuses permission', function () {
    fakePayPal(['v1/reporting/balances*' => [['name' => 'NOT_AUTHORIZED', 'message' => 'Authorization failed due to insufficient permissions.', 'debug_id' => 'abc123'], 403]]);

    expect(refusal(Balances::class, ['account' => 'acme']))->toContain('Transaction Search')->toContain('abc123');
});

it('reports balances as a reporting snapshot', function () {
    fakePayPal(['v1/reporting/balances*' => 'balances']);

    $answer = answer(Balances::class, ['account' => 'acme']);

    expect($answer['as_of'])->toBe('2026-11-18T01:59:59+00:00')
        ->and($answer['note'])->toContain('not a live balance')
        ->and($answer['balances'][1])->toMatchArray(['currency' => 'USD', 'available' => ['currency_code' => 'USD', 'value' => '689.38']]);
});

it('lists transactions newest first, flat, with payer details but no address or phone', function () {
    fakeSearch(fixtureRows());

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01']);
    $first = $answer['transactions'][0];
    $payment = collect($answer['transactions'])->firstWhere('transaction_id', '2LF12426EC4939423');

    expect($answer['matched'])->toBe(12)
        ->and($first['transaction_id'])->toBe('6US23994V7736042Y')
        ->and($first['reference_id'])->toBe('I-AKYE3A6CY2YH')
        ->and($payment)->toMatchArray([
            'event' => 'payment', 'status' => 'success',
            'amount' => ['currency_code' => 'USD', 'value' => '4.00'],
            'fee' => ['currency_code' => 'USD', 'value' => '-0.44'],
            'net' => ['currency_code' => 'USD', 'value' => '3.56'],
        ])
        ->and($payment['payer'])->toHaveKeys(['email', 'name', 'account_id'])
        ->and(json_encode($answer))->not->toContain('Example Street')->not->toContain('national_number')->not->toContain('4085550100');
});

it('filters by event group and currency here, not at PayPal', function () {
    fakeSearch(fixtureRows());

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01', 'event_code' => 'T00', 'currency' => 'usd']);

    expect($answer['matched'])->toBe(5)
        ->and(dataRequests()[0]->url())->not->toContain('transaction_type')->not->toContain('transaction_currency');
});

it('says how far PayPal reached when its reporting cut the search short', function () {
    fakeSearch(fixtureRows(), refreshedTo: '2026-11-18T00:59:59Z');

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01']);

    expect($answer['coverage']['complete'])->toBeFalse()
        ->and($answer['coverage']['covered']['to'])->toBe('2026-11-18T00:59:59+00:00')
        ->and($answer['coverage']['note'])->toContain('not in this answer yet')
        ->and($answer['matched'])->toBe(10);
});

it('caps the rows it returns but counts every match', function () {
    fakeSearch(fixtureRows());

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01', 'limit' => 3]);

    expect($answer)->toMatchArray(['matched' => 12, 'returned' => 3])
        ->and($answer['transactions'])->toHaveCount(3)
        ->and($answer['note'])->toContain('newest 3');
});

it('refuses a range that is backwards, or a date it cannot read', function (array $args, string $why) {
    expect(refusal(SearchTransactions::class, ['account' => 'acme'] + $args))->toContain($why)
        ->and(Http::recorded())->toBeEmpty();
})->with([
    'no from' => [[], '`from` is required'],
    'backwards' => [['from' => '2026-11-10', 'to' => '2026-11-01'], 'is after'],
    'a US date' => [['from' => '11/01/2026'], 'must be a date'],
    'a bad status' => [['from' => '2026-11-01', 'status' => 'done'], 'must be one of'],
    'a bad event code' => [['from' => '2026-11-01', 'event_code' => 'payment'], 'event code'],
]);

it('finds a payer by email or by part of a name, and only them', function () {
    fakeSearch([
        rowAt('2026-11-10T01:00:00Z', 'AAA1', payer: ['email_address' => 'jo@example.com', 'payer_name' => ['given_name' => 'Jo', 'surname' => 'Bloggs']]),
        rowAt('2026-11-11T01:00:00Z', 'BBB2'),
    ]);

    $byEmail = answer(FindPayer::class, ['account' => 'acme', 'from' => '2026-11-01', 'query' => 'JO@example.com']);
    $byName = answer(FindPayer::class, ['account' => 'acme', 'from' => '2026-11-01', 'query' => 'blogg']);

    expect($byEmail['matched'])->toBe(1)
        ->and($byEmail['transactions'][0]['transaction_id'])->toBe('AAA1')
        ->and($byName['matched'])->toBe(1)
        ->and(json_encode($byEmail))->not->toContain('Secret Street')->not->toContain('400000000');
});

it('totals amounts and fees exactly, by currency and event code', function () {
    fakeSearch(fixtureRows());

    $answer = answer(TransactionTotals::class, ['account' => 'acme', 'from' => '2026-11-01', 'event_code' => 'T00']);
    $usd = collect($answer['by_currency'])->firstWhere('currency', 'USD');
    $subscriptions = collect($answer['by_event_code'])->firstWhere('event_code', 'T0002');

    // four T0000 and two T0002; one T0000 is in AUD
    expect($answer['rows'])->toBe(6)
        ->and($usd)->toMatchArray([
            'count' => 5,
            'gross' => ['currency_code' => 'USD', 'value' => '32.00'],
            'fee' => ['currency_code' => 'USD', 'value' => '-2.60'],
            'net' => ['currency_code' => 'USD', 'value' => '29.40'],
        ])
        ->and($subscriptions['count'])->toBe(2);
});

it('groups a converted payment with its conversion rows, given a date', function () {
    fakeSearch(fixtureRows());

    $answer = answer(GetTransaction::class, ['account' => 'acme', 'transaction_id' => '2LF12426EC4939423', 'date' => '2026-11-18']);

    expect($answer['rows'])->toHaveCount(1)
        ->and(array_column($answer['related'], 'transaction_id'))->toEqualCanonicalizing(['3VF18423S4657572D', '7BG29165SJ329434T'])
        ->and($answer['received_as'])->toBe([['currency_code' => 'AUD', 'value' => '5.01']]);
});

it('pairs the two halves of a conversion the account made itself', function () {
    fakeSearch(fixtureRows());

    $answer = answer(GetTransaction::class, ['account' => 'acme', 'transaction_id' => '149178512D307191A', 'date' => '2026-11-18']);

    expect(array_column($answer['related'], 'transaction_id'))->toBe(['56K338415N2054429']);
});

it('searches back a month at a time for a transaction with no date, and stops when it finds it', function () {
    fakeSearch([rowAt('2026-09-05T03:00:00Z', 'OLD12345')]);

    $answer = answer(GetTransaction::class, ['account' => 'acme', 'transaction_id' => 'OLD12345']);

    expect($answer['rows'][0]['transaction_id'])->toBe('OLD12345')
        ->and($answer['searched'])->toBe('in September 2026 (UTC)')
        // November, October and September by id; then September around it
        ->and(dataRequests())->toHaveCount(4)
        ->and(dataRequests()[0]->url())->toContain('transaction_id=OLD12345');
});

it('says where it looked when a transaction is not found', function () {
    fakeSearch([]);

    expect(refusal(GetTransaction::class, ['account' => 'acme', 'transaction_id' => 'NOPE1234', 'max_requests' => 2]))
        ->toContain('no transaction NOPE1234')->toContain('raise max_requests')
        ->and(dataRequests())->toHaveCount(2);
});

it('reads a subscription and its payments, without the subscriber\'s address', function () {
    $subscription = paypalFixture('subscription');
    $subscription['subscriber']['shipping_address'] = ['address' => ['address_line_1' => '1 Secret Street']];

    fakePayPal([
        'v1/billing/subscriptions/I-ETC83DNY7VWA/transactions*' => 'subscription-transactions',
        'v1/billing/subscriptions/I-ETC83DNY7VWA*' => Http::response($subscription),
    ]);

    $answer = answer(GetSubscription::class, ['account' => 'acme', 'subscription_id' => 'I-ETC83DNY7VWA']);

    expect($answer['subscription']['status'])->toBe('ACTIVE')
        ->and($answer['subscription']['billing_info']['next_billing_time'])->toBe('2026-11-19T10:00:00Z')
        ->and($answer['subscription'])->not->toHaveKey('links')
        ->and(json_encode($answer))->not->toContain('Secret Street')
        ->and($answer['payments'][0])->toHaveKeys(['transaction_id', 'gross', 'fee'])
        // PayPal writes it positive here; shown negative, as transaction search shows it
        ->and($answer['payments'][0]['fee']['value'])->toStartWith('-');
});

it('says a subscription is not on this account', function () {
    fakePayPal(['v1/billing/subscriptions/*' => ['not-found', 404]]);

    expect(refusal(GetSubscription::class, ['account' => 'acme', 'subscription_id' => 'I-MISSING1']))->toContain('has no subscription I-MISSING1');
});

it('refuses a call the budget cannot hold, before sending anything', function () {
    fakeSearch(fixtureRows());

    // other's budget is 20: 20 searches and the token allowance do not fit
    $text = refusal(SearchTransactions::class, ['account' => 'other', 'from' => '2025-01-01', 'max_requests' => 20]);

    expect($text)->toContain('Refused without asking PayPal')->toContain('0 of the 20')
        ->and(Http::recorded())->toBeEmpty();
});

it('refuses an account it does not know, naming the ones it does', function () {
    expect(refusal(Balances::class, ['account' => 'nope']))->toContain('no PayPal account called "nope"')->toContain('acme, other');
});

it('refuses an account with no app, before sending anything', function () {
    useAccounts(['acme' => ['name' => 'Acme', 'environment' => 'sandbox', 'client_id' => null, 'secret' => null]]);

    expect(refusal(Balances::class, ['account' => 'acme']))->toContain('has no REST app configured')
        ->and(Http::recorded())->toBeEmpty();
});
