<?php

declare(strict_types=1);

namespace App\Support;

use Hampel\PayPal\Api\Environment;

/**
 * One PayPal business account this server may read, as the inventory describes it.
 *
 * A REST app belongs to one account, so the app's client id is what decides which account is
 * read: the inventory's `name` is a label for people, and nothing checks it against PayPal.
 */
final readonly class PayPalAccount
{
    public function __construct(
        public string $key,
        public string $name,
        public Environment $environment,
        public int $dailyBudget,
        public ?string $clientId,
        #[\SensitiveParameter] private ?string $secret,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->clientId !== null && $this->secret !== null;
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    /**
     * What the budget and the cached rows are kept under: the app, in its environment.
     *
     * Not the inventory key, which can be renamed, and not the secret. Two keys naming one app are
     * refused by the inventory, because they would be one account with two budgets. An account
     * with no app yet gets a placeholder that nothing is ever sent under.
     */
    public function identity(): string
    {
        return $this->environment->value.'-'.substr(hash('sha256', $this->clientId ?? 'none:'.$this->key), 0, 16);
    }

    /** The paypal-api-laravel connection this account is registered under. */
    public function connection(): string
    {
        return 'paypalmcp-'.$this->key.'-'.$this->identity();
    }

    /**
     * What list-accounts shows. Never the client id: it is half a credential, and the answer goes
     * into a conversation.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'account' => $this->key,
            'name' => $this->name,
            'environment' => $this->environment->value,
            'daily_budget' => $this->dailyBudget,
            'credentials_configured' => $this->hasCredentials(),
        ];
    }
}
