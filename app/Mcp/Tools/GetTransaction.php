<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\PayPal\HistoryResult;
use App\PayPal\PayPalCallException;
use App\PayPal\TransactionHistory;
use App\Support\PayPalAccount;
use App\Support\Present;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Filter\TransactionFilter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one transaction by its id, with the rows that belong to it: the currency conversion rows of a payment the
    account converted, or the other half of a conversion. Rows are as search-transactions describes them.

    PAYPAL CANNOT LOOK A TRANSACTION UP WITHOUT A DATE RANGE. Given `date` - roughly when it happened, within three days -
    this costs one or two requests, or none for a month read in the last 30 days. Without one it searches back a month at
    a time, newest first, one request a month up to `max_requests` (default 13, a year): give a date whenever you have one.

    `rows` is usually one; a transaction id can appear twice, once balance-affecting and once not. The fee is on the
    payment's own row, negative. `related` are rows within a day naming this id as their reference, and `received_as`
    the amount a conversion put into the account's own currency. A refund or reversal made later is a row of its own,
    with this id as its reference_id: search-transactions with event_code T11 over the later range finds it.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetTransaction extends PayPalTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'transaction_id' => $schema->string()->description('The PayPal transaction id, such as 5TY05013RG002845M.')->required(),
            'date' => $schema->string()->description('Roughly when it happened, YYYY-MM-DD, within three days either side. Makes the lookup one or two requests rather than one a month.'),
            'max_requests' => $schema->integer()->min(1)->max(HistoryTool::MAX_REQUESTS)->default(HistoryTool::DEFAULT_REQUESTS)
                ->description('Without a date: the most months to search back, one request each. Default 13.'),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $id = strtoupper($this->text($request->get('transaction_id'), 'transaction_id') ?? '');

        if (preg_match('/^[A-Z0-9]{6,30}$/', $id) !== 1) {
            throw new PayPalCallException('`transaction_id` must be a PayPal transaction id: letters and digits, such as 5TY05013RG002845M.');
        }

        $date = $this->instant($request->get('date'), 'date', endOfDay: false);
        $history = app(TransactionHistory::class);

        if ($date !== null) {
            $from = $date->modify('-3 days');
            $to = $date->modify('+4 days')->modify('-1 second');
            $window = $this->read($gateway, $account, $history, $from, $to);
            $rows = array_values(array_filter($window->transactions, static fn (Transaction $row): bool => $row->id === $id));
            $searched = 'between '.Present::time($window->coveredFrom).' and '.Present::time($window->coveredTo);
        } else {
            $max = $this->whole($request->get('max_requests'), 'max_requests', 1, HistoryTool::MAX_REQUESTS, HistoryTool::DEFAULT_REQUESTS);
            [$rows, $searched] = $this->seek($gateway, $account, $history, $id, $max);
        }

        if ($rows === []) {
            throw new PayPalCallException(sprintf(
                '"%s" has no transaction %s %s. PayPal\'s reporting lags by up to three hours, so a payment made in the last few is not there yet; '
                .'a transaction belongs to the account that received or sent it, so it may be in another in list-accounts.',
                $account->key,
                $id,
                $searched,
            ));
        }

        $at = $rows[0]->initiatedAt ?? $date ?? $history->now();
        $around = $this->read($gateway, $account, $history, $at->modify('-1 day'), $at->modify('+1 day'));
        $reference = $rows[0]->referenceId;

        $related = array_values(array_filter($around->transactions, static fn (Transaction $row): bool => $row->id !== $id
            && ($row->referenceId === $id || ($reference !== null && $row->id === $reference))));

        $receivedAs = [];

        foreach ($related as $row) {
            if ($row->isCurrencyConversion() && $row->amount !== null && ! $row->amount->isNegative() && $row->referenceId === $id) {
                $receivedAs[] = Present::money($row->amount);
            }
        }

        return [
            'searched' => $searched,
            'rows' => Present::transactions($rows),
            'related' => Present::transactions($related),
            'received_as' => $receivedAs,
            'dates' => Present::dateNote(),
        ];
    }

    /** @throws PayPalCallException */
    private function read(Gateway $gateway, PayPalAccount $account, TransactionHistory $history, \DateTimeImmutable $from, \DateTimeImmutable $to): HistoryResult
    {
        // two months at most, each a page unless the month is busy
        $max = 4;

        return $gateway->call($account, $max, static fn (Client $paypal) => $history->walk($paypal, $account, $from, $to, true, $max));
    }

    /**
     * Search back a month at a time for one id, newest first.
     *
     * @return array{list<Transaction>, string}
     *
     * @throws PayPalCallException
     */
    private function seek(Gateway $gateway, PayPalAccount $account, TransactionHistory $history, string $id, int $max): array
    {
        $filter = TransactionFilter::make()->withTransactionId($id)->includingNonBalanceAffecting();
        $months = $history->months($history->floor(), $history->now());

        return $gateway->call($account, $max, static function (Client $paypal) use ($filter, $months, $max): array {
            $oldest = null;

            foreach (array_slice($months, 0, $max) as [$start, $end]) {
                $page = $paypal->transactions()->search($start, $end, $filter);
                $oldest = $start;

                if (! $page->isEmpty()) {
                    return [$page->transactions, 'in '.$start->format('F Y').' (UTC)'];
                }
            }

            return [[], 'searching back to '.Present::time($oldest).($max < count($months) ? ' - raise max_requests, or give a date, to search further' : ', the start of PayPal\'s history')];
        });
    }
}
