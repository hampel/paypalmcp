<?php

use App\Mcp\Servers\PayPalServer;
use App\Mcp\Tools\AccountAccess;
use App\Mcp\Tools\Balances;
use App\Mcp\Tools\FindPayer;
use App\Mcp\Tools\GetSubscription;
use App\Mcp\Tools\GetTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\TransactionTotals;
use Laravel\Mcp\Facades\Mcp;

const TOOLS = [
    ListAccounts::class, AccountAccess::class, SearchTransactions::class, GetTransaction::class, FindPayer::class,
    TransactionTotals::class, Balances::class, GetSubscription::class,
];

it('registers every tool', function () {
    PayPalServer::tools()->assertRegistered(TOOLS);
});

it('is registered as a local server and as nothing else', function () {
    expect(Mcp::getLocalServer('paypal'))->not->toBeNull()
        ->and(array_keys(Mcp::servers()))->toBe(['paypal']);
});

it('marks every tool read-only, and never destructive', function (string $class) {
    expect(app($class)->toArray()['annotations'])
        ->toMatchArray(['readOnlyHint' => true])
        ->not->toHaveKey('destructiveHint');
})->with(TOOLS);

it('gives every tool a description that is more than its name', function (string $class) {
    expect(strlen(app($class)->description()))->toBeGreaterThan(150);
})->with(TOOLS);

it('says in every tool that costs requests what it costs', function (string $class) {
    expect(app($class)->description())->toMatch('/request/i');
})->with(array_values(array_diff(TOOLS, [ListAccounts::class])));

it('has no tool that could send an arbitrary request', function () {
    foreach (glob(dirname(__DIR__, 2).'/app/Mcp/Tools/*.php') as $file) {
        expect(file_get_contents($file))->not->toContain('->connection()')->not->toContain('->endpoint(');
    }
});
