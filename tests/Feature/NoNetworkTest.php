<?php

use App\Mcp\Servers\PayPalServer;
use App\Mcp\Tools\Balances;

/*
 * Fakes nothing. If the client ever stopped sending through Laravel's HTTP stack - the transport
 * bound under the wrong key, or a provider dropped from config/app.php - Http::fake() would stop
 * seeing its requests and every other test would quietly call PayPal.
 */
it('cannot reach PayPal without a fake', function () {
    PayPalServer::tool(Balances::class, ['account' => 'acme'])->assertHasErrors();

    expect(Http::recorded())->toBeEmpty();
});
