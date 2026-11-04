<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal panel:update` on the server hosting the panel, pointed at
 * a release tag or a branch. The deploy restarts the worker running this
 * job once the command has returned, so the task still finishes.
 */
class UpdatePanel extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $version = (string) ($options['version'] ?? '');
        $branch = (string) ($options['branch'] ?? '');

        if (preg_match('/^v\d+\.\d+\.\d+$/', $version)) {
            return "panel:update --version={$version}";
        }

        if ($branch !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#', $branch) && ! str_contains($branch, '..')) {
            return "panel:update --branch={$branch}";
        }

        throw new InvalidArgumentException('A panel update needs a release tag or a branch.');
    }
}
