<?php

declare(strict_types=1);

namespace App\PayPal;

use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use App\Support\PayPalAccount;
use App\Support\StateDirectory;
use Closure;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Exception\ApiException;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Exception\NotPermittedException;
use Hampel\PayPal\Api\Exception\RequestException;
use Hampel\PayPal\Api\Exception\ServerException;
use Hampel\PayPal\Api\Exception\TooManyRequestsException;
use Hampel\PayPal\Api\Exception\ValidationException;
use Hampel\PayPal\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Illuminate\Support\Carbon;

/**
 * Every request this server sends to PayPal goes through here.
 *
 * It resolves an account key to a client holding that account's own REST app, holds each call to
 * the account's daily budget, and turns every failure into a sentence. A tool says what to ask and
 * how many requests that may take; it never builds a client itself, and MeteredClient refuses a
 * request from one that did.
 */
class Gateway
{
    /**
     * Requests set aside on top of what a call asks for, for the token: one when none is cached,
     * and two more when PayPal refuses a cached one early - paypal-api then asks for a new token
     * and sends the refused request once more.
     */
    public const LOGIN_ALLOWANCE = 3;

    /** Requests sent since resetLastSpent(), token requests included - a tool may make several calls. */
    private int $lastSpent = 0;

    public function __construct(
        private readonly AccountInventory $inventory,
        private readonly PayPalManager $manager,
        private readonly DailyBudget $budget,
        private readonly MeteredClient $meter,
    ) {}

    /** @throws PayPalCallException */
    public function account(string $key): PayPalAccount
    {
        try {
            $account = $this->inventory->get(trim($key));
        } catch (AccountInventoryException $e) {
            throw new PayPalCallException(ucfirst($e->getMessage()).'. Call list-accounts for the accounts this server can read.');
        }

        if (! $account->hasCredentials()) {
            throw new PayPalCallException(sprintf(
                'The PayPal account "%s" has no REST app configured on this server: its entry in the account inventory has no client_id and secret. Run `paypalmcp app:validate` where the server runs.',
                $account->key,
            ));
        }

        return $account;
    }

    /**
     * Run $call against $account, allowed at most $requests requests plus a token.
     *
     * @template T
     *
     * @param  Closure(Client): T  $call
     * @return T
     *
     * @throws PayPalCallException
     */
    public function call(PayPalAccount $account, int $requests, Closure $call): mixed
    {
        $allowed = $requests + self::LOGIN_ALLOWANCE;

        try {
            $reservation = $this->budget->reserve($account, $allowed);
        } catch (BudgetSpentException $e) {
            throw new PayPalCallException($this->budgetSpent($account, $e));
        }

        $this->meter->open($allowed);

        try {
            return $call($this->client($account));
        } catch (RequestAllowanceExceeded $e) {
            throw new PayPalCallException(sprintf(
                'Stopped before sending more: this call was allowed %d requests and needed more. Ask for less - a shorter date range.',
                $e->limit,
            ));
        } catch (ExceptionInterface $e) {
            throw new PayPalCallException($this->explain($account, $e));
        } finally {
            $sent = $this->meter->close();
            $this->lastSpent += $sent;
            $this->budget->settle($reservation, $sent);
        }
    }

    public function lastSpent(): int
    {
        return $this->lastSpent;
    }

    /**
     * Start a tool call's count at zero. The gateway lives for the process, so without this an
     * answer that sent nothing - one served from the cache - reports what the previous call sent.
     */
    public function resetLastSpent(): void
    {
        $this->lastSpent = 0;
    }

    /**
     * The account's budget as an answer reports it.
     *
     * @return array<string, int>
     */
    public function budget(PayPalAccount $account): array
    {
        return [
            'requests_this_call' => $this->lastSpent,
            'used_last_24h' => $this->budget->spent($account),
            'daily_budget' => $account->dailyBudget,
        ];
    }

    private function client(PayPalAccount $account): Client
    {
        // the token is about to be cached in it
        StateDirectory::ensure();

        // the app's identity is in the name, so an inventory edited while the server runs gets a
        // new client rather than the one the manager kept for the old app
        $name = $account->connection();

        config(['paypal.connections.'.$name => [
            'client_id' => $account->clientId,
            'secret' => $account->secret(),
            'environment' => $account->environment->value,
            'base_uri' => null,
        ]]);

        try {
            return $this->manager->client($name);
        } catch (InvalidConfiguration $e) {
            throw new PayPalCallException('This server\'s PayPal settings are not usable: '.$e->getMessage());
        }
    }

    private function budgetSpent(PayPalAccount $account, BudgetSpentException $e): string
    {
        $freesAt = Carbon::createFromTimestamp($e->freesAt, 'UTC');

        return sprintf(
            'Refused without asking PayPal: this server has used %d of the %d requests it allows itself for "%s" in any 24 hours, '
            .'and this call needs up to %d. Room for it frees at %s. The limit is this server\'s own, set to bound what one '
            .'conversation can send on a credential that could also move money. Do not work round it with other tools; say '
            .'what you could not look up.',
            $e->spent,
            $e->budget,
            $account->key,
            $e->wanted,
            $freesAt->toIso8601ZuluString(),
        );
    }

    private function explain(PayPalAccount $account, ExceptionInterface $e): string
    {
        $whose = sprintf('the REST app this server uses for "%s"', $account->key);

        return match (true) {
            // the token endpoint names its refusal; a data endpoint answers a bare 401, which after
            // paypal-api's one retry with a fresh token means the token itself is refused
            $e instanceof NotAuthenticatedException && $e->oauthError() !== null => sprintf(
                'PayPal refused %s (%s): the client id or secret is wrong, or the app was deleted, or it is a %s app '
                .'configured as the other environment. This is a configuration problem on the server, not an empty answer.',
                $whose,
                $e->oauthError(),
                $account->environment === Environment::Live ? 'sandbox' : 'live',
            ),
            $e instanceof NotAuthenticatedException => sprintf(
                'PayPal issued %s a token and then refused it. This is a configuration problem on the server, not an empty answer. PayPal said: %s',
                $whose,
                $this->paypalSaid($e),
            ),
            $e instanceof NotPermittedException => sprintf(
                'PayPal refused %s permission for this. A transaction search or balance needs Transaction Search ticked '
                .'on the app in PayPal\'s developer dashboard, and a change there can take until the cached token '
                .'expires (up to nine hours) to apply. PayPal said: %s',
                $whose,
                $this->paypalSaid($e),
            ),
            $e instanceof ValidationException => sprintf('PayPal refused the request as malformed: %s', $this->paypalSaid($e)),
            $e instanceof NotFoundException && ! $e->isMissingResource() && $e->error === null => 'PayPal answered 404 '
                .'with no error body, which is a wrong path - a bug in this server, not a missing record.',
            $e instanceof NotFoundException => sprintf('"%s" has no such record. PayPal said: %s', $account->key, $this->paypalSaid($e)),
            $e instanceof TooManyRequestsException => 'PayPal answered "too many requests"'
                .($e->retryAfter !== null ? sprintf(' and asked for %d seconds before the next', $e->retryAfter) : '')
                .'. Stop asking this account for now.',
            $e instanceof ServerException => sprintf('PayPal failed with an error of its own (HTTP %d). Try again later.', $e->statusCode),
            $e instanceof MalformedResponseException => 'PayPal answered with something that is not JSON, such as a '
                .'maintenance or proxy page. Try again later.',
            $e instanceof RequestException => 'PayPal could not be reached: the connection failed or timed out.',
            $e instanceof ApiException => sprintf('PayPal refused the request (HTTP %d): %s', $e->statusCode, $this->paypalSaid($e)),
            // refused by paypal-api before sending, with a sentence of its own about the input
            $e instanceof InvalidArgumentException => 'Refused before asking PayPal: '.$e->getMessage(),
            default => 'The call to PayPal failed ('.class_basename($e).').',
        };
    }

    /**
     * PayPal's own error text, with its debug id - which is what PayPal's support asks for. Never
     * the exception's message, which quotes the request's URI, and never the body.
     */
    private function paypalSaid(ApiException $e): string
    {
        $said = trim($e->error?->describe() ?? '');
        $said = $said === '' ? 'nothing beyond the status' : $said;
        $said = mb_strlen($said) > 400 ? mb_substr($said, 0, 400).'...' : rtrim($said, '.');

        return $said.($e->debugId !== null ? ' [PayPal debug id '.$e->debugId.']' : '');
    }
}
