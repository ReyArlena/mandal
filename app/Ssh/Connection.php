<?php

namespace App\Ssh;

/**
 * An authenticated SSH session with one server.
 */
interface Connection
{
    /**
     * Run a command and wait for it to finish.
     *
     * @param  (callable(string): void)|null  $onOutput  receives output as it arrives
     */
    public function run(string $command, ?callable $onOutput = null, int $timeoutSeconds = 60): CommandResult;

    /**
     * Run a command and hand every chunk of its output to the callback
     * without keeping any of it, for output too large to hold in memory.
     * The result's output is empty; only the exit code and stderr are kept.
     *
     * @param  callable(string): void  $onOutput
     */
    public function stream(string $command, callable $onOutput, int $timeoutSeconds = 60): CommandResult;

    /**
     * Write a file on the server, creating parent directories as needed.
     */
    public function upload(string $remotePath, string $contents, int $mode = 0644): void;

    public function disconnect(): void;
}
