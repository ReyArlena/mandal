<?php

namespace Tests\Support;

use App\Models\Server;
use App\Ssh\CommandResult;
use App\Ssh\Connection;
use App\Ssh\HostKeyVerifier;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Closure;

/**
 * An in-memory SSH client for tests. Commands are matched by substring
 * against the responses registered with {@see respondTo()}.
 */
class FakeSshClient implements Connection, SshClient
{
    public const string HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 fake';

    /** @var array<string, CommandResult|Closure(string): CommandResult> */
    private array $responses = [];

    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, array{contents: string, mode: int}> */
    public array $uploads = [];

    public ?SshException $connectionFailure = null;

    public bool $authenticationFails = false;

    public function __construct(
        private readonly HostKeyVerifier $hostKeys = new HostKeyVerifier,
        public string $hostKey = self::HOST_KEY,
    ) {}

    public function respondTo(string $commandFragment, string $output, int $exitCode = 0, string $errorOutput = ''): static
    {
        $this->responses[$commandFragment] = fn (string $command): CommandResult => new CommandResult($command, $exitCode, $output, $errorOutput);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function respondWithJson(string $commandFragment, array $data): static
    {
        return $this->respondTo($commandFragment, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function connect(Server $server, bool $trustUnknownHostKey = false): Connection
    {
        if ($this->connectionFailure !== null) {
            throw $this->connectionFailure;
        }

        $this->hostKeys->verify($server, $this->hostKey, $trustUnknownHostKey);

        if ($this->authenticationFails) {
            throw new SshException("The server {$server->host} rejected the panel's key for user {$server->ssh_user}.");
        }

        if (! $server->hasPinnedHostKey()) {
            $server->forceFill(['host_key' => $this->hostKey])->save();
        }

        return $this;
    }

    public function fetchHostKey(Server $server): string
    {
        if ($this->connectionFailure !== null) {
            throw $this->connectionFailure;
        }

        return $this->hostKey;
    }

    public function run(string $command, ?callable $onOutput = null, int $timeoutSeconds = 60): CommandResult
    {
        $this->commands[] = $command;

        $fragments = array_filter(array_keys($this->responses), fn (string $fragment): bool => str_contains($command, $fragment));
        usort($fragments, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($fragments as $fragment) {
            $result = ($this->responses[$fragment])($command);

            if ($onOutput !== null && $result->output !== '') {
                $onOutput($result->output);
            }

            return $result;
        }

        return new CommandResult($command, 127, '', "mandal: unknown command [{$command}]");
    }

    public function upload(string $remotePath, string $contents, int $mode = 0644): void
    {
        $this->uploads[$remotePath] = ['contents' => $contents, 'mode' => $mode];
    }

    public function disconnect(): void {}

    public function ran(string $commandFragment): bool
    {
        foreach ($this->commands as $command) {
            if (str_contains($command, $commandFragment)) {
                return true;
            }
        }

        return false;
    }
}
