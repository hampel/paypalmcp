<?php

declare(strict_types=1);

namespace App\PayPal;

use App\Support\PayPalAccount;
use App\Support\Present;
use Closure;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Endpoint\Transactions;
use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Filter\TransactionFilter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * An account's transaction history over any range, read a calendar month at a time.
 *
 * PayPal's transaction search has no payer, email or name filter, so finding a person, totalling
 * fees or listing a quarter all mean reading every row in the range and filtering here. Three years
 * is about 37 searches. What makes that affordable is that rows old enough to have left PayPal's
 * reporting lag do not change, so a month read once is kept.
 *
 * MONTHS IN UTC, NOT 31-DAY WINDOWS FROM WHEREVER THE RANGE STARTS. A calendar month is never over
 * PayPal's 31 days, and the same month is the same search whatever range it falls in, so a month
 * read for one question answers the next. A month at the edge of a range is read whole when it is
 * closed - one search, or a page more in a busy month - and its rows outside the range dropped.
 *
 * CLOSED IS CLOSED_AFTER_HOURS PAST ITS END. PayPal documents up to three hours before a row
 * appears, measured about two; three days is far past either, and leaves room for a pending row
 * to settle. A closed month is kept for CACHE_DAYS. A month still open is read for the part of the
 * range inside it, every time, and never kept.
 *
 * NEWEST MONTH FIRST, and a walk that would pass its search allowance stops at a month boundary
 * rather than half way through one, so what it covered is a whole range it can state.
 *
 * WHAT IS KEPT IS ALREADY REDACTED: Present::redact() runs on every row as it arrives, so a payer's
 * address and phone never reach the cache, and the cache is in the 0700 state directory. Names and
 * email addresses are kept, because matching them is what the cache is for.
 */
class TransactionHistory
{
    public const CLOSED_AFTER_HOURS = 72;

    public const CACHE_DAYS = 30;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  answers Unix seconds; a test moves time with it
     */
    public function __construct(
        private readonly Repository $cache,
        ?Closure $clock = null,
    ) {
        // Carbon's clock rather than time(), so a test can travel
        $this->clock = $clock ?? static fn (): int => Carbon::now()->getTimestamp();
    }

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.($this->clock)()))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The earliest moment PayPal still searches, with a day's margin. */
    public function floor(): \DateTimeImmutable
    {
        return $this->now()->modify(sprintf('-%d years', Transactions::HISTORY_YEARS))->modify('+1 day');
    }

    /**
     * How many searches a walk of this range takes at least, one a month, before any month needs
     * a second page and before the cache saves any.
     */
    public function monthsIn(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return count($this->months(max($from, $this->floor()), min($to, $this->now())));
    }

    /**
     * Every row from $from to $to, newest first, sending at most $maxSearches searches.
     */
    public function walk(
        Client $paypal,
        PayPalAccount $account,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $nonBalanceAffecting,
        int $maxSearches,
    ): HistoryResult {
        $utc = new \DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = min($to->setTimezone($utc), $this->now());
        $floor = $this->floor();
        $clipped = $from < $floor;
        $from = max($from, $floor);

        if ($from > $to) {
            return new HistoryResult([], null, null, true, null, 0, 0, $clipped, false);
        }

        $closedBefore = $this->now()->modify(sprintf('-%d hours', self::CLOSED_AFTER_HOURS));
        $filter = $nonBalanceAffecting ? TransactionFilter::make()->includingNonBalanceAffecting() : TransactionFilter::make();
        $mode = $nonBalanceAffecting ? 'all' : 'balance';

        $searches = 0;
        $rows = [];
        $oldestCovered = null;
        $coveredTo = $to;
        $refreshed = null;
        $cut = false;
        $fromCache = 0;
        $fetched = 0;
        $complete = true;
        $newest = true;

        foreach ($this->months($from, $to) as [$monthStart, $monthEnd]) {
            // a whole closed month is kept; one that history or the clock cuts into is not
            $whole = $monthEnd <= $closedBefore && $monthStart >= $floor;
            $key = 'rows.'.$account->identity().'.'.$monthStart->format('Y-m').'.'.$mode;
            $month = $whole ? $this->cache->get($key) : null;

            if (is_array($month) && isset($month['rows']) && is_array($month['rows'])) {
                $fromCache++;
            } else {
                $start = $whole ? $monthStart : max($monthStart, $from);
                $end = $whole ? $monthEnd : min($monthEnd, $to);
                $month = $this->fetch($paypal, $start, $end, $filter, $maxSearches, $searches);

                if ($month === null) {
                    $complete = false;

                    break;
                }

                $fetched++;
                $refreshed ??= $month['last_refreshed'];

                // only the newest window can reach past PayPal's last refresh
                if ($newest && $month['end'] !== null && $month['end'] < $end) {
                    $coveredTo = $month['end'];
                    $cut = true;
                }

                if ($whole) {
                    $this->cache->put($key, ['rows' => $month['rows']], self::CACHE_DAYS * 86400);
                }
            }

            $newest = false;
            $oldestCovered = $monthStart;

            foreach ($month['rows'] as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                /** @var array<string, mixed> $raw */
                $row = Transaction::fromArray($raw);

                if ($row->initiatedAt === null || ($row->initiatedAt >= $from && $row->initiatedAt <= $to)) {
                    $rows[] = $row;
                }
            }
        }

        if ($oldestCovered === null) {
            return new HistoryResult([], null, null, false, $refreshed, $fromCache, $fetched, $clipped, $cut);
        }

        return new HistoryResult(
            $this->newestFirst($rows),
            max($from, $oldestCovered),
            $coveredTo,
            $complete,
            $refreshed,
            $fromCache,
            $fetched,
            $clipped,
            $cut,
        );
    }

    /**
     * Every page of one search, or null when they would not fit in what is left of $maxSearches.
     *
     * @return array{rows: list<array<string, mixed>>, end: ?\DateTimeImmutable, last_refreshed: ?\DateTimeImmutable}|null
     */
    private function fetch(Client $paypal, \DateTimeImmutable $start, \DateTimeImmutable $end, TransactionFilter $filter, int $maxSearches, int &$searches): ?array
    {
        $rows = [];
        $page = 1;

        do {
            if ($searches >= $maxSearches) {
                return null;
            }

            $searches++;
            $result = $paypal->transactions()->search($start, $end, $filter, $page);

            // a month that needs more pages than are left would be paid for and then dropped
            if ($page === 1 && $result->totalPages !== null && $result->totalPages - 1 > $maxSearches - $searches) {
                return null;
            }

            foreach ($result->transactions as $row) {
                $rows[] = Present::redact($row->raw);
            }

            $page++;
        } while ($result->hasMore());

        return ['rows' => $rows, 'end' => $result->endDate, 'last_refreshed' => $result->lastRefreshedAt];
    }

    /**
     * The UTC calendar months that $from to $to touches, newest first.
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    public function months(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($from > $to) {
            return [];
        }

        $utc = new \DateTimeZone('UTC');
        $cursor = new \DateTimeImmutable($to->setTimezone($utc)->format('Y-m-01 00:00:00'), $utc);
        $months = [];

        while ($cursor->modify('+1 month') > $from) {
            $months[] = [$cursor, $cursor->modify('+1 month')->modify('-1 second')];
            $cursor = $cursor->modify('-1 month');
        }

        return $months;
    }

    /**
     * @param  list<Transaction>  $rows
     * @return list<Transaction>
     */
    private function newestFirst(array $rows): array
    {
        $order = array_keys($rows);

        usort($order, static function (int $a, int $b) use ($rows): int {
            $byTime = ($rows[$b]->initiatedAt?->getTimestamp() ?? 0) <=> ($rows[$a]->initiatedAt?->getTimestamp() ?? 0);

            return $byTime !== 0 ? $byTime : $a <=> $b;
        });

        return array_map(static fn (int $i): Transaction => $rows[$i], $order);
    }
}
