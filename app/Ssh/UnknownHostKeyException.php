<?php

namespace App\Ssh;

/**
 * No host key is pinned for the server yet, so the connection was refused
 * until a user confirms the fingerprint.
 */
class UnknownHostKeyException extends SshException
{
    public function __construct(
        public readonly string $hostKey,
        public readonly string $fingerprint,
    ) {
        parent::__construct("The server's host key ({$fingerprint}) has not been confirmed yet.");
    }
}
