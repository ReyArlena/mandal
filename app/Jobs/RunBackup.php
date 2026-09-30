<?php

namespace App\Jobs;

/**
 * Runs `mandal backup:run`, for every database or one of them.
 */
class RunBackup extends RunMandalTask
{
    public int $timeout = 7200;

    public function arguments(): string
    {
        $database = (string) ($this->task->options['database'] ?? '');

        return $database !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $database)
            ? 'backup:run --database='.$database
            : 'backup:run';
    }
}
