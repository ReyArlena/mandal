<?php

use App\Models\Server;
use Illuminate\Support\Facades\DB;

it('generates a key pair when a server is created', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    expect($server->public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->public_key)->toEndWith('mandal panel (web-1)')
        ->and($server->private_key)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
});

it('stores the private key encrypted and never serialises it', function () {
    $server = Server::factory()->create();

    $stored = DB::table('servers')->where('id', $server->id)->value('private_key');

    expect($stored)->not->toContain('BEGIN OPENSSH')
        ->and($server->toArray())->not->toHaveKey('private_key');
});

it('builds a setup command that fetches setup.sh from the latest release with the panel key', function () {
    config(['mandal.repository' => 'acme/mandal']);
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $keyBlob = explode(' ', $server->public_key)[1];

    expect($server->setupCommand())
        ->toStartWith('curl -fsSL https://github.com/acme/mandal/releases/latest/download/setup.sh | sudo bash -s -- ')
        ->toContain("--user='deploy'")
        ->toContain("--key='ssh-ed25519 {$keyBlob} mandal-panel'")
        ->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

it('reports the fingerprint of a pinned host key', function () {
    expect(Server::factory()->create()->hostKeyFingerprint())->toBeNull()
        ->and(Server::factory()->withPinnedHostKey()->create()->hostKeyFingerprint())->toStartWith('SHA256:');
});
