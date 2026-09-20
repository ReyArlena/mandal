<?php

use App\Ssh\KeyPair;
use App\Ssh\SshException;

it('generates an ed25519 key pair in OpenSSH format', function () {
    $pair = KeyPair::generate('mandal panel (test)');

    expect($pair->privateKey)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($pair->publicKey)->toStartWith('ssh-ed25519 ')
        ->and($pair->publicKey)->toEndWith(' mandal panel (test)');
});

it('generates a different key each time', function () {
    expect(KeyPair::generate('a')->publicKey)->not->toBe(KeyPair::generate('a')->publicKey);
});

it('computes a SHA-256 fingerprint that ignores the comment', function () {
    $publicKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 comment';

    expect(KeyPair::fingerprint($publicKey))
        ->toStartWith('SHA256:')
        ->toHaveLength(7 + 43)
        ->toBe(KeyPair::fingerprint('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1'));
});

it('normalises a key line to its type and blob', function () {
    expect(KeyPair::normalize("  ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 a comment here\n"))
        ->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1');
});

it('rejects text that is not a public key', function () {
    expect(fn () => KeyPair::fingerprint('ssh-ed25519 not*base64'))->toThrow(SshException::class);
});
