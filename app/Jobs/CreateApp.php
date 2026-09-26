<?php

namespace App\Jobs;

use App\Enums\WwwMode;
use InvalidArgumentException;

/**
 * Runs `mandal app:create` with the options recorded on the task.
 */
class CreateApp extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];

        $name = (string) ($options['name'] ?? '');
        $domain = (string) ($options['domain'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        if (! self::isDomain($domain)) {
            throw new InvalidArgumentException("Invalid domain [{$domain}].");
        }

        $arguments = [
            'app:create',
            '--name='.$name,
            '--domain='.$domain,
            '--www='.WwwMode::from($options['www'] ?? WwwMode::default()->value)->value,
        ];

        foreach ((array) ($options['aliases'] ?? []) as $alias) {
            if (self::isDomain((string) $alias)) {
                $arguments[] = '--alias='.$alias;
            }
        }

        if (! empty($options['php']) && preg_match('/^\d+\.\d+$/', (string) $options['php'])) {
            $arguments[] = '--php='.$options['php'];
        }

        if (! empty($options['repo']) && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $options['repo'])) {
            $arguments[] = '--repo='.$options['repo'];
        }

        if (! empty($options['branch']) && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#', (string) $options['branch']) && ! str_contains((string) $options['branch'], '..')) {
            $arguments[] = '--branch='.$options['branch'];
        }

        if (array_key_exists('database', $options) && ! $options['database']) {
            $arguments[] = '--no-database';
        }

        return implode(' ', $arguments);
    }

    public static function isDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
    }
}
