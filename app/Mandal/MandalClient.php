<?php

namespace App\Mandal;

use App\Models\Server;
use App\Ssh\CommandResult;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Runs the mandal CLI on a server over SSH and returns its JSON output.
 */
class MandalClient
{
    public const string BINARY = '/opt/mandal/bin/mandal';

    /**
     * Where files for the CLI are uploaded before a command consumes them.
     */
    public const string UPLOAD_DIR = '.mandal';

    /**
     * How long inventory reads are cached per server, in seconds.
     */
    public const int INVENTORY_TTL = 20;

    public function __construct(
        private readonly SshClient $ssh,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function version(Server $server): array
    {
        return $this->json($server, 'version --json', asRoot: false, timeoutSeconds: 15);
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(Server $server, bool $fresh = false): array
    {
        $key = "servers.{$server->getKey()}.inventory";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::INVENTORY_TTL, fn (): array => $this->json($server, 'inventory'));
    }

    public function forgetInventory(Server $server): void
    {
        Cache::forget("servers.{$server->getKey()}.inventory");
    }

    /**
     * @return array<string, mixed>
     */
    public function provisionCheck(Server $server): array
    {
        return $this->json($server, 'provision --check', timeoutSeconds: 120);
    }

    /**
     * Run a mandal command and return the raw result.
     *
     * @param  (callable(string): void)|null  $onOutput
     */
    public function run(Server $server, string $arguments, ?callable $onOutput = null, bool $asRoot = true, int $timeoutSeconds = 60): CommandResult
    {
        $connection = $this->ssh->connect($server);

        try {
            return $connection->run($this->command($arguments, $asRoot), $onOutput, $timeoutSeconds);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Upload a file into the SSH user's upload directory and run a mandal
     * command that consumes it. The command receives the remote path as
     * `--from=<path>`.
     *
     * @param  (callable(string): void)|null  $onOutput
     */
    public function runWithUpload(Server $server, string $arguments, string $contents, string $extension = '', ?callable $onOutput = null, int $timeoutSeconds = 60): CommandResult
    {
        $connection = $this->ssh->connect($server);
        $remote = self::UPLOAD_DIR.'/'.Str::random(16).$extension;

        try {
            $connection->upload($remote, $contents, 0600);
            $absolute = $connection->run('printf %s "$HOME"', timeoutSeconds: 15)->output;
            $path = rtrim(trim($absolute), '/').'/'.$remote;

            return $connection->run($this->command($arguments.' --from='.escapeshellarg($path), asRoot: true), $onOutput, $timeoutSeconds);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Server $server, string $arguments, bool $asRoot = true, int $timeoutSeconds = 60): array
    {
        $result = $this->run($server, $arguments, asRoot: $asRoot, timeoutSeconds: $timeoutSeconds);

        if ($result->failed()) {
            throw new SshException(trim($result->errorOutput ?: $result->output) ?: "mandal {$arguments} failed with exit code {$result->exitCode}");
        }

        return $result->json();
    }

    private function command(string $arguments, bool $asRoot): string
    {
        $binary = self::BINARY.' '.$arguments;

        return $asRoot ? 'sudo -n '.$binary : $binary;
    }
}
