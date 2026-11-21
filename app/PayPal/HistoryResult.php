<?php

declare(strict_types=1);

namespace App\PayPal;

use Hampel\PayPal\Api\Entity\Transaction;

/**
 * What one walk of an account's history found, and how far it actually reached.
 *
 * `coveredFrom` and `coveredTo` are the part of the range asked for that was read in full. They
 * differ from it in three ways, and an answer that did not say so would read "nothing found" as
 * "nothing there": PayPal's reporting lags and cuts a search back to its last refresh; history
 * stops at three years; and a walk that ran out of requests stops at a month boundary.
 */
final readonly class HistoryResult
{
    /**
     * @param  list<Transaction>  $transactions  newest first, every row inside the covered range
     */
    public function __construct(
        public array $transactions,
        public ?\DateTimeImmutable $coveredFrom,
        public ?\DateTimeImmutable $coveredTo,
        public bool $complete,
        public ?\DateTimeImmutable $lastRefreshedAt,
        public int $monthsFromCache,
        public int $monthsFetched,
        public bool $historyClipped,
        public bool $reportingCut,
    ) {}
}
