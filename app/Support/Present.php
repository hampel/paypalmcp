<?php

declare(strict_types=1);

namespace App\Support;

use App\PayPal\HistoryResult;
use Carbon\CarbonImmutable;
use Hampel\PayPal\Api\Entity\Money;
use Hampel\PayPal\Api\Entity\Subscription;
use Hampel\PayPal\Api\Entity\SubscriptionTransaction;
use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Enum\TransactionStatus;

/**
 * How PayPal's records are laid out for an agent, and what is withheld from them.
 *
 * WHICH PAYER DETAILS REACH A TRANSCRIPT. A transaction row carries the payer's name, email,
 * PayPal account id, phone number and postal address, and usually a shipping name and address too.
 * Matching a payment to a person is the point of this server, so the name, email, account id and
 * country go out. The phone number and every address do not: no tool needs them, and an answer is
 * a transcript that may be kept anywhere. redact() removes them before a row is cached or shown.
 *
 * AN ALLOWLIST, NOT A DENYLIST: a section or field PayPal adds after this was written is dropped
 * until someone decides it may go out.
 */
final class Present
{
    /** The payer fields that go out; everything else in `payer_info` is withheld. */
    private const PAYER_FIELDS = ['account_id', 'email_address', 'payer_name', 'country_code', 'payer_status'];

    /** The sections of a row that are kept. shipping_info and auction_info are not. */
    private const SECTIONS = ['transaction_info', 'payer_info', 'cart_info', 'store_info', 'incentive_info'];

    /** The subscriber fields that go out: no shipping address, no phone. */
    private const SUBSCRIBER_FIELDS = ['email_address', 'payer_id', 'name'];

    /** PayPal's names for the event code groups, from its Transaction Search guide. */
    private const GROUPS = [
        'T00' => 'payment', 'T01' => 'fee', 'T02' => 'currency conversion', 'T03' => 'bank deposit into PayPal',
        'T04' => 'bank withdrawal from PayPal', 'T05' => 'debit card', 'T06' => 'credit card withdrawal',
        'T07' => 'credit card deposit', 'T08' => 'bonus', 'T09' => 'incentive', 'T10' => 'bill pay',
        'T11' => 'reversal', 'T12' => 'adjustment', 'T13' => 'authorization', 'T14' => 'dividend',
        'T15' => 'hold for dispute or investigation', 'T16' => 'buyer credit deposit', 'T17' => 'non-bank withdrawal',
        'T18' => 'buyer credit withdrawal', 'T19' => 'account correction', 'T20' => 'transfer to another PayPal account',
        'T21' => 'reserve or release', 'T22' => 'transfer', 'T30' => 'generic instrument', 'T50' => 'collection or disbursement',
        'T97' => 'payable or receivable', 'T98' => 'display only', 'T99' => 'other',
    ];

    /**
     * A transaction search row as PayPal sent it, less what may not go out.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function redact(array $row): array
    {
        $kept = array_intersect_key($row, array_flip(self::SECTIONS));

        if (isset($kept['payer_info']) && is_array($kept['payer_info'])) {
            $kept['payer_info'] = array_intersect_key($kept['payer_info'], array_flip(self::PAYER_FIELDS));
        }

        return $kept;
    }

    /**
     * One row, laid out flat.
     *
     * @return array<string, mixed>
     */
    public static function transaction(Transaction $row): array
    {
        $net = null;

        if ($row->amount !== null && $row->fee !== null && $row->amount->currency === $row->fee->currency) {
            $net = $row->amount->plus($row->fee);
        }

        return array_filter([
            'transaction_id' => $row->id,
            'event_code' => $row->eventCode,
            'event' => self::eventGroup($row->eventCode),
            'status' => self::status($row->status),
            'initiated' => self::time($row->initiatedAt),
            'updated' => self::time($row->updatedAt),
            'amount' => self::money($row->amount),
            'fee' => self::money($row->fee),
            'net' => self::money($net),
            'reference_id' => $row->referenceId,
            'reference_id_type' => $row->referenceIdType,
            'invoice_id' => $row->invoiceId,
            'custom_field' => $row->customField,
            'subject' => $row->subject,
            'note' => $row->note,
            'payer' => $row->payer === null ? null : array_filter([
                'account_id' => $row->payer->accountId,
                'email' => $row->payer->email,
                'name' => $row->payer->name(),
                'country_code' => $row->payer->countryCode,
            ], static fn (mixed $value): bool => $value !== null),
            'items' => array_map(static fn ($item): array => array_filter([
                'name' => $item->name,
                'code' => $item->code,
                'quantity' => $item->quantity,
                'unit_price' => self::money($item->unitPrice),
                'total' => self::money($item->total ?? $item->amount),
            ], static fn (mixed $value): bool => $value !== null), $row->cartItems),
            'instrument' => $row->instrumentSubType ?? $row->instrumentType,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  iterable<Transaction>  $rows
     * @return list<array<string, mixed>>
     */
    public static function transactions(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = self::transaction($row);
        }

        return $out;
    }

    /**
     * A subscription as PayPal sent it, less its links and the subscriber's address and phone.
     *
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription): array
    {
        $raw = $subscription->raw;

        unset($raw['links']);

        if (isset($raw['subscriber']) && is_array($raw['subscriber'])) {
            $raw['subscriber'] = array_intersect_key($raw['subscriber'], array_flip(self::SUBSCRIBER_FIELDS));
        }

        return $raw;
    }

    /** @return array<string, mixed> */
    public static function subscriptionPayment(SubscriptionTransaction $payment): array
    {
        return array_filter([
            'transaction_id' => $payment->id,
            'status' => $payment->status,
            'time' => self::time($payment->time),
            'gross' => self::money($payment->gross),
            // PayPal writes this fee positive, where transaction search writes the same fee negative
            // on the same payment (sandbox, 2026-11-21): shown negative, so gross + fee = net in
            // every tool's answer
            'fee' => self::money($payment->fee === null || $payment->fee->isNegative() ? $payment->fee : $payment->fee->negate()),
            'net' => self::money($payment->net),
            'payer_email' => $payment->payerEmail,
            'payer_name' => $payment->payerName,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * What a walk covered, said beside every answer built from one - because PayPal's reporting
     * lags, and "nothing found" must not be read as "nothing there".
     *
     * @return array<string, mixed>
     */
    public static function coverage(HistoryResult $result, \DateTimeImmutable $askedFrom, \DateTimeImmutable $askedTo): array
    {
        $notes = [];

        if ($result->coveredFrom === null) {
            $notes[] = 'Nothing was read: the range is outside PayPal\'s three years of history, or the request allowance ran out before the newest month.';
        }

        if ($result->reportingCut) {
            $notes[] = 'PayPal\'s reporting data reached only '.self::time($result->coveredTo).': a payment made after that is not in this answer yet. '
                .'PayPal documents up to three hours before a payment appears.';
        }

        if ($result->historyClipped) {
            $notes[] = 'PayPal keeps three years of history; the range was started at the earliest it still searches.';
        }

        if (! $result->complete && $result->coveredFrom !== null) {
            $notes[] = 'The request allowance ran out: only '.self::time($result->coveredFrom).' to '.self::time($result->coveredTo)
                .' was read, newest first. Ask again with a higher max_requests, or a shorter range, for the rest.';
        }

        if ($notes === []) {
            $notes[] = 'The whole range was read.';
        }

        return [
            'asked' => ['from' => self::time($askedFrom), 'to' => self::time($askedTo)],
            'covered' => ['from' => self::time($result->coveredFrom), 'to' => self::time($result->coveredTo)],
            'complete' => $result->complete && ! $result->reportingCut && ! $result->historyClipped && $result->coveredFrom !== null,
            'reporting_refreshed' => self::time($result->lastRefreshedAt),
            'months_read' => $result->monthsFetched,
            'months_from_cache' => $result->monthsFromCache,
            'note' => implode(' ', $notes),
        ];
    }

    /** @return array{currency_code: string, value: string}|null */
    public static function money(?Money $money): ?array
    {
        return $money?->jsonSerialize();
    }

    /** An instant as PayPal wrote it, with its own offset - which day it belongs to is the reader's call. */
    public static function time(?\DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }

    public static function eventGroup(?string $code): ?string
    {
        return $code === null ? null : (self::GROUPS[strtoupper(substr($code, 0, 3))] ?? null);
    }

    public static function status(?string $status): ?string
    {
        $case = $status === null ? null : TransactionStatus::tryFrom($status);

        return $case === null ? $status : strtolower($case->name);
    }

    /**
     * Said in every answer that carries a time, because the misreading costs a day.
     */
    public static function dateNote(): string
    {
        $zone = (string) config('paypalmcp.timezone');

        return 'Times are instants, as PayPal wrote them. A bare date given to this server is a whole day in '.$zone.'; '
            .'say which zone you mean when it matters which day a payment belongs to.';
    }

    /** A Unix time as ISO 8601 in UTC. */
    public static function iso(int $time): string
    {
        return CarbonImmutable::createFromTimestamp($time, 'UTC')->toIso8601ZuluString();
    }
}
