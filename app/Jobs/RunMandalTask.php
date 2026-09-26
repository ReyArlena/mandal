<?php

namespace App\Jobs;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\CommandResult;
use App\Ssh\SshException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Throwable;

/**
 * Runs one mandal command on a server, streaming its output into a task.
 * Subclasses provide the arguments and react to a successful run.
 */
abstract class RunMandalTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        #[WithoutRelations]
        public Task $task,
    ) {}

    /**
     * One mandal command per server at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->task->server_id;
    }

    /**
     * The arguments passed to the mandal CLI.
     */
    abstract public function arguments(): string;

    protected function succeeded(Server $server, CommandResult $result): void {}

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
        $mandal->forgetInventory($server);

        if ($result->successful()) {
            $this->succeeded($server, $result);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->appendOutput("\nerror: ".($exception?->getMessage() ?? 'the job failed')."\n");
        $this->task->markFinished(255);
    }

    /**
     * Shell-quote a value for the command line.
     */
    protected function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
