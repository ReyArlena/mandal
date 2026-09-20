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
     * Write a file on the server, creating parent directories as needed.
     */
    public function upload(string $remotePath, string $contents, int $mode = 0644): void;

    public function disconnect(): void;
}
