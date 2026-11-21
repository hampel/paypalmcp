<?php

declare(strict_types=1);

namespace App\Support;

use Hampel\PayPal\Api\Environment;
use PhpCollective\Toml\Exception\ParseException;
use PhpCollective\Toml\Toml;

/**
 * The PayPal accounts this server may read, and the REST app for each: the inventory file.
 *
 * The file is TOML, and HOLDS A CLIENT SECRET FOR EVERY ACCOUNT, as saasumcp's holds a password
 * for every file - one file to render and protect, rather than settings in one place and their
 * credentials in another. So nothing read from it is ever quoted back: not a parse error, not an
 * unknown setting's value. accounts.toml.example is its template. Every top-level table is an
 * account, named by its key, which every tool takes.
 *
 * READ AGAIN WHENEVER IT CHANGES. The server is one process for the life of a client's session,
 * so a parse kept for the life of the process would hide a newly added account until the client
 * reconnected - found in xfmcp. The modification time and size are the test.
 *
 * Everything is checked when the file is read, and an entry that cannot be used is an exception
 * naming what is wrong, not an account with a hole in it.
 */
class AccountInventory
{
    private const KEYS = ['name', 'environment', 'daily_budget', 'client_id', 'secret'];

    /** @var array<string, PayPalAccount>|null */
    private ?array $accounts = null;

    private ?string $signature = null;

    public function path(): string
    {
        return (string) config('paypalmcp.accounts_path');
    }

    /**
     * @return array<string, PayPalAccount>
     *
     * @throws AccountInventoryException
     */
    public function all(): array
    {
        $signature = $this->signature();

        if ($this->accounts === null || $signature !== $this->signature) {
            $this->accounts = $this->load($this->raw());
            $this->signature = $signature;
        }

        return $this->accounts;
    }

    /** @throws AccountInventoryException */
    public function get(string $key): PayPalAccount
    {
        $accounts = $this->all();

        return $accounts[$key] ?? throw new AccountInventoryException(sprintf(
            'there is no PayPal account called "%s"%s',
            $key,
            $accounts === [] ? '' : ' - the accounts are '.implode(', ', array_keys($accounts)),
        ));
    }

    /**
     * The decoded file. A test overrides this, so that the suite never reads a real inventory.
     *
     * @return array<mixed>
     *
     * @throws AccountInventoryException
     */
    public function raw(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new AccountInventoryException('the account inventory does not exist: '.$path);
        }

        if (! is_readable($path)) {
            throw new AccountInventoryException('the account inventory cannot be read: '.$path);
        }

        try {
            return Toml::decodeFile($path);
        } catch (ParseException $e) {
            // the line and column only - never the parser's message, which can quote the text
            throw new AccountInventoryException(sprintf(
                'the account inventory is not valid TOML%s',
                $e->span !== null ? ', at line '.$e->span->line.', column '.$e->span->column : '',
            ));
        }
    }

    private function signature(): string
    {
        $path = $this->path();

        clearstatcache(true, $path);

        return is_file($path) ? filemtime($path).':'.filesize($path) : 'missing';
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, PayPalAccount>
     *
     * @throws AccountInventoryException
     */
    private function load(array $data): array
    {
        if ($data === []) {
            throw new AccountInventoryException('the account inventory describes no account - add a [<key>] table');
        }

        $accounts = [];
        $apps = [];

        foreach ($data as $key => $row) {
            $key = (string) $key;

            if (! is_array($row) || array_is_list($row)) {
                throw new AccountInventoryException('"'.$key.'" at the top of the account inventory is not an account - every setting goes under a [<key>] table');
            }

            $account = $this->account($key, $row);

            // one app is one account, so two keys for it would each get a whole budget
            if ($account->hasCredentials()) {
                $identity = $account->identity();

                if (isset($apps[$identity])) {
                    throw new AccountInventoryException(sprintf('"%s" and "%s" use the same REST app in %s - one entry per app, so it has one budget', $apps[$identity], $key, $account->environment->value));
                }

                $apps[$identity] = $key;
            }

            $accounts[$key] = $account;
        }

        return $accounts;
    }

    /**
     * @param  array<mixed>  $row
     *
     * @throws AccountInventoryException
     */
    private function account(string $key, array $row): PayPalAccount
    {
        if (preg_match('/^[a-z0-9]+$/', $key) !== 1) {
            throw new AccountInventoryException('account "'.$key.'": a key is lowercase letters and digits only - it is the `account` every tool takes');
        }

        $fail = static fn (string $what): AccountInventoryException => new AccountInventoryException('account "'.$key.'": '.$what);

        foreach (array_keys($row) as $setting) {
            if (! in_array($setting, self::KEYS, true)) {
                throw $fail('"'.$setting.'" is not a setting - an account has '.implode(', ', self::KEYS));
            }
        }

        $name = $row['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw $fail('name must be the business the account belongs to');
        }

        // required, not defaulted: a live account read as sandbox is an empty answer that looks
        // like a real one, and the other way round reads real payers by accident
        $environment = $row['environment'] ?? null;
        $environment = is_string($environment) ? Environment::tryFrom(strtolower(trim($environment))) : null;

        if ($environment === null) {
            throw $fail('environment must be "live" or "sandbox"');
        }

        $budget = $row['daily_budget'] ?? (int) config('paypalmcp.daily_budget');

        if (! is_int($budget) || $budget < 1) {
            throw $fail('daily_budget must be a positive integer, or left out for the default');
        }

        $clientId = $row['client_id'] ?? null;
        $secret = $row['secret'] ?? null;

        foreach (['client_id' => $clientId, 'secret' => $secret] as $setting => $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw $fail($setting.' must be text, or left out until the account has an app');
            }
        }

        // half a credential is a typo, not an account waiting for one
        if (($clientId === null) !== ($secret === null)) {
            throw $fail($clientId === null ? 'has a secret and no client_id' : 'has a client_id and no secret');
        }

        return new PayPalAccount(
            $key,
            trim($name),
            $environment,
            $budget,
            is_string($clientId) ? trim($clientId) : null,
            is_string($secret) ? trim($secret) : null,
        );
    }
}
