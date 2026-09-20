<?php

namespace App\Ssh;

use App\Models\Server;

interface SshClient
{
    /**
     * Connect and authenticate as the server's SSH user.
     *
     * The server's host key is checked against the one pinned on the model.
     *
     * @throws UnknownHostKeyException when no host key is pinned and $trustUnknownHostKey is false
     * @throws HostKeyMismatchException when the pinned host key does not match
     * @throws SshException when the connection or authentication fails
     */
    public function connect(Server $server, bool $trustUnknownHostKey = false): Connection;

    /**
     * Read the server's host key without authenticating.
     */
    public function fetchHostKey(Server $server): string;
}
