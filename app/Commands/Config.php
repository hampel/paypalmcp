<?php

declare(strict_types=1);

namespace App\Commands;

use App\PayPal\TransactionHistory;
use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use App\Support\PayPalAccount;
use Hampel\ConsoleReport\FormatsValues;
use Hampel\ConsoleReport\ReportsSettings;
use LaravelZero\Framework\Commands\Command;

/**
 * What this installation is configured to do. Contacts nothing, and prints no credential.
 */
class Config extends Command
{
    use FormatsValues;
    use ReportsSettings;

    protected $signature = 'app:config {--only= : The section to display}';

    protected $description = 'Show application configuration';

    public function handle(AccountInventory $inventory): int
    {
        $this->setReportOutput($this->getOutput());

        $this->reportSettings($this->settings($inventory), $this->option('only'));

        return self::SUCCESS;
    }

    /**
     * The environment file that was read, or where one was looked for. bootstrap/app.php records
     * the outcome of its own search; environmentFilePath() would name a file whether or not it
     * exists.
     */
    private function environmentFile(): string
    {
        $loaded = $this->laravel->bound('paypalmcp.env.loaded') ? $this->laravel->make('paypalmcp.env.loaded') : null;

        if (is_string($loaded)) {
            return $this->path($loaded);
        }

        $candidates = $this->laravel->bound('paypalmcp.env.candidates') ? $this->laravel->make('paypalmcp.env.candidates') : [];

        return is_array($candidates) && $candidates !== []
            ? '<fg=yellow>none found</> - looked in '.implode(', ', $candidates)
            : $this->notSet();
    }

    /**
     * @return array<string, array<string, string>> settings to report, by section
     */
    private function settings(AccountInventory $inventory): array
    {
        $accounts = [];
        $error = null;

        try {
            $accounts = $inventory->all();
        } catch (AccountInventoryException $e) {
            $error = $e->getMessage();
        }

        $section = [
            'Inventory Path' => $this->path($inventory->path()),
            'Accounts Loaded' => $error !== null ? '<fg=red>'.$error.'</>' : count($accounts).' account(s)',
            'Default Daily Budget' => config('paypalmcp.daily_budget').' requests',
        ];

        foreach ($accounts as $account) {
            $section['Account: '.$account->key] = $this->describe($account);
        }

        return [
            'Application' => [
                'Name' => (string) config('app.name'),
                'Version' => (string) $this->app->version(),
                'Laravel Version' => $this->app::VERSION,
                'PHP Version' => (string) phpversion(),
                'Environment File' => $this->environmentFile(),
                // an MCP client starts this process from a directory of its choosing, and a
                // compiled binary resolves its storage path from it
                'Working Directory' => $this->path(getcwd() ?: ''),
            ],

            'MCP' => [
                'Server' => 'paypal (stdio only; nothing listens on a port)',
                'Start Command' => config('app.name').' mcp:start paypal',
                'Date Timezone' => (string) config('paypalmcp.timezone').' (the zone a bare date is read in)',
            ],

            'State' => [
                'State Path' => $this->path((string) config('paypalmcp.state_path')),
                'Months Kept' => sprintf('%d days, once %d hours past their end', TransactionHistory::CACHE_DAYS, TransactionHistory::CLOSED_AFTER_HOURS),
            ],

            'Accounts' => $section,
        ];
    }

    private function describe(PayPalAccount $account): string
    {
        return sprintf(
            '%s, %s, %d a day, app %s',
            $account->name,
            $account->environment->value,
            $account->dailyBudget,
            $account->hasCredentials() ? 'set' : '<fg=red>not set</>',
        );
    }
}
