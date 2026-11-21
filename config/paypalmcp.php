<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| paypalmcp
|--------------------------------------------------------------------------
|
| The state directory holds the cached PayPal tokens, the daily request count for each account,
| the months of transactions already read and, by default, the account inventory with its
| secrets. The default is the storage path, which from a checkout is the project's storage/ and
| inside a compiled binary is the CURRENT WORKING DIRECTORY - which an MCP client chooses. So a
| deployment sets PAYPALMCP_STATE_PATH absolutely, and app:validate fails a binary running on the
| derived one.
|
*/

$state = require base_path('bootstrap/state.php');

return [

    'state_path' => $state,

    // whether PAYPALMCP_STATE_PATH was set, rather than derived - app:validate needs to know
    'state_path_set' => (bool) env('PAYPALMCP_STATE_PATH'),

    // the inventory, which holds each account's REST app - see accounts.toml.example
    'accounts_path' => env('PAYPALMCP_ACCOUNTS_PATH') ?: $state.'/accounts.toml',

    /*
     * Requests this server may send to one PayPal account in any 24 hours, unless the account's
     * entry in the inventory sets its own `daily_budget`. PayPal publishes no limit; this one bounds
     * a runaway conversation on a credential that can also move money. Three years of history is
     * about 37 requests, so the default allows a dozen of those a day.
     */
    'daily_budget' => (int) env('PAYPALMCP_DAILY_BUDGET', 500),

    // The zone a bare date - "2026-03-01" - is read in, as the whole of that day. Times PayPal
    // reports are passed on with their own offset and never converted.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

];
