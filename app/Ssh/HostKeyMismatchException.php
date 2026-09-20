<?php

namespace App\Ssh;

/**
 * The server presented a host key other than the one pinned for it.
 */
class HostKeyMismatchException extends SshException
{
    public function __construct(
        public readonly string $expectedFingerprint,
        public readonly string $actualFingerprint,
    ) {
        parent::__construct(
            "The server's host key has changed (expected {$expectedFingerprint}, got {$actualFingerprint}). "
            .'This can mean the server was reinstalled, or that the connection is being intercepted.'
        );
    }
}
