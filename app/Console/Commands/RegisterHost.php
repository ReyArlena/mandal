<?php

namespace App\Console\Commands;

use App\Enums\ServerSetupMode;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Illuminate\Console\Command;

/**
 * Registers the server the panel runs on as one of the servers it manages,
 * so a hosted panel can manage itself. install.sh runs it twice: once to
 * get the panel's public key to allow on the server, and once with
 * --verify after the key is in place.
 */
class RegisterHost extends Command
{
    protected $signature = 'mandal:register-host
        {--name= : A name for the server (default: the host name)}
        {--host=127.0.0.1 : The address the panel connects to}
        {--host-key-file=/etc/ssh/ssh_host_ed25519_key.pub : The host key to pin}
        {--verify : Connect, and record the server as connected}';

    protected $description = 'Register the server this panel runs on as one it manages';

    public function handle(SshClient $ssh, MandalClient $mandal): int
    {
        $host = (string) $this->option('host');
        $server = Server::query()->where('host', $host)->first();

        if ($server === null) {
            $name = (string) ($this->option('name') ?: gethostname() ?: 'panel-host');

            if (Server::query()->where('name', $name)->exists()) {
                $this->error("A server named {$name} already exists; pass --name.");

                return self::FAILURE;
            }

            $hostKey = $this->readHostKey();

            if ($hostKey === null) {
                return self::FAILURE;
            }

            $server = Server::query()->create([
                'name' => $name,
                'host' => $host,
                'port' => 22,
                'ssh_user' => 'mandal',
                'setup_mode' => ServerSetupMode::Existing,
            ]);
            $server->forceFill(['host_key' => $hostKey])->save();
        } elseif (! $server->hasPinnedHostKey()) {
            $hostKey = $this->readHostKey();

            if ($hostKey === null) {
                return self::FAILURE;
            }

            $server->forceFill(['host_key' => $hostKey])->save();
        }

        if ($this->option('verify') && ! $this->verify($server, $ssh, $mandal)) {
            return self::FAILURE;
        }

        $this->line(json_encode([
            'id' => $server->id,
            'name' => $server->name,
            'public_key' => $server->public_key,
            'connected' => $server->connected_at !== null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * The host key to pin, from the file given, or null after an error.
     */
    private function readHostKey(): ?string
    {
        $file = (string) $this->option('host-key-file');

        if (! is_readable($file)) {
            $this->error("Cannot read the host key from {$file}.");

            return null;
        }

        try {
            return KeyPair::normalize((string) file_get_contents($file));
        } catch (SshException $exception) {
            $this->error("{$file}: {$exception->getMessage()}");

            return null;
        }
    }

    private function verify(Server $server, SshClient $ssh, MandalClient $mandal): bool
    {
        try {
            $connection = $ssh->connect($server);
            $result = $connection->run('echo mandal-ok', timeoutSeconds: 15);
            $connection->disconnect();
        } catch (SshException $exception) {
            $this->error("Could not connect to {$server->host}: {$exception->getMessage()}");

            return false;
        }

        if (! str_contains($result->output, 'mandal-ok')) {
            $this->error('Connected, but the shell did not respond as expected: '.trim($result->errorOutput ?: $result->output));

            return false;
        }

        try {
            $provisioned = (bool) ($mandal->inventory($server, fresh: true)['mandal']['provisioned'] ?? false);
        } catch (SshException $exception) {
            $this->error("Connected, but the mandal CLI did not answer: {$exception->getMessage()}");

            return false;
        }

        $server->forceFill([
            'connected_at' => now(),
            'provisioned_at' => $provisioned ? ($server->provisioned_at ?? now()) : null,
        ])->save();

        return true;
    }
}
