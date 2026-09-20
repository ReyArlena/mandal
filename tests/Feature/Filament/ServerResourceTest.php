<?php

use App\Enums\ServerSetupMode;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use App\Models\User;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
});

it('lists servers', function () {
    $servers = Server::factory()->count(2)->create();

    livewire(ListServers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($servers);
});

it('creates a server and generates its key pair', function () {
    livewire(CreateServer::class)
        ->fillForm([
            'name' => 'web-1',
            'host' => '203.0.113.10',
            'port' => 22,
            'ssh_user' => 'mandal',
            'setup_mode' => ServerSetupMode::Fresh->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = Server::query()->where('name', 'web-1')->firstOrFail();

    expect($server->public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->setup_mode)->toBe(ServerSetupMode::Fresh);
});

it('validates the host, port and user', function () {
    livewire(CreateServer::class)
        ->fillForm([
            'name' => 'bad',
            'host' => 'not a host',
            'port' => 70000,
            'ssh_user' => 'Root User',
            'setup_mode' => ServerSetupMode::Fresh->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['host', 'port', 'ssh_user']);
});

it('shows the public key and the unconfirmed host key state', function () {
    $server = Server::factory()->create();

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee($server->public_key)
        ->assertSee('Not confirmed yet');
});

it('pins the host key and records the connection when the test succeeds', function () {
    $server = Server::factory()->create();
    $this->ssh->respondTo('echo mandal-ok', 'mandal-ok');

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified("Connected to {$server->name}");

    $server->refresh();

    expect($server->host_key)->toBe(FakeSshClient::HOST_KEY)
        ->and($server->connected_at)->not->toBeNull();
});

it('reports a failed connection without pinning anything', function () {
    $server = Server::factory()->create();
    $this->ssh->connectionFailure = new SshException('Could not connect to 203.0.113.10:22: timed out');

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Connection failed');

    expect($server->fresh()->host_key)->toBeNull();
});
