<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\Support\PayPalAccount;
use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Ask PayPal whether this server's REST app for an account is accepted, and what it may do. One token request, or none
    when a token is cached - tokens last about nine hours.

    `can_search_transactions` says whether the app has Transaction Search, which every transaction and balance tool needs;
    `can_read_subscriptions` whether it has Subscriptions, which get-subscription needs.
    A change to the app's features in PayPal's dashboard can take until the cached token expires to show. `scopes` is every
    feature the app was granted: the token can do more than this server ever asks of it, which is why the server has no
    tool that writes.

    Use it when an account is new to you, or when another tool's error suggests the app is the problem. It does not say
    which business the account belongs to; balances and transactions do.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class AccountAccess extends PayPalTool
{
    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        // no request but the token: verify() asks for one, or reads the cached one
        $grant = $gateway->call($account, 0, static fn (Client $paypal): AccessGrant => $paypal->verify());

        return [
            'accepted' => true,
            'app_id' => $grant->appId,
            'can_search_transactions' => $grant->canSearchTransactions(),
            'can_read_subscriptions' => $grant->canReadSubscriptions(),
            'token_expires' => $grant->expiresAt->format(DATE_ATOM),
            'scopes' => $grant->scopes(),
            'note' => match (true) {
                $grant->canSearchTransactions() && $grant->canReadSubscriptions() => 'The app has Transaction Search and Subscriptions: every tool here can be used.',
                $grant->canSearchTransactions() => 'The app lacks Subscriptions, so get-subscription will be refused. Tick it on the app in PayPal\'s developer dashboard.',
                default => 'The app lacks Transaction Search, so transaction and balance tools will be refused. Tick it on the app in PayPal\'s developer dashboard.',
            },
        ];
    }
}
