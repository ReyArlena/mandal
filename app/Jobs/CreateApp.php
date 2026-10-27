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
        $docker = ($options['type'] ?? 'laravel') === 'docker';
        $proxy = $docker && ! empty($options['proxy']);

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:create', '--name='.$name];

        // A Docker app without the nginx proxy has no domain at all.
        if (! $docker || $proxy) {
            if (! self::isDomain($domain)) {
                throw new InvalidArgumentException("Invalid domain [{$domain}].");
            }

            $arguments[] = '--domain='.$domain;
            $arguments[] = '--www='.WwwMode::from($options['www'] ?? WwwMode::default()->value)->value;

            foreach ((array) ($options['aliases'] ?? []) as $alias) {
                if (self::isDomain((string) $alias)) {
                    $arguments[] = '--alias='.$alias;
                }
            }
        }

        if ($docker) {
            return implode(' ', [...$arguments, ...$this->dockerArguments($options, $proxy)]);
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
     * The compose file of a Docker app, or the deploy script of a Laravel
     * app, travels as an upload, never on the command line.
     */
    protected function upload(): ?array
    {
        $options = $this->task->options ?? [];

        if (($options['type'] ?? 'laravel') === 'docker') {
            return ['contents' => self::composeContents($options), 'extension' => '.yml'];
        }

        $script = rtrim((string) ($options['deploy_script'] ?? ''));

        if ($script === '' || str_contains($script, "\0")) {
            return null;
        }

        return ['contents' => $script."\n", 'extension' => '.sh'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function dockerArguments(array $options, bool $proxy): array
    {
        self::composeContents($options);

        $arguments = ['--type=docker'];

        if ($proxy) {
            $port = (string) ($options['port'] ?? '');

            if (! preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
                throw new InvalidArgumentException("Invalid proxy port [{$port}].");
            }

            $arguments[] = '--proxy';
            $arguments[] = '--port='.$port;
        }

        foreach ((array) ($options['env'] ?? []) as $pair) {
            if (is_string($pair) && preg_match('/^[A-Z][A-Z0-9_]*=[^\n]*$/', $pair)) {
                $arguments[] = '--env='.escapeshellarg($pair);
            }
        }

        return $arguments;
    }

    /**
     * The compose file from the task options, checked to be sane text.
     *
     * @param  array<string, mixed>  $options
     */
    public static function composeContents(array $options): string
    {
        $compose = rtrim((string) ($options['compose'] ?? ''))."\n";

        if (trim($compose) === '' || strlen($compose) > 262144 || str_contains($compose, "\0")) {
            throw new InvalidArgumentException('The compose file is empty, too large or not text.');
        }

        return $compose;
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
