<?php

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
    $this->inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
});

it('shows what the CLI reports about the server', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('Ubuntu 26.04.1 LTS')
        ->assertSee('shop.example.com')
        ->assertSee('shop-worker')
        ->assertSee('allow 443/tcp')
        ->assertSee('Required')
        ->assertSee('PHP 8.5');
});

it('labels sites mandal writes for the server itself', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->inventory['php']['pools'] = [];
    $this->inventory['nginx']['sites'] = [[
        'managed_app' => null, 'managed' => 'server', 'server_names' => [], 'ports' => [80], 'root' => '', 'redirects' => 0, 'ssl' => false,
        'name' => 'mandal-default', 'file' => '/etc/nginx/sites-available/mandal-default', 'enabled' => true,
    ]];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('mandal-default')
        ->assertDontSee('unmanaged');
});

it('explains when the server has never been connected', function () {
    $server = Server::factory()->create();

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('has not been connected yet');

    expect($this->ssh->commands)->toBeEmpty();
});

it('shows the SSH error when the server cannot be read', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->connectionFailure = new SshException('Could not connect to 203.0.113.10:22: timed out');

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('Inventory is unavailable')
        ->assertSee('timed out');
});

it('refreshes past the cache', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('refresh')
        ->assertOk();

    expect($this->ssh->commands)->toHaveCount(2);
});

it('enables ntsync from the system card and reads the inventory again', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->inventory['system']['ntsync'] = ['supported' => true, 'active' => false, 'persistent' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory)
        ->respondTo('ntsync:enable', "ntsync is active.\n");

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertSee('Not enabled')
        ->assertActionEnabled('enableNtsync')
        ->callAction('enableNtsync')
        ->assertNotified('ntsync is active');

    expect($this->ssh->ran('ntsync:enable'))->toBeTrue()
        ->and(collect($this->ssh->commands)->filter(fn (string $command): bool => str_contains($command, 'inventory')))->toHaveCount(2);
});

it('reports ntsync that is already active', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->inventory['system']['ntsync'] = ['supported' => true, 'active' => true, 'persistent' => true];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertSee('Active')
        ->assertActionDisabled('enableNtsync');
});

it('says when the kernel has no ntsync driver', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->inventory['system']['ntsync'] = ['supported' => false, 'active' => false, 'persistent' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertSee('Not supported')
        ->assertSee('needs Linux 6.14 or newer');
});
