<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Runs `mandal app:ssl` for the app named on the task.
 */
class RequestCertificate extends RunMandalTask
{
    public int $timeout = 900;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:ssl', '--name='.$name];

        $email = (string) ($options['email'] ?? '');

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $arguments[] = '--email='.escapeshellarg($email);
        }

        if (! empty($options['skip_dns_check'])) {
            $arguments[] = '--skip-dns-check';
        }

        return implode(' ', $arguments);
    }
}
