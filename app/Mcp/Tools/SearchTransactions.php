<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\Support\PayPalAccount;
use App\Support\Present;
use Hampel\PayPal\Api\Entity\Transaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List an account's transactions in a date range of any length up to three years, newest first, optionally by event
    code, currency or status.

    COST: about one request a calendar month in the range, plus a token if none is cached. A month that ended more than
    three days ago is kept for 30 days once read, so asking about it again costs nothing. `max_requests` caps it; when it
    runs out, `coverage` says how far back the answer reached.

    READ `coverage` BEFORE SAYING SOMETHING IS ABSENT. PayPal's reporting lags - up to three hours, measured at about two -
    and it silently stops a search at its last refresh, so `coverage.covered.to` can be earlier than you asked.

    Each row: transaction_id, event_code with `event` (its group: payment, fee, currency conversion, reversal...), status,
    initiated, amount, fee (negative, in the payment's currency, on the payment's own row), net, reference_id, invoice_id,
    custom_field (the seller's own reference), subject, note, payer (name, email, PayPal account id, country) and items.
    A payment in a currency the account converts comes with two T0200 rows naming it as their reference_id; get-transaction
    groups them. A transaction id is not unique: one can appear once as balance-affecting and once not.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class SearchTransactions extends HistoryTool
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + $this->filterArguments($schema) + [
            'include_non_balance_affecting' => $schema->boolean()->description('Also list rows that do not change the balance, such as pending or denied payments and authorisations. Default false, PayPal\'s own default.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(self::DEFAULT_LIMIT)
                ->description(sprintf('The most rows to return, newest first, 1 to %d. Default %d. `matched` still counts them all.', self::MAX_LIMIT, self::DEFAULT_LIMIT)),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $filter = $this->rowFilter($request);
        $limit = $this->whole($request->get('limit'), 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);
        $nonBalance = $this->flag($request->get('include_non_balance_affecting'), 'include_non_balance_affecting');

        [$result, $from, $to] = $this->history($request, $gateway, $account, $nonBalance);

        $matched = array_values(array_filter($result->transactions, static fn (Transaction $row): bool => $filter($row)));

        return [
            'coverage' => Present::coverage($result, $from, $to),
            'matched' => count($matched),
            'returned' => min($limit, count($matched)),
            'note' => count($matched) > $limit
                ? sprintf('%d rows matched and the newest %d are here. Narrow the range or the filter for the rest.', count($matched), $limit)
                : 'Every row that matched in the covered range is here.',
            'dates' => Present::dateNote(),
            'transactions' => Present::transactions(array_slice($matched, 0, $limit)),
        ];
    }
}
