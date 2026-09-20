<?php

use App\Models\Server;
use App\Ssh\HostKeyMismatchException;
use App\Ssh\KeyPair;
use App\Ssh\UnknownHostKeyException;
use Tests\Support\FakeSshClient;

it('refuses to connect to a server whose host key has not been confirmed', function () {
    $server = Server::factory()->create();

    expect(fn () => (new FakeSshClient)->connect($server))
        ->toThrow(UnknownHostKeyException::class);

    expect($server->fresh()->host_key)->toBeNull();
});

it('pins the host key when the caller trusts it on first connection', function () {
    $server = Server::factory()->create();

    (new FakeSshClient)->connect($server, trustUnknownHostKey: true);

    expect($server->fresh()->host_key)->toBe(FakeSshClient::HOST_KEY);
});

it('connects when the presented host key matches the pinned one, ignoring the comment', function () {
    $server = Server::factory()->create(['host_key' => KeyPair::normalize(FakeSshClient::HOST_KEY).' some comment']);

    $connection = (new FakeSshClient)->connect($server);

    expect($connection)->toBeInstanceOf(FakeSshClient::class);
});

it('refuses to connect when the host key has changed', function () {
    $server = Server::factory()->create(['host_key' => FakeSshClient::HOST_KEY]);

    $client = new FakeSshClient(hostKey: KeyPair::generate('other')->publicKey);

    expect(fn () => $client->connect($server))->toThrow(HostKeyMismatchException::class);
});
