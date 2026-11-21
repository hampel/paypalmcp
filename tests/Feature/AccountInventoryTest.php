<?php

use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use Hampel\PayPal\Api\Environment;

function inventoryFrom(string $toml): AccountInventory
{
    $path = sys_get_temp_dir().'/paypalmcp-accounts-'.bin2hex(random_bytes(4)).'.toml';
    file_put_contents($path, $toml);
    config(['paypalmcp.accounts_path' => $path]);

    return new AccountInventory;
}

it('reads an account, its environment, its default budget and its app', function () {
    $account = inventoryFrom("[acme]\nname = \"Acme\"\nenvironment = \"live\"\nclient_id = \"abc\"\nsecret = \"".SECRET."\"\n")->get('acme');

    expect($account->environment)->toBe(Environment::Live)
        ->and($account->dailyBudget)->toBe(500)
        ->and($account->clientId)->toBe('abc')
        ->and($account->secret())->toBe(SECRET);
});

it('reads an account that has no app yet', function () {
    expect(inventoryFrom("[acme]\nname = \"Acme\"\nenvironment = \"sandbox\"\n")->get('acme')->hasCredentials())->toBeFalse();
});

it('takes an account\'s own budget over the default', function () {
    expect(inventoryFrom("[acme]\nname = \"Acme\"\nenvironment = \"sandbox\"\ndaily_budget = 50\n")->get('acme')->dailyBudget)->toBe(50);
});

it('refuses what it cannot use, naming why', function (string $toml, string $why) {
    expect(fn () => inventoryFrom($toml)->all())->toThrow(AccountInventoryException::class, $why);
})->with([
    'a key with capitals' => ["[Acme]\nname = \"A\"\nenvironment = \"live\"\n", 'lowercase letters and digits'],
    'an unknown setting' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\npassword = \"x\"\n", '"password" is not a setting'],
    'a secret alone' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\nsecret = \"x\"\n", 'has a secret and no client_id'],
    'a client id alone' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\nclient_id = \"x\"\n", 'has a client_id and no secret'],
    'an empty secret' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\nclient_id = \"x\"\nsecret = \"\"\n", 'secret must be text'],
    'no environment' => ["[acme]\nname = \"A\"\n", 'environment must be "live" or "sandbox"'],
    'a mistyped environment' => ["[acme]\nname = \"A\"\nenvironment = \"production\"\n", 'environment must be "live" or "sandbox"'],
    'no name' => ["[acme]\nenvironment = \"live\"\n", 'name must be'],
    'a zero budget' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\ndaily_budget = 0\n", 'daily_budget must be a positive integer'],
    'one app twice' => ["[acme]\nname = \"A\"\nenvironment = \"live\"\nclient_id = \"x\"\nsecret = \"y\"\n[other]\nname = \"B\"\nenvironment = \"live\"\nclient_id = \"x\"\nsecret = \"z\"\n", 'use the same REST app'],
    'nothing' => ['', 'describes no account'],
    'a setting outside a table' => ["name = \"A\"\n", 'is not an account'],
]);

it('allows one app id in both environments, since they are different apps', function () {
    $accounts = inventoryFrom("[a]\nname = \"A\"\nenvironment = \"live\"\nclient_id = \"x\"\nsecret = \"y\"\n[b]\nname = \"B\"\nenvironment = \"sandbox\"\nclient_id = \"x\"\nsecret = \"y\"\n")->all();

    expect(array_keys($accounts))->toBe(['a', 'b'])
        ->and($accounts['a']->identity())->not->toBe($accounts['b']->identity());
});

it('never quotes the text a parse stopped on', function () {
    try {
        inventoryFrom("[acme]\nname = \"A\"\nenvironment = \"live\"\nclient_id = \"u\"\nsecret = \"secret-looking-value\n")->all();
    } catch (AccountInventoryException $e) {
        expect($e->getMessage())->toContain('not valid TOML')->not->toContain('secret-looking-value');

        return;
    }

    $this->fail('no exception');
});

it('says where it looked when there is no inventory', function () {
    config(['paypalmcp.accounts_path' => '/nonexistent/accounts.toml']);

    expect(fn () => (new AccountInventory)->all())->toThrow(AccountInventoryException::class, '/nonexistent/accounts.toml');
});

it('reads the file again when it changes, so a new account needs no reconnect', function () {
    $inventory = inventoryFrom("[acme]\nname = \"Acme\"\nenvironment = \"live\"\n");
    expect(array_keys($inventory->all()))->toBe(['acme']);

    file_put_contents($inventory->path(), "[acme]\nname = \"Acme\"\nenvironment = \"live\"\n\n[other]\nname = \"Other\"\nenvironment = \"sandbox\"\n");

    expect(array_keys($inventory->all()))->toBe(['acme', 'other']);
});

it('ships an example that reads', function () {
    config(['paypalmcp.accounts_path' => dirname(__DIR__, 2).'/accounts.toml.example']);

    expect(array_keys((new AccountInventory)->all()))->toBe(['acme']);
});

it('keeps the secret out of a dump of the account', function () {
    $account = inventoryFrom("[acme]\nname = \"Acme\"\nenvironment = \"live\"\nclient_id = \"abc\"\nsecret = \"".SECRET."\"\n")->get('acme');

    expect(json_encode($account->describe()))->not->toContain(SECRET)->not->toContain('abc');
});
