<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\Support\PayPalAccount;
use App\Support\Present;
use Hampel\PayPal\Api\Entity\Money;
use Hampel\PayPal\Api\Entity\Transaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Total an account's transactions over a date range up to three years: the count, gross amount, fees and net, by currency
    and by event code. For "what did we pay in fees last quarter" and "how much came in in USD". The sums are exact.

    COST: as search-transactions over the same range - about one request a calendar month, nothing for a month read in the
    last 30 days - but the answer is totals, not rows.

    Fees are negative, as PayPal writes them: `fee` is what PayPal kept. A currency conversion (T02) is two rows, money out
    of one currency and into another, so a currency's gross includes both sides; filter with event_code T00 for payments
    alone. Read `coverage` before quoting a total for a period that ends today: PayPal's reporting lags by up to three hours.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class TransactionTotals extends HistoryTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + $this->filterArguments($schema);
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $filter = $this->rowFilter($request);

        [$result, $from, $to] = $this->history($request, $gateway, $account, nonBalanceAffecting: false);

        /** @var array<string, array{count: int, gross: ?Money, fee: ?Money}> $byCurrency */
        $byCurrency = [];
        /** @var array<string, array{count: int, gross: ?Money, fee: ?Money}> $byCode */
        $byCode = [];
        $count = 0;

        foreach ($result->transactions as $row) {
            if (! $filter($row) || $row->amount === null) {
                continue;
            }

            $count++;
            $currency = $row->amount->currency;

            $byCurrency[$currency] = self::add($byCurrency[$currency] ?? null, $row);
            $byCode[$currency.' '.($row->eventCode ?? '?')] = self::add($byCode[$currency.' '.($row->eventCode ?? '?')] ?? null, $row);
        }

        ksort($byCurrency);
        ksort($byCode);

        return [
            'coverage' => Present::coverage($result, $from, $to),
            'rows' => $count,
            'by_currency' => array_map(static fn (string $currency, array $sum): array => ['currency' => $currency, ...self::show($sum)], array_keys($byCurrency), $byCurrency),
            'by_event_code' => array_map(static function (string $key, array $sum): array {
                [$currency, $code] = explode(' ', $key, 2);

                return ['currency' => $currency, 'event_code' => $code, 'event' => Present::eventGroup($code), ...self::show($sum)];
            }, array_keys($byCode), $byCode),
        ];
    }

    /**
     * @param  array{count: int, gross: ?Money, fee: ?Money}|null  $sum
     * @return array{count: int, gross: ?Money, fee: ?Money}
     */
    private static function add(?array $sum, Transaction $row): array
    {
        $sum ??= ['count' => 0, 'gross' => null, 'fee' => null];
        $sum['count']++;

        if ($row->amount !== null) {
            $sum['gross'] = $sum['gross'] === null ? $row->amount : $sum['gross']->plus($row->amount);
        }

        // a fee in another currency than its row has not been seen; it is left out rather than mixed in
        if ($row->fee !== null && $row->amount !== null && $row->fee->currency === $row->amount->currency) {
            $sum['fee'] = $sum['fee'] === null ? $row->fee : $sum['fee']->plus($row->fee);
        }

        return $sum;
    }

    /**
     * @param  array{count: int, gross: ?Money, fee: ?Money}  $sum
     * @return array<string, mixed>
     */
    private static function show(array $sum): array
    {
        $net = $sum['gross'] !== null && $sum['fee'] !== null ? $sum['gross']->plus($sum['fee']) : $sum['gross'];

        return [
            'count' => $sum['count'],
            'gross' => Present::money($sum['gross']),
            'fee' => Present::money($sum['fee']),
            'net' => Present::money($net),
        ];
    }
}
