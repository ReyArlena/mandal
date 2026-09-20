<?php

namespace App\Ssh;

use App\Models\Server;

/**
 * Compares the host key a server presents with the one pinned for it.
 */
class HostKeyVerifier
{
    /**
     * @throws UnknownHostKeyException when nothing is pinned and the key may not be trusted automatically
     * @throws HostKeyMismatchException when the pinned key differs from the presented one
     */
    public function verify(Server $server, string $presented, bool $trustUnknown = false): void
    {
        if (! $server->hasPinnedHostKey()) {
            if ($trustUnknown) {
                return;
            }

            throw new UnknownHostKeyException($presented, KeyPair::fingerprint($presented));
        }

        if (! hash_equals(KeyPair::normalize($server->host_key), KeyPair::normalize($presented))) {
            throw new HostKeyMismatchException(
                KeyPair::fingerprint($server->host_key),
                KeyPair::fingerprint($presented),
            );
        }
    }
}
