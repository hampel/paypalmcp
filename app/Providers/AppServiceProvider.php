<?php

declare(strict_types=1);

namespace App\Providers;

use App\PayPal\DailyBudget;
use App\PayPal\Gateway;
use App\PayPal\MeteredClient;
use App\PayPal\TransactionHistory;
use App\Support\AccountInventory;
use Hampel\PayPal\Api\Laravel\Http\PendingRequestClient;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        // one inventory per process, however many tools ask; it re-reads the file when it changes
        $this->app->singleton(AccountInventory::class);

        // the count lives in the state directory's store, shared by every server process - never
        // the default store, which a test or a misconfiguration could make `array`
        $this->app->singleton(DailyBudget::class, static fn ($app): DailyBudget => new DailyBudget(
            $app->make(CacheFactory::class)->store('paypalmcp'),
        ));

        $this->app->singleton(TransactionHistory::class, static fn ($app): TransactionHistory => new TransactionHistory(
            $app->make(CacheFactory::class)->store('paypalmcp'),
        ));

        // The transport every PayPal client sends through: the package's own Laravel adapter, so
        // Http::fake() sees each request, inside the meter that counts them. Bound under the
        // package's key before its provider registers, so its singletonIf() keeps this one - the
        // order config/app.php lists them in. The factory is resolved per send, as the package's
        // own binding does, so Http::fake() after boot is still seen.
        $this->app->singleton(MeteredClient::class, static fn ($app): MeteredClient => new MeteredClient(new PendingRequestClient(
            static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
            (float) config('paypal.timeout', 30),
            (float) config('paypal.connect_timeout', 5),
        )));

        $this->app->singleton(PayPalServiceProvider::HTTP_CLIENT, static fn ($app): MeteredClient => $app->make(MeteredClient::class));

        // one per process, so the request count answers for the tool call in progress
        $this->app->singleton(Gateway::class);
    }
}
