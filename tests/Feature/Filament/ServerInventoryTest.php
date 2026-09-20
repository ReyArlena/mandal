<?php

use App\Filament\Resources\Servers\Pages\ServerInventory;
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
    $this->inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
});

it('shows what the CLI reports about the server', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ServerInventory::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('Ubuntu 24.04.3 LTS')
        ->assertSee('shop.example.com')
        ->assertSee('shop-worker')
        ->assertSee('allow 443/tcp')
        ->assertSee('Required')
        ->assertSee('PHP 8.5');
});

it('explains when the server has never been connected', function () {
    $server = Server::factory()->create();

    livewire(ServerInventory::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('has not been connected yet');

    expect($this->ssh->commands)->toBeEmpty();
});

it('shows the SSH error when the server cannot be read', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->connectionFailure = new SshException('Could not connect to 203.0.113.10:22: timed out');

    livewire(ServerInventory::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('Inventory is unavailable')
        ->assertSee('timed out');
});

it('refreshes past the cache', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ServerInventory::class, ['record' => $server->getRouteKey()])
        ->callAction('refresh')
        ->assertOk();

    expect($this->ssh->commands)->toHaveCount(2);
});
