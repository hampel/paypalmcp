<?php

declare(strict_types=1);

/*
 * hampel/paypal-api-laravel's settings, replaced whole rather than merged into: an application
 * file of this name wins key by key, so every key the package reads is set here.
 *
 * NO CONNECTIONS ARE LISTED. One is registered per PayPal account at run time, from the account
 * inventory, by App\PayPal\Gateway - the accounts are only known once the inventory has been read.
 */
return [

    'default' => 'unused',

    'connections' => [],

    // the state directory's store: a cached token reads an account's whole history for nine hours,
    // and that directory is 0700
    'cache_store' => 'paypalmcp',

    // PayPal's maximum, and the package's default: every page is a request against the budget
    'page_size' => null,

    'timeout' => env('PAYPAL_TIMEOUT', 30),

    'connect_timeout' => env('PAYPAL_CONNECT_TIMEOUT', 5),

];
