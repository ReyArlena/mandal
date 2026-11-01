<?php

namespace App\Jobs;

use App\DetailsPages\GameStatuses;
use App\Mandal\ComposeImport;
use App\Mandal\InvalidImport;
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

    /** @var array<string, int> */
    private array $uploadSteps = [];

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

    /**
     * A file to upload before the command runs; the command then receives
     * its path as --from=<path>.
     *
     * @return array{contents: string, extension: string}|null
     */
    protected function upload(): ?array
    {
        return null;
    }

    /**
     * Local files to copy next to the upload, each passed to the command
     * under its own option: `['--files' => '/path/x.tar.gz']`. Called once
     * the task is running, so it can report progress.
     *
     * @return array<string, string>
     */
    protected function files(): array
    {
        return [];
    }

    /**
     * Runs once the task has finished, whatever the outcome.
     */
    protected function cleanUp(): void {}

    protected function succeeded(Server $server, CommandResult $result): void {}

    public function handle(MandalClient $mandal): void
    {
        $this->task->markRunning();

        try {
            $this->runCommand($mandal);
        } finally {
            $this->cleanUp();
        }
    }

    private function runCommand(MandalClient $mandal): void
    {
        $task = $this->task;
        $server = $task->server;

        try {
            $upload = $this->upload();
            $files = $this->files();

            $result = $upload === null
                ? $mandal->run(
                    $server,
                    $this->arguments(),
                    onOutput: fn (string $chunk) => $task->appendOutput($chunk),
                    timeoutSeconds: $this->timeout - 60,
                    mergeErrorOutput: true,
                )
                : $mandal->runWithUpload(
                    $server,
                    $this->arguments(),
                    $upload['contents'],
                    $upload['extension'],
                    onOutput: fn (string $chunk) => $task->appendOutput($chunk),
                    timeoutSeconds: $this->timeout - 60,
                    files: $files,
                    onUpload: $this->reportUpload(...),
                    mergeErrorOutput: true,
                );
        } catch (SshException|InvalidImport $exception) {
            $task->appendOutput("\nerror: {$exception->getMessage()}\n");
            $task->markFinished(255);

            return;
        }

        if ($result->errorOutput !== '' && ! str_contains($task->output, $result->errorOutput)) {
            $task->appendOutput($result->errorOutput);
        }

        $task->markFinished($result->exitCode);
        $mandal->forgetInventory($server);
        app(GameStatuses::class)->refresh($server);

        if ($result->successful()) {
            $this->succeeded($server, $result);
        }
    }

    /**
     * One line per tenth of each file copied, so a large upload shows it is
     * moving.
     */
    private function reportUpload(string $option, int $sent, int $total): void
    {
        $step = $total > 0 ? intdiv($sent * 10, $total) : 10;

        if ($step > ($this->uploadSteps[$option] ?? -1)) {
            $this->uploadSteps[$option] = $step;
            $this->task->appendOutput(sprintf("Uploading: %d%% (%s of %s)\n", $step * 10, ComposeImport::human($sent), ComposeImport::human($total)));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->cleanUp();

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
