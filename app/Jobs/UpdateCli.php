<?php

namespace App\Jobs;

use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Ssh\SshException;

/**
 * Uploads the panel's CLI archive to the server and installs it.
 */
class UpdateCli extends RunMandalTask
{
    public function arguments(): string
    {
        return 'cli:update';
    }

    public function handle(MandalClient $mandal): void
    {
        $task = $this->task;
        $server = $task->server;

        $task->markRunning();

        try {
            $archive = app(CliArchive::class);
            $task->appendOutput("Uploading mandal {$archive->version()} (build {$archive->hash()})\n");

            $result = $mandal->runWithUpload(
                $server,
                $this->arguments(),
                (string) file_get_contents($archive->path()),
                extension: '.tar.gz',
                onOutput: fn (string $chunk) => $task->appendOutput($chunk),
                timeoutSeconds: 300,
            );
        } catch (SshException $exception) {
            $task->appendOutput("\nerror: {$exception->getMessage()}\n");
            $task->markFinished(255);

            return;
        }

        if ($result->errorOutput !== '' && ! str_contains($task->output, $result->errorOutput)) {
            $task->appendOutput($result->errorOutput);
        }

        $task->markFinished($result->exitCode);
        $mandal->forgetInventory($server);
    }
}
