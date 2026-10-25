<?php

use App\Filament\Resources\Servers\Pages\ServerLogs;
use App\Models\Server;
use App\Models\User;
use App\Ssh\SshClient;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('logs:sources', [
        ['id' => 'journal:nginx', 'label' => 'nginx (service)', 'kind' => 'journal', 'target' => 'nginx', 'app' => '', 'scope' => 'server'],
        ['id' => 'nginx:shop:access', 'label' => 'shop nginx access', 'kind' => 'file', 'target' => '/var/log/nginx/shop-access.log', 'app' => 'shop', 'scope' => 'app'],
        ['id' => 'laravel:shop', 'label' => 'shop Laravel log', 'kind' => 'laravel', 'target' => '/home/shop/shared/storage/logs', 'app' => 'shop', 'scope' => 'app'],
    ]);
    $this->ssh->respondWithJson('logs:read --source=journal:nginx', ['source' => 'journal:nginx', 'kind' => 'journal', 'exists' => true, 'lines' => ['2026-10-27T04:00:00+0000 web-1 systemd[1]: Started nginx.'], 'cursor' => 's=abc;i=1', 'truncated' => false]);
    $this->ssh->respondWithJson('logs:read --source=nginx:shop:access', ['source' => 'nginx:shop:access', 'kind' => 'file', 'exists' => true, 'lines' => ['GET /one 200', 'GET /two 200'], 'cursor' => '1024', 'truncated' => false]);
    $this->ssh->respondWithJson('logs:read --source=laravel:shop', ['source' => 'laravel:shop', 'kind' => 'laravel', 'exists' => true, 'entries' => [
        ['at' => '2026-10-27 04:01:00', 'level' => 'ERROR', 'message' => 'Something broke', 'text' => "[2026-10-27 04:01:00] production.ERROR: Something broke\n#0 trace"],
    ], 'cursor' => '2048', 'truncated' => false]);
});

it('loads the sources and shows the first one', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSet('source', 'journal:nginx')
        ->assertSee('Started nginx.')
        ->assertSee('shop nginx access');
});

it('switches sources and passes the filters to the CLI', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->set('source', 'nginx:shop:access')
        ->assertSee('GET /two 200')
        ->set('lines', 500)
        ->set('search', 'two')
        ->assertSee('GET /two 200');

    expect($this->ssh->ran("logs:read --source=nginx:shop:access --lines=500 --grep='two'"))->toBeTrue();
});

it('renders laravel entries with level filtering', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->set('source', 'laravel:shop')
        ->assertSee('Something broke')
        ->assertSee('error')
        ->set('level', 'error');

    expect($this->ssh->ran('logs:read --source=laravel:shop --lines=200 --level=error'))->toBeTrue();
});

it('offers the time filter for container logs', function () {
    $this->ssh->respondWithJson('logs:sources', [
        ['id' => 'docker:tool', 'label' => 'tool containers', 'kind' => 'docker', 'target' => 'tool', 'app' => 'tool', 'scope' => 'app'],
    ]);
    $this->ssh->respondWithJson('logs:read --source=docker:tool', ['source' => 'docker:tool', 'kind' => 'docker', 'exists' => true, 'lines' => ['app-1  | 2026-10-27T04:00:00.000000000Z listening on :80'], 'cursor' => '2026-10-27T04:00:05.000000000Z', 'truncated' => false]);

    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('listening on :80')
        ->assertSee('Jump to present')
        ->assertSeeHtml('wire:model.live="since"')
        ->set('since', '1h');

    expect($this->ssh->ran('logs:read --source=docker:tool --lines=200 --since=1h'))->toBeTrue();
});

it('appends new lines while tailing', function () {
    $component = livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->set('source', 'nginx:shop:access')
        ->call('toggleLive')
        ->assertSet('live', true);

    $this->ssh->respondWithJson("logs:read --source=nginx:shop:access --lines=200 --after='1024'", ['source' => 'nginx:shop:access', 'kind' => 'file', 'exists' => true, 'lines' => ['GET /three 200'], 'cursor' => '1100', 'truncated' => false]);

    $component->call('poll')
        ->assertSee('GET /one 200')
        ->assertSee('GET /three 200')
        ->assertSet('result.cursor', '1100');
});

it('restricts sources to one app when asked', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey(), 'app' => 'shop'])
        ->assertSet('source', 'nginx:shop:access')
        ->assertDontSee('nginx (service)');
});

it('refuses an unknown source without calling the server', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->set('source', '../../etc/passwd')
        ->assertSet('error', 'Unknown log source.');

    expect($this->ssh->ran('passwd'))->toBeFalse();
});

it('ignores unsupported option values', function () {
    livewire(ServerLogs::class, ['record' => $this->server->getRouteKey()])
        ->set('source', 'journal:nginx')
        ->set('lines', 99999)
        ->set('since', 'whenever')
        ->assertSet('lines', 200)
        ->assertSet('since', '');
});
