<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\PayPal\HistoryResult;
use App\PayPal\PayPalCallException;
use App\PayPal\TransactionHistory;
use App\Support\PayPalAccount;
use Closure;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Enum\TransactionStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;

/**
 * A tool that reads an account's history over a range and filters it here.
 *
 * PayPal's search filters by event code, currency and status as well, but each filter is a
 * different search, which the month cache could not share. Reading every row once and filtering
 * here makes every question about the same months answer from the same cache.
 */
abstract class HistoryTool extends PayPalTool
{
    /** A year and a month: one search a month when no month needs a second page. */
    public const DEFAULT_REQUESTS = 13;

    /** Three years and a margin for months of more than 500 rows. */
    public const MAX_REQUESTS = 50;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + $this->rangeArguments($schema) + [
            'max_requests' => $schema->integer()->min(1)->max(self::MAX_REQUESTS)->default(self::DEFAULT_REQUESTS)
                ->description(sprintf(
                    'The most searches this call may send, 1 to %d, default %d. PayPal searches one calendar month (UTC) at a time, '
                    .'so a range costs about a request a month, a busy month one per 500 rows, and a month already read in the last '
                    .'%d days nothing. When it runs out, the answer says how far it reached, newest first.',
                    self::MAX_REQUESTS,
                    self::DEFAULT_REQUESTS,
                    TransactionHistory::CACHE_DAYS,
                )),
        ];
    }

    /**
     * Read the range the request names.
     *
     * @return array{HistoryResult, \DateTimeImmutable, \DateTimeImmutable}
     *
     * @throws PayPalCallException
     */
    protected function history(Request $request, Gateway $gateway, PayPalAccount $account, bool $nonBalanceAffecting): array
    {
        [$from, $to] = $this->range($request);
        $max = $this->whole($request->get('max_requests'), 'max_requests', 1, self::MAX_REQUESTS, self::DEFAULT_REQUESTS);

        return [$this->read($gateway, $account, $from, $to, $nonBalanceAffecting, $max), $from, $to];
    }

    /** @throws PayPalCallException */
    protected function read(Gateway $gateway, PayPalAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $nonBalanceAffecting, int $max): HistoryResult
    {
        $history = app(TransactionHistory::class);

        return $gateway->call(
            $account,
            $max,
            static fn (Client $paypal): HistoryResult => $history->walk($paypal, $account, $from, $to, $nonBalanceAffecting, $max),
        );
    }

    /**
     * The event code, currency and status filters, read from the request.
     *
     * @return Closure(Transaction): bool
     *
     * @throws PayPalCallException
     */
    protected function rowFilter(Request $request): Closure
    {
        $code = $this->text($request->get('event_code'), 'event_code');
        $code = $code === null ? null : strtoupper($code);

        if ($code !== null && preg_match('/^T\d{2}(\d{2})?$/', $code) !== 1) {
            throw new PayPalCallException('`event_code` must be a PayPal event code such as T0006, or a group such as T00 for every payment.');
        }

        $currency = $this->text($request->get('currency'), 'currency');
        $currency = $currency === null ? null : strtoupper($currency);

        if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PayPalCallException('`currency` must be a three-letter code such as USD.');
        }

        $status = $this->text($request->get('status'), 'status');
        $wanted = null;

        if ($status !== null) {
            foreach (TransactionStatus::cases() as $case) {
                if (strcasecmp($case->value, $status) === 0 || strcasecmp($case->name, $status) === 0) {
                    $wanted = $case->value;
                }
            }

            if ($wanted === null) {
                throw new PayPalCallException('`status` must be one of: success, pending, denied, reversed.');
            }
        }

        return static fn (Transaction $row): bool => ($code === null || str_starts_with(strtoupper((string) $row->eventCode), $code))
            && ($currency === null || $row->amount?->currency === $currency)
            && ($wanted === null || $row->status === $wanted);
    }

    /**
     * @return array<string, Type>
     */
    protected function filterArguments(JsonSchema $schema): array
    {
        return [
            'event_code' => $schema->string()->description('Only rows with this event code, such as T0006, or in this group, such as T00 for every payment, T11 for reversals and refunds, T02 for currency conversions.'),
            'currency' => $schema->string()->description('Only rows in this currency, such as USD.'),
            'status' => $schema->string()->enum(['success', 'pending', 'denied', 'reversed'])->description('Only rows with this status.'),
        ];
    }
}
