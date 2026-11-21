<?php

declare(strict_types=1);

namespace App\PayPal;

use App\Support\PayPalAccount;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * How many requests this server has sent each PayPal account in the last 24 hours, kept across
 * every server process.
 *
 * WHAT IT BOUNDS IS A CONVERSATION, NOT A QUOTA. PayPal publishes no rate limit for these
 * endpoints, and its responses carry no rate-limit header (measured by paypal-api, 2026-09-15). The
 * danger is an agent that keeps asking: finding a payer means walking months of history, and a
 * conversation that does it for every name in a list would send thousands of requests on a
 * credential that can also move money. So the server keeps a ceiling of its own and refuses a call
 * that would pass it.
 *
 * PERSISTED, because an MCP client starts a server process per session: a count kept in the
 * process would reset every time a conversation began. It is in the cache store, under a lock, so
 * two conversations at once share one count.
 *
 * A ROLLING 24 HOURS, NOT A CALENDAR DAY, counted by the hour and summed over the last 24, so a
 * whole budget can never be spent either side of a midnight. An hour is counted whole while any of
 * it is in the window, which errs towards refusing. From saasumcp, where it matters more.
 *
 * RESERVE, THEN SETTLE. A call first sets aside the most it might send, under the lock, so that two
 * processes cannot each see the same room and both spend it; it then gives back what it did not
 * use. A call is refused outright when its reservation does not fit, rather than started and cut
 * off half way.
 *
 * Keyed by PayPalAccount::identity() - the app in its environment - so renaming an inventory key
 * does not reset the count.
 */
class DailyBudget
{
    public const HOUR = 3600;

    public const WINDOW_HOURS = 24;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  answers Unix seconds; a test moves time with it
     */
    public function __construct(
        private readonly Repository $cache,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Set aside $requests for one call to $account, or refuse.
     *
     * @throws BudgetSpentException
     */
    public function reserve(PayPalAccount $account, int $requests): Reservation
    {
        return $this->locked($account->identity(), function () use ($account, $requests): Reservation {
            $now = ($this->clock)();
            $hours = $this->window($this->read($account->identity()), $now);
            $spent = array_sum($hours);

            if ($spent + $requests > $account->dailyBudget) {
                throw new BudgetSpentException($spent, $account->dailyBudget, $requests, $this->freesAt($hours, $spent + $requests - $account->dailyBudget));
            }

            $hour = $this->hour($now);
            $hours[$hour] = ($hours[$hour] ?? 0) + $requests;

            $this->write($account->identity(), $hours);

            return new Reservation($account->identity(), $hour, $requests);
        });
    }

    /**
     * Give back what a reservation did not use. Called whether the call succeeded or not: a
     * request that was sent and failed was still sent.
     */
    public function settle(Reservation $reservation, int $used): void
    {
        $unused = $reservation->requests - max(0, $used);

        if ($unused <= 0) {
            return;
        }

        $this->locked($reservation->account, function () use ($reservation, $unused): void {
            $hours = $this->window($this->read($reservation->account), ($this->clock)());

            if (! isset($hours[$reservation->hour])) {
                return; // the hour has left the window
            }

            $hours[$reservation->hour] = max(0, $hours[$reservation->hour] - $unused);

            $this->write($reservation->account, $hours);
        });
    }

    /** Requests counted against $account in the window, reservations in flight included. */
    public function spent(PayPalAccount $account): int
    {
        return array_sum($this->window($this->read($account->identity()), ($this->clock)()));
    }

    public function remaining(PayPalAccount $account): int
    {
        return max(0, $account->dailyBudget - $this->spent($account));
    }

    /**
     * When enough of the oldest hours leave the window to free $needed requests.
     *
     * @param  array<int, int>  $hours
     */
    private function freesAt(array $hours, int $needed): int
    {
        ksort($hours);

        $freed = 0;

        foreach ($hours as $hour => $count) {
            $freed += $count;

            if ($freed >= $needed) {
                return $hour + (self::WINDOW_HOURS + 1) * self::HOUR;
            }
        }

        // asked for more than a whole budget: never, but say when the window is next empty
        return $this->hour(($this->clock)()) + (self::WINDOW_HOURS + 1) * self::HOUR;
    }

    /**
     * @param  array<int, int>  $hours
     * @return array<int, int>
     */
    private function window(array $hours, int $now): array
    {
        $oldest = $this->hour($now) - self::WINDOW_HOURS * self::HOUR;

        return array_filter($hours, static fn (int $count, int $hour): bool => $hour >= $oldest && $count > 0, ARRAY_FILTER_USE_BOTH);
    }

    private function hour(int $time): int
    {
        return intdiv($time, self::HOUR) * self::HOUR;
    }

    /** @return array<int, int> */
    private function read(string $account): array
    {
        $stored = $this->cache->get($this->key($account));

        if (! is_array($stored)) {
            return [];
        }

        $hours = [];

        foreach ($stored as $hour => $count) {
            if (is_int($hour) && is_int($count)) {
                $hours[$hour] = $count;
            }
        }

        return $hours;
    }

    /** @param  array<int, int>  $hours */
    private function write(string $account, array $hours): void
    {
        // no expiry: an expiry is a way for the count to vanish early
        $this->cache->forever($this->key($account), $hours);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(string $account, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new \LogicException('the cache store keeping the request budget does not support locks');
        }

        // waits up to ten seconds for another process's read-and-write, which takes milliseconds
        return $store->lock($this->key($account).'.lock', 10)->block(10, $callback);
    }

    private function key(string $account): string
    {
        return 'budget.'.$account;
    }
}
