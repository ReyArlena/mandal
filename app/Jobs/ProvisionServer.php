<?php

namespace App\Jobs;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\JsDriver;
use App\Mandal\MandalClient;
use App\Models\Task;
use App\Ssh\SshException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Throwable;

/**
 * Runs `mandal provision` on a server, streaming its output into the task.
 */
class ProvisionServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        #[WithoutRelations]
        public Task $task,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->task->server_id;
    }

    public function handle(MandalClient $mandal): void
    {
        $task = $this->task;
        $server = $task->server;

        $task->markRunning();

        try {
            $result = $mandal->run(
                $server,
                $this->arguments(),
                onOutput: fn (string $chunk) => $task->appendOutput($chunk),
                timeoutSeconds: $this->timeout - 60,
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

        if ($result->successful()) {
            $server->forceFill(['provisioned_at' => now()])->save();
            $mandal->forgetInventory($server);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->appendOutput("\nerror: ".($exception?->getMessage() ?? 'the job failed')."\n");
        $this->task->markFinished(255);
    }

    /**
     * The `mandal provision` arguments for the task's options.
     */
    public function arguments(): string
    {
        $options = $this->task->options ?? [];

        $arguments = [
            'provision',
            '--database='.DatabaseDriver::from($options['database'] ?? DatabaseDriver::default()->value)->value,
            '--cache='.CacheDriver::from($options['cache'] ?? CacheDriver::default()->value)->value,
            '--js='.JsDriver::from($options['js'] ?? JsDriver::default()->value)->value,
        ];

        if (! empty($options['php']) && preg_match('/^\d+\.\d+$/', (string) $options['php'])) {
            $arguments[] = '--php='.$options['php'];
        }

        if (! empty($options['skip_ssh_hardening'])) {
            $arguments[] = '--skip-ssh-hardening';
        }

        return implode(' ', $arguments);
    }
}
