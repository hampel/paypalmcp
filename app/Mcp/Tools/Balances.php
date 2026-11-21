<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\Support\PayPalAccount;
use App\Support\Present;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Entity\AccountBalances;
use Hampel\PayPal\Api\Entity\Balance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show what the account holds in each currency: total, available and withheld. One request, plus a token if none is cached.

    THESE ARE PAYPAL'S REPORTING FIGURES, NOT A LIVE BALANCE. They are as of `as_of`, which for "now" is PayPal's last
    reporting refresh - measured at one to two hours behind - so a payment made since is not in them. Say so when you
    quote one.

    `as_of` asks for the balance at an earlier moment in the last three years: YYYY-MM-DD for the end of that day, or a
    date and time with its offset.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class Balances extends PayPalTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'as_of' => $schema->string()->description('The balance at this moment rather than now: YYYY-MM-DD for the end of that day in '.config('paypalmcp.timezone').', or a date and time with its offset.'),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $asOf = $this->instant($request->get('as_of'), 'as_of', endOfDay: true);

        $balances = $gateway->call($account, 1, static fn (Client $paypal): AccountBalances => $paypal->transactions()->balances($asOf));

        return [
            'as_of' => Present::time($balances->asOf),
            'reporting_refreshed' => Present::time($balances->lastRefreshedAt),
            'note' => 'Reporting figures as of `as_of`, not a live balance: anything after it is not included.',
            'balances' => array_map(static fn (Balance $balance): array => array_filter([
                'currency' => $balance->currency,
                'primary' => $balance->primary,
                'total' => Present::money($balance->total),
                'available' => Present::money($balance->available),
                'withheld' => Present::money($balance->withheld),
            ], static fn (mixed $value): bool => $value !== null), $balances->balances),
        ];
    }
}
