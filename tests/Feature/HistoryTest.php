<?php

use App\Mcp\Tools\FindPayer;
use App\Mcp\Tools\SearchTransactions;
use Illuminate\Support\Carbon;

it('reads a closed month whole, and answers it from the cache the second time', function () {
    Carbon::setTestNow('2026-12-10T00:00:00Z');
    fakeSearch(fixtureRows());

    $first = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-17', 'to' => '2026-11-18']);
    $sent = count(dataRequests());
    $second = answer(FindPayer::class, ['account' => 'acme', 'from' => '2026-11-01', 'to' => '2026-11-30', 'query' => 'buyer@example.com']);

    expect($first['matched'])->toBe(12)
        ->and($sent)->toBe(1)
        ->and(dataRequests()[0]->url())->toContain('start_date=2026-11-01T00%3A00%3A00Z')->toContain('end_date=2026-11-30T23%3A59%3A59Z')
        ->and(count(dataRequests()))->toBe(1)
        ->and($second['coverage'])->toMatchArray(['months_read' => 0, 'months_from_cache' => 1])
        ->and($second['budget']['requests_this_call'])->toBe(0);
});

it('never keeps a month that is still open', function () {
    // November ended a day ago: inside the three days a row may still settle
    Carbon::setTestNow('2026-12-01T12:00:00Z');
    fakeSearch(fixtureRows());

    answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-15', 'to' => '2026-11-20']);
    answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-15', 'to' => '2026-11-20']);

    expect(dataRequests())->toHaveCount(2)
        ->and(dataRequests()[0]->url())->toContain('start_date=2026-11-15');
});

it('keeps no address or phone number in the cache', function () {
    Carbon::setTestNow('2026-12-10T00:00:00Z');
    fakeSearch(fixtureRows());

    answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01', 'to' => '2026-11-30']);

    $store = app('cache')->store('paypalmcp')->getStore();
    $kept = json_encode((fn () => $this->storage)->call($store));

    expect($kept)->toContain('buyer@example.com')->not->toContain('Example Street')->not->toContain('4085550100');
});

it('walks newest first, and stops at a month boundary when the allowance runs out', function () {
    fakeSearch([rowAt('2026-11-02T00:00:00Z', 'NOV1'), rowAt('2026-10-02T00:00:00Z', 'OCT1'), rowAt('2026-08-02T00:00:00Z', 'AUG1')]);

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-06-01', 'max_requests' => 2]);

    expect(array_column($answer['transactions'], 'transaction_id'))->toBe(['NOV1', 'OCT1'])
        ->and($answer['coverage'])->toMatchArray(['complete' => false])
        ->and($answer['coverage']['covered']['from'])->toBe('2026-10-01T00:00:00+00:00')
        ->and($answer['coverage']['note'])->toContain('allowance ran out')
        ->and(dataRequests())->toHaveCount(2);
});

it('does not pay for a month it could not finish', function () {
    fakeSearch([], extra: ['v1/reporting/transactions*' => Http::response(searchPage([rowAt('2026-11-02T00:00:00Z', 'NOV1')], totalPages: 5, endDate: '2026-11-20T23:59:59Z'))]);

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-01', 'max_requests' => 3]);

    expect($answer['matched'])->toBe(0)
        ->and($answer['coverage']['covered'])->toBe(['from' => null, 'to' => null])
        ->and(dataRequests())->toHaveCount(1);
});

it('starts a range older than three years at the start of PayPal\'s history', function () {
    fakeSearch([]);

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2020-01-01', 'to' => '2024-01-31']);

    expect($answer['coverage']['note'])->toContain('three years')
        // newest first, so the oldest search is the last one sent
        ->and(last(dataRequests())->url())->toContain('start_date=2023-11-22');
});

it('reads a bare date in the configured zone', function () {
    config(['paypalmcp.timezone' => 'Australia/Sydney']);
    fakeSearch([]);

    $answer = answer(SearchTransactions::class, ['account' => 'acme', 'from' => '2026-11-18', 'to' => '2026-11-18']);

    expect($answer['coverage']['asked'])->toBe(['from' => '2026-11-18T00:00:00+11:00', 'to' => '2026-11-18T23:59:59+11:00'])
        ->and(dataRequests()[0]->url())->toContain('start_date=2026-11-17T13%3A00%3A00Z');
});
