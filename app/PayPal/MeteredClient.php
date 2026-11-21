<?php

declare(strict_types=1);

namespace App\PayPal;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The PSR-18 transport every PayPal client in this server sends through, counting as it goes.
 *
 * saasu-api has a request budget of its own, `withRequestBudget()`; paypal-api has none, because
 * PayPal rations nothing. So the count is kept here, at the one seam every request crosses - the
 * token request included, which goes through the same client without a bearer.
 *
 * IT REFUSES ANY REQUEST THE GATEWAY DID NOT METER. Gateway::call() opens a meter with the most
 * the call may send and closes it after; a request outside one is a client built somewhere else,
 * which would spend outside the daily budget, and it is refused rather than sent. A request past
 * the meter's limit is refused the same way.
 *
 * The refusals are plain RuntimeExceptions, deliberately NOT ClientExceptionInterface: paypal-api
 * catches that interface and reports it as PayPal being unreachable, which would turn "this server
 * stopped itself" into a network failure. Anything else passes through it untouched.
 *
 * Bound under PayPalServiceProvider::HTTP_CLIENT, around the package's own Laravel adapter, so
 * Http::fake() still sees every request.
 */
final class MeteredClient implements ClientInterface
{
    private ?int $limit = null;

    private int $sent = 0;

    public function __construct(
        private readonly ClientInterface $inner,
    ) {}

    /** Start counting, allowing at most $limit requests until close(). */
    public function open(int $limit): void
    {
        if ($this->limit !== null) {
            throw new \LogicException('a PayPal call is already metered - calls through the gateway do not nest');
        }

        $this->limit = $limit;
        $this->sent = 0;
    }

    /** Stop counting, and say how many requests were sent since open(). */
    public function close(): int
    {
        $sent = $this->sent;

        $this->limit = null;
        $this->sent = 0;

        return $sent;
    }

    public function sent(): int
    {
        return $this->sent;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->limit === null) {
            throw new UnmeteredRequestException('A request to PayPal was made outside App\PayPal\Gateway::call(), and refused: it would not be counted against the daily budget.');
        }

        if ($this->sent >= $this->limit) {
            throw new RequestAllowanceExceeded($this->limit);
        }

        // counted before it is sent: a request that fails in transit may still have reached PayPal
        $this->sent++;

        return $this->inner->sendRequest($request);
    }
}
