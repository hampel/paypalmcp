<?php

use App\Mcp\Servers\PayPalServer;
use App\Mcp\Tools\Balances;
use App\PayPal\Gateway;
use App\PayPal\MeteredClient;
use App\PayPal\UnmeteredRequestException;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;

it('is the transport the PayPal package sends through', function () {
    expect(app(PayPalServiceProvider::HTTP_CLIENT))->toBe(app(MeteredClient::class));
});

it('refuses a request made outside the gateway, before it is sent', function () {
    fakePayPal(['v1/reporting/balances*' => 'balances']);

    config(['paypal.connections.stray' => ['client_id' => 'x', 'secret' => 'y', 'environment' => 'sandbox']]);

    expect(fn () => app(PayPalManager::class)->client('stray')->transactions()->balances())
        ->toThrow(UnmeteredRequestException::class)
        ->and(Http::recorded())->toBeEmpty();
});

it('counts the token request against the budget', function () {
    fakePayPal(['v1/reporting/balances*' => 'balances']);

    $first = answer(Balances::class, ['account' => 'acme']);
    // the token is cached in the state directory's store
    $second = answer(Balances::class, ['account' => 'acme']);

    expect($first['budget']['requests_this_call'])->toBe(2)
        ->and($second['budget'])->toMatchArray(['requests_this_call' => 1, 'used_last_24h' => 3]);
});

it('stops a call at its allowance, and says so', function () {
    // a 401 with a fresh token each time: the package retries once, and then the next call would too
    Http::fake([
        '*/v1/oauth2/token' => Http::response(paypalFixture('token')),
        '*/v1/reporting/balances*' => Http::response(['name' => 'AUTHENTICATION_FAILURE', 'message' => 'Authentication failed'], 401),
    ]);

    PayPalServer::tool(Balances::class, ['account' => 'acme'])
        ->assertHasErrors(['issued the REST app this server uses for "acme" a token and then refused it']);

    expect(count(sentRequests()))->toBeLessThanOrEqual(1 + Gateway::LOGIN_ALLOWANCE);
});
