<?php

declare(strict_types=1);

namespace App\Commands;

use App\PayPal\DailyBudget;
use App\PayPal\Gateway;
use App\PayPal\PayPalCallException;
use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use App\Support\PayPalAccount;
use Closure;
use Hampel\ConsoleReport\RendersChecks;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Filter\TransactionFilter;
use LaravelZero\Framework\Commands\Command;

/**
 * Can this machine do what the configuration says? Exercised, not described.
 *
 * The command a deployment gates on. Run it as the user the MCP client starts the server as: the
 * state directory holds cached tokens, and a check run as root reports a directory writable that
 * the server's user cannot touch.
 *
 * ONLINE, IT ASKS PAYPAL FOR WHAT THE TOOLS NEED, one cheap request each: a token, whose scopes say
 * whether the app has Transaction Search; a one-row search; the balances; and a subscription read
 * of an id that does not exist, which a permitted app answers with "not found". Every probe goes
 * through the gateway and counts against the account's daily budget: four requests an account, or
 * three with a cached token.
 *
 * Exit: 0 when nothing failed, 1 when anything did. --strict makes a warning-only run exit 2.
 */
class Validate extends Command
{
    use RendersChecks;

    /** A budget more than this share spent is worth a warning. */
    private const BUDGET_WARN = 0.8;

    protected $signature = 'app:validate
                            {--offline : Skip the checks that reach PayPal}
                            {--strict : Exit 2 when a check warned and none failed, for a monitor rather than a gate}';

    protected $description = 'Check the configuration, the state directory, and that each app can read what the tools need';

    public function handle(AccountInventory $inventory, Gateway $gateway, DailyBudget $budget): int
    {
        $this->setReportOutput($this->getOutput());

        // RendersChecks keeps its outcome on the command, and Laravel reuses a command object
        // within a process - so without this a second call inherits the first one's failure
        $this->checkFailed = false;
        $this->checkWarned = false;

        $this->checkSection('Runtime');
        $this->checkRuntime();

        $this->checkSection('State');
        $this->checkState();

        $this->checkSection('Accounts');
        $accounts = $this->checkInventory($inventory);

        foreach ($accounts as $account) {
            $this->checkSection('Account: '.$account->key);
            $this->checkAccount($account, $gateway, $budget);
        }

        return $this->checkExitCode((bool) $this->option('strict'));
    }

    private function checkRuntime(): void
    {
        $user = function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid())
            : (getenv('USER') ?: 'unknown');

        $user === 'root'
            ? $this->checkWarn('Running as', 'root - run as the user the MCP client starts the server as')
            : $this->checkOk('Running as', $user);

        $this->checkOk('PHP', PHP_VERSION);

        $loaded = $this->laravel->bound('paypalmcp.env.loaded') ? $this->laravel->make('paypalmcp.env.loaded') : null;

        is_string($loaded)
            ? $this->checkOk('Environment file', $loaded)
            : $this->checkSkip('Environment file', 'none found - every setting is a default');
    }

    private function checkState(): void
    {
        $path = (string) config('paypalmcp.state_path');

        if (! config('paypalmcp.state_path_set') && $this->followsWorkingDirectory()) {
            $this->checkFail('State directory', $path.' - derived from the working directory, which an MCP client chooses; set PAYPALMCP_STATE_PATH. '
                .'Each directory would keep its own daily budget and its own cached tokens.');

            return;
        }

        if (! self::isAbsolute($path)) {
            $this->checkFail('State directory', $path.' - not an absolute path');

            return;
        }

        if (! is_dir($path)) {
            $parent = dirname($path);

            is_dir($parent) && is_writable($parent)
                ? $this->checkWarn('State directory', $path.' - does not exist yet; the first call creates it 0700')
                : $this->checkFail('State directory', $path.' - does not exist, and '.$parent.' is not writable');

            return;
        }

        $mode = fileperms($path) & 0777;

        if (! is_writable($path)) {
            $this->checkFail('State directory', $path.' - not writable by this user');
        } elseif (($mode & 0077) !== 0 && DIRECTORY_SEPARATOR !== '\\') {
            $this->checkFail('State directory', sprintf('%s - %04o; it holds cached PayPal tokens and payers\' names, make it 0700', $path, $mode));
        } else {
            $this->checkOk('State directory', $path);
        }
    }

    /** @return array<string, PayPalAccount> */
    private function checkInventory(AccountInventory $inventory): array
    {
        $path = $inventory->path();

        try {
            $accounts = $inventory->all();
        } catch (AccountInventoryException $e) {
            $this->checkFail('Account inventory', ucfirst($e->getMessage()));

            return [];
        }

        $this->checkOk('Account inventory', $path.' - '.count($accounts).' account(s): '.implode(', ', array_keys($accounts)));

        $this->checkInventoryMode($path);

        $default = (int) config('paypalmcp.daily_budget');

        $this->checkOk('Default daily budget', $default.' requests an account in any 24 hours');

        return $accounts;
    }

    /** The inventory holds every app's secret, so only the server's user may read it. */
    private function checkInventoryMode(string $path): void
    {
        $stat = @stat($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->checkSkip('Inventory mode', 'not a Unix file mode on Windows; limit who can read the file in its Security properties');
        } elseif ($stat === false) {
            $this->checkSkip('Inventory mode', 'could not be read');
        } else {
            [$ok, $detail] = self::fileModeVerdict($stat['mode'], $stat['uid'], self::groupName($stat['gid']));

            $ok ? $this->checkOk('Inventory mode', $detail) : $this->checkFail('Inventory mode', $detail);
        }
    }

    private function checkAccount(PayPalAccount $account, Gateway $gateway, DailyBudget $budget): void
    {
        $account->environment === Environment::Live
            ? $this->checkOk('PayPal account', $account->name.' - live')
            : $this->checkOk('PayPal account', $account->name.' - sandbox, PayPal\'s test system');

        if (! $account->hasCredentials()) {
            $this->checkFail('REST app', 'not set - add client_id and secret under ['.$account->key.'] in the account inventory');

            return;
        }

        $this->checkOk('REST app', 'client id and secret set');

        $spent = $budget->spent($account);

        $spent >= $account->dailyBudget * self::BUDGET_WARN
            ? $this->checkWarn('Budget', sprintf('%d of %d used in the last 24 hours', $spent, $account->dailyBudget))
            : $this->checkOk('Budget', sprintf('%d of %d used in the last 24 hours', $spent, $account->dailyBudget));

        if ($this->option('offline')) {
            $this->checkSkip('PayPal', 'not reached - --offline');

            return;
        }

        try {
            $grant = $gateway->call($account, 0, static fn (Client $paypal) => $paypal->verify());
        } catch (PayPalCallException $e) {
            $this->checkFail('Token', $e->getMessage());

            return; // every other probe would fail the same way, and spend a request saying so
        }

        $this->checkOk('Token', 'issued to '.($grant->appId ?? 'an app with no id').', '.count($grant->scopes()).' scopes');

        $grant->canSearchTransactions()
            ? $this->checkOk('Transaction Search', 'in the token\'s scopes')
            : $this->checkFail('Transaction Search', 'not in the token\'s scopes - tick Transaction Search on the app in PayPal\'s developer dashboard; a cached token keeps its old scopes for up to nine hours');

        foreach ($this->probes() as $label => $probe) {
            $this->probe($gateway, $account, $label, $probe);
        }

        $this->checkOk('Requests', sprintf('%d of %d now used in the last 24 hours', $budget->spent($account), $account->dailyBudget));
    }

    /**
     * Every permission a tool needs, as the request that tool makes.
     *
     * @return array<string, Closure(Client): string>
     */
    private function probes(): array
    {
        return [
            // a day, a week back so the reporting lag cannot cut it, one row at most
            'Searches transactions' => static function (Client $paypal): string {
                $end = new \DateTimeImmutable('-7 days');
                $page = $paypal->transactions()->search($end->modify('-1 day'), $end, TransactionFilter::make()->onlyTransactionInfo(), 1, 1);

                return 'yes, reporting refreshed '.($page->lastRefreshedAt?->format(DATE_ATOM) ?? 'at an unstated time');
            },
            'Reads balances' => static fn (Client $paypal): string => implode(', ', array_filter(array_map(
                static fn ($balance): ?string => $balance->currency,
                $paypal->transactions()->balances()->balances,
            ))) ?: 'yes, no currency held',
            // an id no subscription has: "not found" means the app may read subscriptions. It must be
            // I- and twelve characters, as PayPal's are; a longer one is refused as malformed (sandbox,
            // 2026-11-21), which proves nothing about access
            'Reads subscriptions' => static function (Client $paypal): string {
                $paypal->subscriptions()->find('I-PAYPALPROBE0');

                return 'yes';
            },
        ];
    }

    /** @param  Closure(Client): string  $probe */
    private function probe(Gateway $gateway, PayPalAccount $account, string $label, Closure $probe): bool
    {
        try {
            $answer = $gateway->call($account, 1, $probe);
        } catch (PayPalCallException $e) {
            $this->checkFail($label, $e->getMessage());

            return false;
        }

        $this->checkOk($label, $answer);

        return true;
    }

    /**
     * Whether a file holding secrets is safe at this mode: 0600, or root-owned and readable by
     * one group - how a deployment renders it, so that the server cannot rewrite its own
     * configuration and the user it runs as reads it through the group. From xfmcp.
     *
     * @return array{0: bool, 1: string}
     */
    public static function fileModeVerdict(int $mode, int $owner, ?string $group = null): array
    {
        $perms = $mode & 0777;
        $shown = sprintf('%04o', $perms);

        if (($perms & 0007) !== 0) {
            return [false, $shown.' - readable by any user on this machine; it holds PayPal secrets, make it 0600'];
        }

        if (($perms & 0070) === 0) {
            return [true, $shown];
        }

        if ($owner === 0 && ($perms & 0070) === 0040) {
            return [true, $shown.' - owned by root, readable by group '.($group ?? 'of the file').' and no one else'];
        }

        return [false, $shown.' - readable beyond its owner; it holds PayPal secrets, make it 0600, or root-owned and 0640 for a group of the user who runs it'];
    }

    private static function groupName(int $gid): ?string
    {
        if (! function_exists('posix_getgrgid')) {
            return null;
        }

        $group = @posix_getgrgid($gid);

        return is_array($group) ? $group['name'] : null;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function followsWorkingDirectory(): bool
    {
        return $this->laravel->bound('paypalmcp.storage.follows_cwd')
            && $this->laravel->make('paypalmcp.storage.follows_cwd') === true;
    }
}
