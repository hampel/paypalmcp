<?php

declare(strict_types=1);

namespace App\PayPal;

/**
 * A call reached the number of requests its meter allowed. Not a ClientExceptionInterface - see
 * MeteredClient.
 */
final class RequestAllowanceExceeded extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf('this call was allowed %d requests to PayPal and needed more', $limit));
    }
}
