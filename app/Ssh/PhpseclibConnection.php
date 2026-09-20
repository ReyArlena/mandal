<?php

namespace App\Ssh;

use phpseclib3\Net\SFTP;

class PhpseclibConnection implements Connection
{
    public function __construct(
        private readonly SFTP $session,
    ) {}

    public function run(string $command, ?callable $onOutput = null, int $timeoutSeconds = 60): CommandResult
    {
        $this->session->setTimeout($timeoutSeconds);
        $this->session->enableQuietMode();

        $output = '';
        $result = $this->session->exec($command, function (string $chunk) use (&$output, $onOutput): void {
            $output .= $chunk;

            if ($onOutput !== null) {
                $onOutput($chunk);
            }
        });

        if ($this->session->isTimeout()) {
            throw new SshException("The command [{$command}] did not finish within {$timeoutSeconds} seconds.");
        }

        if ($result === false) {
            throw new SshException("The command [{$command}] could not be started: ".($this->session->getLastError() ?: 'unknown error'));
        }

        $exitCode = $this->session->getExitStatus();
        $errorOutput = (string) $this->session->getStdError();

        return new CommandResult(
            command: $command,
            exitCode: is_int($exitCode) ? $exitCode : 1,
            output: $output,
            errorOutput: $errorOutput,
        );
    }

    public function upload(string $remotePath, string $contents, int $mode = 0644): void
    {
        $directory = dirname($remotePath);

        if ($directory !== '.' && ! $this->session->is_dir($directory)) {
            $this->session->mkdir($directory, -1, true);
        }

        if (! $this->session->put($remotePath, $contents)) {
            throw new SshException("Could not write {$remotePath}: ".($this->session->getLastSFTPError() ?: 'unknown error'));
        }

        $this->session->chmod($mode, $remotePath);
    }

    public function disconnect(): void
    {
        $this->session->disconnect();
    }
}
