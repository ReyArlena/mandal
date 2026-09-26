<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:deploy` for the app named on the task.
 */
class DeployApp extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:deploy', '--name='.$name];

        $branch = (string) ($options['branch'] ?? '');

        if ($branch !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#', $branch) && ! str_contains($branch, '..')) {
            $arguments[] = '--branch='.$branch;
        }

        $commit = (string) ($options['commit'] ?? '');

        if ($commit !== '' && preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            $arguments[] = '--commit='.$commit;
        }

        return implode(' ', $arguments);
    }
}
