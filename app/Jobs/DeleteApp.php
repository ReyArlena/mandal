<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:delete` for the app named on the task.
 */
class DeleteApp extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:delete', '--name='.$name];

        if (! empty($options['keep_database'])) {
            $arguments[] = '--keep-database';
        }

        if (! empty($options['keep_files'])) {
            $arguments[] = '--keep-files';
        }

        return implode(' ', $arguments);
    }
}
