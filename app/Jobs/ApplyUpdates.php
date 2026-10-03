<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal updates:apply` or `component:update` as recorded on the task.
 */
class ApplyUpdates extends RunMandalTask
{
    public int $timeout = 3600;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $mode = (string) ($options['mode'] ?? '');

        return match ($mode) {
            'security' => 'updates:apply --security',
            'all' => 'updates:apply --all',
            'package' => $this->package((string) ($options['package'] ?? '')),
            'component' => $this->component((string) ($options['component'] ?? '')),
            default => throw new InvalidArgumentException("Unknown update mode [{$mode}]."),
        };
    }

    private function package(string $package): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9+.-]{0,99}$/', $package)) {
            throw new InvalidArgumentException("Invalid package name [{$package}].");
        }

        return 'updates:apply --package='.$package;
    }

    private function component(string $component): string
    {
        if (! in_array($component, ['cache', 'js', 'composer', 'docker'], true)) {
            throw new InvalidArgumentException("Invalid component [{$component}].");
        }

        return 'component:update --component='.$component;
    }
}
