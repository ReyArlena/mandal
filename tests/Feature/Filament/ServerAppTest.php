<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApp;
use App\Jobs\RollbackApp;
use App\Models\Server;
use App\Models\User;
use App\Ssh\SshClient;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('inventory', json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true));
    $this->ssh->respondWithJson('app:releases --name=shop', [
        ['release' => '20261027110000', 'commit' => 'aaaaaaa1111', 'branch' => 'main', 'deployed_at' => '2026-10-27T11:00:00Z', 'current' => false],
        ['release' => '20261027120000', 'commit' => 'bbbbbbb2222', 'branch' => 'main', 'deployed_at' => '2026-10-27T12:00:00Z', 'current' => true],
    ]);
    $this->ssh->respondWithJson('worker:list --app=shop', [
        ['name' => 'shop-worker', 'worker' => 'worker', 'command' => '/usr/bin/php8.5 /home/shop/current/artisan queue:work', 'user' => 'shop', 'numprocs' => 2, 'managed_app' => 'shop'],
    ]);
});

it('shows the app with its releases and workers', function () {
    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->assertOk()
        ->assertSee('shop.example.com')
        ->assertSeeText('acme/shop@main')
        ->assertDontSee('$app[', false)
        ->assertSee('20261027120000')
        ->assertSee('Current')
        ->assertSee('bbbbbbb')
        ->assertSee('queue:work')
        ->assertSee('2 processes')
        ->assertSee('ssh-ed25519 AAAATESTKEY');
});

it('returns 404 for an app that does not exist', function () {
    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'missing'])
        ->assertStatus(404);
});

it('rolls back to a chosen release', function () {
    Queue::fake();

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('rollbackApp', arguments: ['name' => 'shop', 'release' => '20261027110000'])
        ->assertNotified('Rolling back shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Rollback)
        ->and((new RollbackApp($task))->arguments())->toBe('app:rollback --name=shop --release=20261027110000');
});

it('adds a worker through the CLI', function () {
    $this->ssh->respondTo('worker:add', "Worker shop-default added (2 x queue:work --sleep=3 --tries=3).\n");

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('addWorker', data: ['worker' => 'default', 'command' => 'queue:work --sleep=3 --tries=3', 'processes' => 2], arguments: ['name' => 'shop'])
        ->assertHasNoActionErrors()
        ->assertNotified('Worker default added');

    expect($this->ssh->ran("worker:add --app=shop --name=default --command='queue:work --sleep=3 --tries=3' --processes=2"))->toBeTrue();
});

it('rejects a worker command with shell characters', function () {
    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('addWorker', data: ['worker' => 'default', 'command' => 'queue:work; rm -rf /', 'processes' => 1], arguments: ['name' => 'shop'])
        ->assertHasActionErrors(['command']);

    expect($this->ssh->ran('worker:add'))->toBeFalse();
});

it('removes and restarts workers', function () {
    $this->ssh->respondTo('worker:remove', "Worker removed.\n");
    $this->ssh->respondTo('worker:restart', "Restarted.\n");

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('removeWorker', arguments: ['name' => 'shop', 'worker' => 'worker'])
        ->assertNotified('Worker worker removed')
        ->callAction('restartWorkers', arguments: ['name' => 'shop'])
        ->assertNotified('Workers restarted');

    expect($this->ssh->ran('worker:remove --app=shop --name=worker'))->toBeTrue()
        ->and($this->ssh->ran('worker:restart --app=shop'))->toBeTrue();
});

it('reports a worker command failure', function () {
    $this->ssh->respondTo('worker:remove', '', exitCode: 1, errorOutput: "error: worker 'x' does not exist for app 'shop'");

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->callAction('removeWorker', arguments: ['name' => 'shop', 'worker' => 'x'])
        ->assertNotified('Could not remove the worker');
});

it('protects the app that hosts the panel', function () {
    $inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $inventory['apps'] = array_map(fn (array $app): array => $app['name'] === 'shop' ? $app + ['panel' => 'true'] : $app, $inventory['apps']);
    $this->ssh->respondWithJson('inventory', $inventory);

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->assertSee('Hosts this panel')
        ->assertActionHidden('deleteApp');
});

it('shows a docker app behind nginx with its ports and containers instead of releases', function () {
    $inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $inventory['apps'][] = ['name' => 'tool', 'type' => 'docker', 'domain' => 'tool.example.com', 'proxy' => true, 'port' => '20000', 'home' => '/srv/docker/tool', 'ports' => [['host_ip' => '127.0.0.1', 'published' => '20000', 'target' => '8080', 'protocol' => 'tcp']], 'containers' => [['name' => 'tool-app-1', 'image' => 'ghcr.io/org/tool:1.2', 'state' => 'running', 'status' => 'Up 2 hours']]];
    $this->ssh->respondWithJson('inventory', $inventory);

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'tool'])
        ->assertOk()
        ->assertSee('tool.example.com')
        ->assertSee('127.0.0.1:20000')
        ->assertSee('tool-app-1')
        ->assertSee('Up 2 hours')
        ->assertDontSee('Workers')
        ->assertDontSee('bypasses ufw')
        ->assertActionVisible('deployApp')
        ->assertActionVisible('editCompose')
        ->assertActionVisible('requestCertificate');

    expect($this->ssh->ran('app:releases --name=tool'))->toBeFalse();
});

it('shows a docker app without nginx with its public ports and no certificate action', function () {
    $inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $inventory['apps'][] = ['name' => 'enshrouded', 'type' => 'docker', 'domain' => '', 'proxy' => false, 'port' => '', 'home' => '/srv/docker/enshrouded', 'ports' => [['host_ip' => '0.0.0.0', 'published' => '15637', 'target' => '15637', 'protocol' => 'udp']], 'containers' => []];
    $this->ssh->respondWithJson('inventory', $inventory);

    livewire(ServerApp::class, ['record' => $this->server->getRouteKey(), 'app' => 'enshrouded'])
        ->assertOk()
        ->assertSee('Not in front')
        ->assertSee('0.0.0.0:15637')
        ->assertSee('bypasses ufw')
        ->assertDontSee('Domain')
        ->assertActionHidden('requestCertificate');
});
