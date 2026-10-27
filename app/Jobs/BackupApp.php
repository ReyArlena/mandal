<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:backup` for one app, to every destination it has.
 */
class BackupApp extends RunMandalTask
{
    public int $timeout = 7200;

    public function arguments(): string
    {
        $name = (string) ($this->task->options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        return 'app:backup --name='.$name;
    }
}
