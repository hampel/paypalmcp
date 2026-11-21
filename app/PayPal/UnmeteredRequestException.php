<?php

declare(strict_types=1);

namespace App\PayPal;

/**
 * A request to PayPal outside Gateway::call() - a bug in this server, refused before it was sent.
 */
final class UnmeteredRequestException extends \RuntimeException {}
