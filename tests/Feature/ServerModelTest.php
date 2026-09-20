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
        ->and($server->toArray())->not->toHaveKeys(['private_key', 'setup_token']);
});

it('issues a setup token that can be verified once and expires', function () {
    $server = Server::factory()->create();

    $token = $server->issueSetupToken();

    expect($token)->toHaveLength(48)
        ->and($server->setupTokenMatches($token))->toBeTrue()
        ->and($server->setupTokenMatches('wrong'))->toBeFalse();

    $this->travel(Server::SETUP_TOKEN_HOURS + 1)->hours();

    expect($server->setupTokenMatches($token))->toBeFalse();
});

it('rejects a setup token after it has been cleared', function () {
    $server = Server::factory()->create();
    $token = $server->issueSetupToken();

    $server->clearSetupToken();

    expect($server->setupTokenMatches($token))->toBeFalse();
});

it('reports the fingerprint of a pinned host key', function () {
    expect(Server::factory()->create()->hostKeyFingerprint())->toBeNull()
        ->and(Server::factory()->withPinnedHostKey()->create()->hostKeyFingerprint())->toStartWith('SHA256:');
});
