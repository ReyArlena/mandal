<?php

namespace App\Ssh;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

class PhpseclibSshClient implements SshClient
{
    public function __construct(
        private readonly HostKeyVerifier $hostKeys,
        private readonly int $connectTimeoutSeconds = 15,
    ) {}

    public function connect(Server $server, bool $trustUnknownHostKey = false): Connection
    {
        $sftp = new SFTP($server->host, $server->port, $this->connectTimeoutSeconds);

        $hostKey = $this->readHostKey($sftp, $server);
        $this->hostKeys->verify($server, $hostKey, $trustUnknownHostKey);

        try {
            $key = PublicKeyLoader::loadPrivateKey($server->private_key);
            $authenticated = $sftp->login($server->ssh_user, $key);
        } catch (Throwable $exception) {
            throw new SshException("Authentication to {$server->host} failed: {$exception->getMessage()}", previous: $exception);
        }

        if (! $authenticated) {
            throw new SshException("The server {$server->host} rejected the panel's key for user {$server->ssh_user}.");
        }

        if (! $server->hasPinnedHostKey()) {
            $server->forceFill(['host_key' => $hostKey])->save();
        }

        return new PhpseclibConnection($sftp);
    }

    public function fetchHostKey(Server $server): string
    {
        return $this->readHostKey(new SSH2($server->host, $server->port, $this->connectTimeoutSeconds), $server);
    }

    private function readHostKey(SSH2 $ssh, Server $server): string
    {
        try {
            $hostKey = $ssh->getServerPublicHostKey();
        } catch (Throwable $exception) {
            throw new SshException("Could not connect to {$server->host}:{$server->port}: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_string($hostKey)) {
            throw new SshException("The server {$server->host} did not present a valid host key.");
        }

        return $hostKey;
    }
}
