<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal php:install` or `php:remove` for the version on the task.
 */
class ManagePhp extends RunMandalTask
{
    public int $timeout = 1800;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $version = (string) ($options['version'] ?? '');
        $mode = (string) ($options['mode'] ?? '');

        if (! preg_match('/^\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException("Invalid PHP version [{$version}].");
        }

        return match ($mode) {
            'install' => 'php:install --version='.$version,
            'remove' => 'php:remove --version='.$version,
            default => throw new InvalidArgumentException("Unknown PHP action [{$mode}]."),
        };
    }
}
