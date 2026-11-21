<?php

use App\PayPal\BudgetSpentException;
use App\PayPal\DailyBudget;
use App\Support\PayPalAccount;
use Hampel\PayPal\Api\Environment;

function budgetAt(int &$now): DailyBudget
{
    return new DailyBudget(app('cache')->store('paypalmcp'), function () use (&$now): int {
        return $now;
    });
}

function accountWithBudget(int $budget, string $clientId = 'client-acme', string $key = 'acme'): PayPalAccount
{
    return new PayPalAccount($key, 'Acme', Environment::Sandbox, $budget, $clientId, 's');
}

it('counts what a call reserved, less what it gave back', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $account = accountWithBudget(10);

    $reservation = $budget->reserve($account, 4);
    expect($budget->spent($account))->toBe(4);

    $budget->settle($reservation, 1);
    expect($budget->spent($account))->toBe(1);
});

it('refuses a reservation that does not fit, and counts nothing for it', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $account = accountWithBudget(10);

    $budget->reserve($account, 8);

    expect(fn () => $budget->reserve($account, 3))->toThrow(BudgetSpentException::class)
        ->and($budget->spent($account))->toBe(8);
});

it('frees an hour only once all of it has left the window', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $account = accountWithBudget(10);
    $hour = intdiv($now, 3600) * 3600;

    $budget->reserve($account, 10);

    try {
        $budget->reserve($account, 1);
    } catch (BudgetSpentException $e) {
        expect($e->freesAt)->toBe($hour + 25 * 3600);
    }

    $now = $hour + 25 * 3600 - 1;
    expect($budget->spent($account))->toBe(10);

    $now = $hour + 25 * 3600;
    expect($budget->spent($account))->toBe(0);
});

it('keeps one count per app, whatever the account is called', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);

    $budget->reserve(accountWithBudget(10, 'one'), 9);

    expect($budget->spent(accountWithBudget(10, 'two')))->toBe(0)
        ->and($budget->spent(accountWithBudget(10, 'one', 'renamed')))->toBe(9);
});

it('never gives back more than was reserved, nor counts below zero', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $account = accountWithBudget(10);

    $reservation = $budget->reserve($account, 3);
    $budget->settle($reservation, 7);

    expect($budget->spent($account))->toBe(3);
});

it('survives the process, because the count is in the cache store', function () {
    $now = 1_800_000_000;
    budgetAt($now)->reserve(accountWithBudget(10), 6);

    expect(budgetAt($now)->spent(accountWithBudget(10)))->toBe(6);
});
