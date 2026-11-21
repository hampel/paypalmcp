<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AccountAccess;
use App\Mcp\Tools\Balances;
use App\Mcp\Tools\FindPayer;
use App\Mcp\Tools\GetSubscription;
use App\Mcp\Tools\GetTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\TransactionTotals;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Read-only questions about PayPal business accounts, over stdio.
 *
 * Every tool here reads, and THE CODE IS THE ONLY GUARD. A REST app's token carries every feature
 * the app has - a sandbox app's carried thirty scopes, refunds and payouts among them (measured by
 * paypal-api, 2026-09-15) - so nothing on PayPal's side stops it moving money. paypal-api wraps no
 * operation that writes, and this server calls nothing else. Never add a tool that sends a raw
 * request: it would route round both.
 */
#[Name('PayPal account lookups')]
#[Instructions(<<<'TEXT'
    Read-only questions about PayPal business accounts: transactions (payments received and sent, fees, refunds,
    currency conversions), who paid what and when, totals over a period, balances, and subscriptions. Nothing here
    changes an account or moves money.

    Start with list-accounts; every other tool takes an account key from it. Each account is a separate PayPal business
    account; a payment is in the account that received it.

    PAYPAL'S REPORTING LAGS. A payment takes up to three hours to appear, and PayPal silently stops a search at its last
    refresh. Every answer built from transaction history carries `coverage`, saying exactly how far it reached - read it
    before saying a payment does not exist. Balances are the same reporting snapshot, not a live balance.

    PAYPAL CANNOT SEARCH BY PERSON, and searches one month at a time. Finding a payer or totalling a period reads every
    row in the range: about one request a calendar month, nothing for a month read in the last 30 days. So:

    - Give get-transaction a `date` whenever you know roughly when.
    - Ask for the range you need, not three years by default.
    - Every answer's `budget` says what this server has left for the account today; if a call is refused for budget,
      stop and say what you could not look up. Do not retry it.

    Payers are named by their PayPal login email and name, which may differ from what they gave the seller;
    `custom_field` is the seller's own reference where one was set. Addresses and phone numbers are never shown.

    When a tool returns an error, read it: it says whether the account is unknown, the app is refused or lacks Transaction
    Search, the record does not exist, or the budget is spent - different problems with different fixes.
    TEXT)]
class PayPalServer extends Server
{
    protected array $tools = [
        ListAccounts::class,
        AccountAccess::class,
        SearchTransactions::class,
        GetTransaction::class,
        FindPayer::class,
        TransactionTotals::class,
        Balances::class,
        GetSubscription::class,
    ];

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
