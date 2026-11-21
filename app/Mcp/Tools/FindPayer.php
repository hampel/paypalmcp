<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\PayPal\PayPalCallException;
use App\Support\PayPalAccount;
use App\Support\Present;
use Hampel\PayPal\Api\Entity\Transaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Find what one person paid, or was paid, by their email address, name or PayPal account id, in a date range up to three
    years. Newest first.

    PAYPAL CANNOT SEARCH BY PERSON, so this reads every transaction in the range and matches here: the cost is that of
    search-transactions over the same range, about one request a calendar month, nothing for a month read in the last
    30 days. Ask for the range you need - a year is about 13 requests, three years about 37 with max_requests raised.

    `query` matches the payer's email or PayPal account id exactly, or any part of their name, ignoring case. THE EMAIL IS
    THEIR PAYPAL LOGIN, which is often not the address they gave the seller: when nothing matches, try their name, or
    search by the seller's own reference in `custom_field` with search-transactions. Read `coverage` before saying they
    paid nothing: PayPal's reporting lags by up to three hours.

    Rows are as search-transactions describes them.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class FindPayer extends HistoryTool
{
    public const MAX_ROWS = 200;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'query' => $schema->string()->description('An email address or PayPal account id, matched whole, or part of a name. At least three characters.')->required(),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $query = $this->text($request->get('query'), 'query');

        if ($query === null || mb_strlen($query) < 3) {
            throw new PayPalCallException('`query` is required: an email address, a PayPal account id, or at least three characters of a name.');
        }

        [$result, $from, $to] = $this->history($request, $gateway, $account, nonBalanceAffecting: false);

        $matched = array_values(array_filter($result->transactions, static fn (Transaction $row): bool => self::matches($row, $query)));

        return [
            'query' => $query,
            'coverage' => Present::coverage($result, $from, $to),
            'matched' => count($matched),
            'note' => count($matched) > self::MAX_ROWS
                ? sprintf('%d rows matched and the newest %d are here. Narrow the range for the rest.', count($matched), self::MAX_ROWS)
                : ($matched === [] ? 'No payer in the covered range matched. The email is the payer\'s PayPal login, which may differ from the one they gave the seller.' : 'Every matching row in the covered range is here.'),
            'dates' => Present::dateNote(),
            'transactions' => Present::transactions(array_slice($matched, 0, self::MAX_ROWS)),
        ];
    }

    public static function matches(Transaction $row, string $query): bool
    {
        $payer = $row->payer;

        if ($payer === null) {
            return false;
        }

        if (str_contains($query, '@')) {
            return $payer->email !== null && strcasecmp($payer->email, $query) === 0;
        }

        if ($payer->accountId !== null && strcasecmp($payer->accountId, $query) === 0) {
            return true;
        }

        foreach ([$payer->name(), $payer->alternateFullName] as $name) {
            if ($name !== null && mb_stripos($name, $query) !== false) {
                return true;
            }
        }

        return false;
    }
}
