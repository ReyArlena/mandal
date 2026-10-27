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
        $version = $this->json($server, 'version --json', asRoot: false, timeoutSeconds: 15);

        $this->sawCliBuild($server, $version['build'] ?? null);

        return $version;
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

        $inventory = Cache::remember($key, self::INVENTORY_TTL, fn (): array => $this->json($server, 'inventory'));

        $this->sawCliBuild($server, $inventory['mandal']['build'] ?? null);

        return $inventory;
    }

    /**
     * Every read of a server's version keeps the panel's record of its CLI
     * build current; a change raises or clears the update notice.
     */
    private function sawCliBuild(Server $server, mixed $build): void
    {
        if (! is_string($build) || $build === '') {
            return;
        }

        if ($server->recordCliBuild($build)) {
            app(CliUpdates::class)->check($server);
        }
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
     * With $mergeErrorOutput, the server sends stderr through stdout so
     * warnings and errors stream in order with the rest of the output.
     *
     * @param  (callable(string): void)|null  $onOutput
     */
    public function run(Server $server, string $arguments, ?callable $onOutput = null, bool $asRoot = true, int $timeoutSeconds = 60, bool $mergeErrorOutput = false): CommandResult
    {
        $connection = $this->ssh->connect($server);
        $command = $this->command($arguments, $asRoot).($mergeErrorOutput ? ' 2>&1' : '');

        try {
            return $connection->run($command, $onOutput, $timeoutSeconds);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Run a mandal command whose stdout is handed to the callback chunk by
     * chunk and never kept, for file downloads. Only the exit code and
     * stderr come back in the result.
     *
     * @param  callable(string): void  $onOutput
     */
    public function stream(Server $server, string $arguments, callable $onOutput, int $timeoutSeconds = 3600): CommandResult
    {
        $connection = $this->ssh->connect($server);

        try {
            return $connection->stream($this->command($arguments, asRoot: true), $onOutput, $timeoutSeconds);
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
            // The file can hold secrets. The CLI removes it once used, but not
            // when it refuses the command, so it is always removed here too.
            rescue(fn () => $connection->run('rm -f -- '.escapeshellarg($remote), timeoutSeconds: 15), report: false);
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
