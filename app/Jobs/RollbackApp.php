<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:rollback` for the app named on the task.
 */
class RollbackApp extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:rollback', '--name='.$name];

        $release = (string) ($options['release'] ?? '');

        if ($release !== '' && preg_match('/^\d{14}$/', $release)) {
            $arguments[] = '--release='.$release;
        }

        return implode(' ', $arguments);
    }
}
