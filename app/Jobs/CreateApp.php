<?php

namespace App\Jobs;

use App\Enums\SourceProviderType;
use App\Enums\WwwMode;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Sources\SourceProviders;
use App\Ssh\CommandResult;
use InvalidArgumentException;
use Throwable;

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

        if (($options['type'] ?? 'laravel') === 'docker') {
            return implode(' ', [...$arguments, ...$this->dockerArguments($options)]);
        }

        if (! empty($options['php']) && preg_match('/^\d+\.\d+$/', (string) $options['php'])) {
            $arguments[] = '--php='.$options['php'];
        }

        if (! empty($options['repo']) && preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$#', (string) $options['repo'])) {
            $arguments[] = '--repo='.$options['repo'];
        }

        if (! empty($options['branch']) && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#', (string) $options['branch']) && ! str_contains((string) $options['branch'], '..')) {
            $arguments[] = '--branch='.$options['branch'];
        }

        if (! empty($options['https'])) {
            $arguments[] = '--https';
        }

        if (array_key_exists('database', $options) && ! $options['database']) {
            $arguments[] = '--no-database';
        }

        return implode(' ', $arguments);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function dockerArguments(array $options): array
    {
        $image = (string) ($options['image'] ?? '');
        $port = (string) ($options['port'] ?? '');

        if (! preg_match('#^[a-z0-9]([a-z0-9._/-]*[a-z0-9])?(:[A-Za-z0-9._-]{1,128})?(@sha256:[0-9a-f]{64})?$#', $image)) {
            throw new InvalidArgumentException("Invalid image [{$image}].");
        }

        if (! preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException("Invalid container port [{$port}].");
        }

        $arguments = ['--type=docker', '--image='.$image, '--port='.$port];

        foreach ((array) ($options['env'] ?? []) as $pair) {
            if (is_string($pair) && preg_match('/^[A-Z][A-Z0-9_]*=[^\n]*$/', $pair)) {
                $arguments[] = '--env='.escapeshellarg($pair);
            }
        }

        return $arguments;
    }

    /**
     * Register the app's deploy key with GitHub when a token is available.
     */
    protected function succeeded(Server $server, CommandResult $result): void
    {
        $options = $this->task->options ?? [];
        $repo = (string) ($options['repo'] ?? '');

        if ($repo === '' || ! empty($options['https']) || ($options['type'] ?? 'laravel') === 'docker') {
            return;
        }

        $provider = app(SourceProviders::class)->for(SourceProviderType::GitHub);

        if ($provider === null) {
            $this->task->appendOutput("\nNo GitHub token is connected; add the deploy key to {$repo} by hand before deploying.\n");

            return;
        }

        try {
            $apps = app(MandalClient::class)->inventory($server, fresh: true)['apps'] ?? [];
            $key = collect($apps)->firstWhere('name', $options['name'])['deploy_key'] ?? null;

            if (! is_string($key) || $key === '') {
                throw new \RuntimeException('the server did not report a deploy key');
            }

            $provider->addDeployKey($repo, "mandal {$options['name']}@{$server->name}", $key);
            $this->task->appendOutput("\nDeploy key added to {$repo} on GitHub.\n");
        } catch (Throwable $exception) {
            $this->task->appendOutput("\nCould not add the deploy key to {$repo}: {$exception->getMessage()}. Add it by hand before deploying.\n");
        }
    }

    public static function isDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
    }
}
