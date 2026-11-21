<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The state directory: cached PayPal tokens, each account's request count, the months of
 * transactions already read, and by default the account inventory.
 *
 * Created 0700 before anything is written into it, because the cached tokens are credentials, the
 * months hold payers' names and emails, and the file cache store cannot protect them itself. Laravel's FileStore applies its `permission`
 * setting to the directories it creates as well as to the files, so 0600 there makes every cache
 * directory impossible to enter - measured by saasusync on 2026-11-18, "Permission denied" on the
 * first token write. What is inside a 0700 directory is unreachable to anyone else whatever its own
 * mode.
 */
final class StateDirectory
{
    public static function path(): string
    {
        return (string) config('paypalmcp.state_path');
    }

    public static function ensure(): void
    {
        $path = self::path();

        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new \RuntimeException('cannot create the state directory '.$path);
        }

        chmod($path, 0700);
    }
}
