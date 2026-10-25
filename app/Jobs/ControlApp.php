<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:control` to start, stop or restart a Docker app.
 */
class ControlApp extends RunMandalTask
{
    public const array ACTIONS = ['start', 'stop', 'restart'];

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');
        $action = (string) ($options['action'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid action [{$action}].");
        }

        return "app:control --name={$name} --action={$action}";
    }
}
