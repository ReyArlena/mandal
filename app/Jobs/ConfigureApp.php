<?php

namespace App\Jobs;

use App\Enums\WwwMode;
use InvalidArgumentException;

/**
 * Runs `mandal app:configure` with the settings on the task: for now the
 * www handling of the app's domain.
 */
class ConfigureApp extends RunMandalTask
{
    public int $timeout = 900;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:configure', '--name='.$name, '--www='.WwwMode::from((string) ($options['www'] ?? ''))->value];

        if (! empty($options['skip_dns_check'])) {
            $arguments[] = '--skip-dns-check';
        }

        return implode(' ', $arguments);
    }
}
