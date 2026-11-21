<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\DailyBudget;
use App\Support\AccountInventory;
use App\Support\AccountInventoryException;
use App\Support\PayPalAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the PayPal accounts this server can read. Call this first: every other tool takes an account key from here.

    Each is one PayPal business account, read through a REST app made for this server. This reads the server's own
    list and contacts nobody, so it costs nothing. It says what is CONFIGURED, not what works: `credentials_configured`
    means an app is set, not that PayPal accepts it. account-access asks PayPal. `environment` is live for a real
    account and sandbox for PayPal's test system, whose payers and money are not real.

    `used_last_24h` and `daily_budget` are this server's own limit on requests to the account in any 24 hours.
    Check them before a long search.
    TEXT)]
#[IsReadOnly]
class ListAccounts extends Tool
{
    public function handle(Request $request, AccountInventory $inventory, DailyBudget $budget): Response|ResponseFactory
    {
        try {
            $accounts = $inventory->all();
        } catch (AccountInventoryException $e) {
            return Response::error(
                'The account inventory cannot be read: '.$e->getMessage().'. Run `paypalmcp app:validate` on the machine this server runs on.'
            );
        }

        return Response::structured([
            'count' => count($accounts),
            'accounts' => array_values(array_map(static fn (PayPalAccount $account): array => [
                ...$account->describe(),
                'used_last_24h' => $budget->spent($account),
            ], $accounts)),
        ]);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()->required(),
            'accounts' => $schema->array()->description('One entry per account: account (the key), name, environment, daily_budget, used_last_24h, credentials_configured.')->required(),
        ];
    }
}
