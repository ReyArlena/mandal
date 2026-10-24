<?php

use App\Enums\ServerSetupMode;
use App\Models\Server;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Tests\Support\FakeSshClient;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->hostKeyFile = tempnam(sys_get_temp_dir(), 'mandal-host-key');
    file_put_contents($this->hostKeyFile, FakeSshClient::HOST_KEY."\n");
});

afterEach(function () {
    @unlink($this->hostKeyFile);
});

it('registers the host once, pinning its host key and printing the public key', function () {
    $this->artisan('mandal:register-host', ['--name' => 'panel-1', '--host-key-file' => $this->hostKeyFile])
        ->expectsOutputToContain('"name":"panel-1"')
        ->assertSuccessful();

    $server = Server::query()->sole();

    expect($server->host)->toBe('127.0.0.1')
        ->and($server->ssh_user)->toBe('mandal')
        ->and($server->setup_mode)->toBe(ServerSetupMode::Existing)
        ->and($server->hostKeyFingerprint())->toBe(KeyPair::fingerprint(FakeSshClient::HOST_KEY))
        ->and($server->connected_at)->toBeNull();

    $this->artisan('mandal:register-host', ['--name' => 'ignored', '--host-key-file' => $this->hostKeyFile])
        ->expectsOutputToContain($server->public_key)
        ->assertSuccessful();

    expect(Server::query()->count())->toBe(1);
});

it('records the connection and provisioning state with --verify', function () {
    $this->ssh->respondTo('echo mandal-ok', 'mandal-ok');
    $this->ssh->respondWithJson('inventory', ['mandal' => ['provisioned' => true]]);

    $this->artisan('mandal:register-host', ['--name' => 'panel-1', '--host-key-file' => $this->hostKeyFile, '--verify' => true])
        ->expectsOutputToContain('"connected":true')
        ->assertSuccessful();

    $server = Server::query()->sole();

    expect($server->connected_at)->not->toBeNull()
        ->and($server->provisioned_at)->not->toBeNull();
});

it('fails to verify when the server cannot be reached', function () {
    $this->ssh->connectionFailure = new SshException('Could not connect to 127.0.0.1:22: refused');

    $this->artisan('mandal:register-host', ['--name' => 'panel-1', '--host-key-file' => $this->hostKeyFile, '--verify' => true])
        ->expectsOutputToContain('Could not connect to 127.0.0.1')
        ->assertFailed();

    expect(Server::query()->sole()->connected_at)->toBeNull();
});

it('fails when the host key file is missing or when the name is taken', function () {
    $this->artisan('mandal:register-host', ['--host-key-file' => '/nonexistent/key.pub'])
        ->expectsOutputToContain('Cannot read the host key')
        ->assertFailed();

    expect(Server::query()->count())->toBe(0);

    Server::factory()->create(['name' => 'taken', 'host' => '203.0.113.9']);

    $this->artisan('mandal:register-host', ['--name' => 'taken', '--host-key-file' => $this->hostKeyFile])
        ->expectsOutputToContain('already exists; pass --name')
        ->assertFailed();
});
