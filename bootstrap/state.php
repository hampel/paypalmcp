<?php

declare(strict_types=1);

/*
 * The state directory, worked out once for the config files that need it.
 *
 * A file of its own because config files load in alphabetical order: cache.php is read before
 * paypalmcp.php, so it cannot ask config() for a value it has not loaded yet.
 * config/paypalmcp.php explains the default.
 */

return rtrim((string) (env('PAYPALMCP_STATE_PATH') ?: storage_path()), '/');
