<?php

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Mandal\CliArchive;
use App\Mandal\PanelReleases;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PanelUpdateAvailable;
use App\Ssh\SshClient;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['mandal.repository' => 'acme/mandal']);
    $this->users = User::factory()->count(2)->create();
    $this->actingAs($this->users->first());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->current = app(CliArchive::class)->version();
});

it('records the latest release from GitHub and notifies every user once about a newer one', function () {
    Http::fake(['api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => 'v99.0.0'])]);
    $host = Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '127.0.0.1']);

    $releases = app(PanelReleases::class);

    expect($releases->check())->toBe('99.0.0')
        ->and(Setting::get(PanelReleases::LATEST_SETTING))->toBe('99.0.0')
        ->and($releases->available())->toBeTrue();

    $releases->check();
    $notices = DatabaseNotification::query()->where('type', PanelUpdateAvailable::class)->get();

    expect($notices)->toHaveCount(2)
        ->and($notices->first()->data['title'])->toBe('Mandal 99.0.0 is available')
        ->and($notices->first()->data['body'])->toContain("This panel runs {$this->current}")
        ->and($notices->first()->data['body'])->toContain('sudo mandal panel:update')
        ->and($notices->first()->data['body'])->toContain('panel-host (127.0.0.1)')
        ->and($notices->first()->data['actions'][0]['url'])->toContain("/servers/{$host->id}")
        ->and($notices->first()->data['version'])->toBe('99.0.0');
});

it('replaces the notice when a newer release appears and clears it once the panel has caught up', function () {
    Http::fake(['api.github.com/*' => Http::sequence()
        ->push(['tag_name' => 'v99.0.0'])
        ->push(['tag_name' => 'v99.1.0'])
        ->push(['tag_name' => "v{$this->current}"])]);

    app(PanelReleases::class)->check();
    app(PanelReleases::class)->check();

    $notices = DatabaseNotification::query()->get();
    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('data.version')->unique()->all())->toBe(['99.1.0']);

    app(PanelReleases::class)->check();

    expect(DatabaseNotification::query()->count())->toBe(0)
        ->and(app(PanelReleases::class)->available())->toBeFalse();
});

it('keeps the last known release and raises nothing when GitHub cannot be read', function () {
    Http::fake(['api.github.com/*' => Http::response(status: 403)]);

    expect(app(PanelReleases::class)->check())->toBeNull()
        ->and(app(PanelReleases::class)->latest())->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0);

    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'not-a-version'])]);

    expect(app(PanelReleases::class)->check())->toBeNull();
});

it('shows the update instructions on the server that hosts the panel', function () {
    Setting::set(PanelReleases::LATEST_SETTING, '99.0.0');
    $host = Server::factory()->withPinnedHostKey()->create(['host' => '127.0.0.1']);
    $other = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('inventory', ['mandal' => ['hosts_panel' => true, 'build' => 'x']]);

    livewire(ViewServer::class, ['record' => $host->getRouteKey()])
        ->assertActionVisible('panelUpdate')
        ->mountAction('panelUpdate')
        ->assertActionMounted('panelUpdate');

    $html = view('filament.servers.panel-update', ['server' => $host, 'latest' => '99.0.0', 'current' => $this->current])->render();

    expect($html)->toContain('sudo mandal panel:update')
        ->toContain('Grant access')
        ->toContain('ssh mandal@&lt;server address&gt;');

    $this->ssh->respondWithJson('inventory', ['mandal' => ['hosts_panel' => false, 'build' => 'x']]);
    livewire(ViewServer::class, ['record' => $other->getRouteKey()])->assertActionHidden('panelUpdate');

    Setting::set(PanelReleases::LATEST_SETTING, $this->current);
    $this->ssh->respondWithJson('inventory', ['mandal' => ['hosts_panel' => true, 'build' => 'x']]);
    livewire(ViewServer::class, ['record' => $host->getRouteKey()])->assertActionHidden('panelUpdate');
});

it('reports the panel release state from the scheduled command', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v99.0.0'])]);

    $this->artisan('mandal:check-updates')
        ->expectsOutputToContain('Mandal 99.0.0 is available')
        ->assertSuccessful();
});
