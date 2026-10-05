<?php

namespace App\Jobs;

use App\Enums\SourceProviderType;
use App\Mandal\MandalClient;
use App\Sources\AutoDeploy;
use App\Sources\SourceProviders;
use InvalidArgumentException;
use Throwable;

/**
 * Runs `mandal app:delete` for the app named on the task, after removing
 * the app's deploy key and webhook from GitHub when a token allows it.
 */
class DeleteApp extends RunMandalTask
{
    public function handle(MandalClient $mandal): void
    {
        $this->releaseGitHub($mandal);

        parent::handle($mandal);
    }

    private function releaseGitHub(MandalClient $mandal): void
    {
        $name = (string) ($this->task->options['name'] ?? '');
        $server = $this->task->server;

        try {
            app(AutoDeploy::class)->disable($server, $name);

            $provider = app(SourceProviders::class)->for(SourceProviderType::GitHub);
            $app = collect($mandal->inventory($server)['apps'] ?? [])->firstWhere('name', $name);
            $repo = (string) ($app['repo'] ?? '');
            $key = (string) ($app['deploy_key'] ?? '');

            if ($provider !== null && $repo !== '' && $key !== '') {
                $provider->removeDeployKey($repo, $key);
                $this->task->appendOutput("Deploy key removed from {$repo} on GitHub.\n");
            }
        } catch (Throwable $exception) {
            $this->task->appendOutput("Could not tidy up on GitHub: {$exception->getMessage()}\n");
        }
    }

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
            throw new InvalidArgumentException("Invalid app name [{$name}].");
        }

        $arguments = ['app:delete', '--name='.$name];

        if (! empty($options['keep_database'])) {
            $arguments[] = '--keep-database';
        }

        if (! empty($options['keep_files'])) {
            $arguments[] = '--keep-files';
        }

        return implode(' ', $arguments);
    }
}
