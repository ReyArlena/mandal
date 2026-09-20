<?php

namespace App\Ssh;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;

/**
 * An Ed25519 key pair in OpenSSH format.
 */
final readonly class KeyPair
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {}

    public static function generate(string $comment): self
    {
        /** @var PrivateKey $private */
        $private = EC::createKey('Ed25519');

        return new self(
            privateKey: $private->toString('OpenSSH', ['comment' => $comment]),
            publicKey: $private->getPublicKey()->toString('OpenSSH', ['comment' => $comment]),
        );
    }

    /**
     * Reduce an OpenSSH public key line to its type and blob, dropping any comment.
     *
     * @throws SshException when the line is not an OpenSSH public key
     */
    public static function normalize(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];

        [$type, $blob] = count($parts) >= 2 ? [$parts[0], $parts[1]] : ['', $parts[0] ?? ''];

        if ($type === '' || base64_decode($blob, strict: true) === false) {
            throw new SshException('Not a valid OpenSSH public key.');
        }

        return "{$type} {$blob}";
    }

    /**
     * The SHA-256 fingerprint of an OpenSSH public key, as ssh-keygen prints it.
     */
    public static function fingerprint(string $publicKey): string
    {
        [, $blob] = explode(' ', self::normalize($publicKey));

        $binary = base64_decode($blob, strict: true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $binary, binary: true)), '=');
    }
}
