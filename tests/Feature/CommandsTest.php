<?php

use App\Commands\Validate;
use App\Support\AccountInventory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Exception\CommandNotFoundException;

function fakeEveryPayPalProbe(array $overrides = [], mixed $token = 'token'): void
{
    fakePayPal(array_merge([
        'v1/reporting/transactions*' => Http::response(searchPage([])),
        'v1/reporting/balances*' => 'balances',
        'v1/billing/subscriptions/*' => [['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.'], 404],
    ], $overrides), $token);
}

it('validates offline without asking PayPal', function () {
    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Account: acme')->toContain('client id and secret set')->toContain('not reached - --offline')
        ->and(Http::recorded())->toBeEmpty();
});

it('fails an account with no app', function () {
    useAccounts(['acme' => ['name' => 'Acme', 'environment' => 'sandbox', 'client_id' => null, 'secret' => null]]);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('add client_id and secret under [acme]');
});

it('fails an inventory others can read, since it holds the secrets', function () {
    $path = sys_get_temp_dir().'/paypalmcp-accounts-'.bin2hex(random_bytes(4)).'.toml';
    file_put_contents($path, "[acme]\nname = \"Acme\"\nenvironment = \"sandbox\"\nclient_id = \"u\"\nsecret = \"p\"\n");
    chmod($path, 0644);
    config(['paypalmcp.accounts_path' => $path]);
    app()->forgetInstance(AccountInventory::class);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    chmod($path, 0600);
    [$exitAfter, $outputAfter] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toMatch('/\[fail\] Inventory mode\s+0644/')
        ->and($exitAfter)->toBe(0)->and($outputAfter)->toMatch('/\[ ok \] Inventory mode\s+0600/');
})->skipOnWindows();

it('asks PayPal for everything the tools need, and counts it against the budget', function () {
    fakeEveryPayPalProbe();
    useAccounts(['acme' => ['name' => 'Acme', 'environment' => 'sandbox']]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/\[ ok \] Transaction Search/')->toContain('Searches transactions')->toMatch('/Reads balances\s+AUD, USD/')
        ->and($output)->toMatch('/\[ ok \] Reads subscriptions/')
        ->and(dataRequests())->toHaveCount(3)
        ->and($output)->toContain('4 of 500 now used');
});

it('fails an app without Transaction Search', function () {
    // Http::fake() answers from the first matching route, so the token is replaced, not added
    fakeEveryPayPalProbe(token: Http::response(['access_token' => 'x', 'expires_in' => 32400, 'scope' => 'openid', 'app_id' => 'APP-1']));
    useAccounts(['acme' => ['name' => 'Acme', 'environment' => 'sandbox']]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)->and($output)->toMatch('/\[fail\] Transaction Search/');
});

it('stops probing an account whose secret is refused', function () {
    fakePayPal([], token: [['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401]);
    useAccounts(['acme' => ['name' => 'Acme', 'environment' => 'sandbox']]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)
        ->and($output)->toContain('client id or secret is wrong')
        ->and(sentRequests())->toHaveCount(1);
});

it('fails a state directory that follows the working directory', function () {
    config(['paypalmcp.state_path_set' => false]);
    app()->instance('paypalmcp.storage.follows_cwd', true);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('set PAYPALMCP_STATE_PATH');
});

it('judges a file of secrets by its mode', function (int $mode, int $owner, bool $ok) {
    expect(Validate::fileModeVerdict($mode, $owner, 'paypalmcp')[0])->toBe($ok);
})->with([
    'owner only' => [0600, 1000, true],
    'root, group read' => [0640, 0, true],
    'world readable' => [0644, 1000, false],
    'group read, not root' => [0640, 1000, false],
]);

it('shows the configuration without a credential', function () {
    [$exit, $output] = runCommand('app:config');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Account: acme')->toContain('app set')->toContain('live')
        ->and($output)->not->toContain(SECRET)->not->toContain('client-acme');
});

it('fails a mistyped command rather than printing the list', function () {
    expect(fn () => runCommand('app:validte'))->toThrow(CommandNotFoundException::class);
});
