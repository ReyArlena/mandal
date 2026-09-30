<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal backup:restore` into a fresh database.
 */
class RestoreBackup extends RunMandalTask
{
    public int $timeout = 7200;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $database = (string) ($options['database'] ?? '');
        $file = (string) ($options['file'] ?? '');

        if (! preg_match('/^[a-z0-9_]{1,64}$/', $database) || ! preg_match('/^[a-z0-9_]+-\d{14}\.sql\.zst$/', $file)) {
            throw new InvalidArgumentException('Invalid backup to restore.');
        }

        $arguments = ['backup:restore', '--database='.$database, '--file='.$file];

        $into = (string) ($options['into'] ?? '');

        if ($into !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $into)) {
            $arguments[] = '--into='.$into;
        }

        return implode(' ', $arguments);
    }
}
