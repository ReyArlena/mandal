<?php

use App\Enums\ReleaseChannel;
use App\Enums\TaskType;
use App\Filament\Clusters\Settings\Pages\Updates;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\UpdatePanel;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Mandal\PanelReleases;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Notifications\PanelUpdateAvailable;
use App\Ssh\SshClient;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

const MAIN_HEAD = 'abcdef1234567890abcdef1234567890abcdef12';
const MAIN_NEXT = '1234567890abcdef1234567890abcdef12345678';

/**
 * Pretends the mandal CLI deployed this panel from a ref, as it records
 * next to the code on a hosted panel.
 */
function panelDeployedFrom(string $ref, string $commit = MAIN_HEAD): void
{
    file_put_contents((string) config('mandal.release_file'), "BRANCH={$ref}\nCOMMIT={$commit}\nDEPLOYED_AT=2026-11-03T10:00:00Z\n");
}

beforeEach(function () {
    config(['mandal.repository' => 'acme/mandal']);
    $this->releaseFile = tempnam(sys_get_temp_dir(), 'mandal-release');
    unlink($this->releaseFile);
    config(['mandal.release_file' => $this->releaseFile]);
    $this->users = User::factory()->count(2)->create();
    $this->actingAs($this->users->first());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->current = app(CliArchive::class)->version();
});

afterEach(function () {
    @unlink($this->releaseFile);
});

it('records the latest release from GitHub and notifies every user once about a newer one', function () {
    Http::fake(['api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => 'v99.0.0'])]);

    $releases = app(PanelReleases::class);

    expect($releases->check())->toBe('99.0.0')
        ->and(Setting::get(PanelReleases::LATEST_SETTING))->toBe('99.0.0')
        ->and($releases->available())->toBeTrue()
        ->and($releases->pending()->command())->toBe('sudo mandal panel:update --version=v99.0.0');

    $releases->check();
    $notices = DatabaseNotification::query()->where('type', PanelUpdateAvailable::class)->get();

    expect($notices)->toHaveCount(2)
        ->and($notices->first()->data['title'])->toBe('Mandal 99.0.0 is available')
        ->and($notices->first()->data['body'])->toBe("This panel runs {$this->current}. Update it under Settings > Updates.")
        ->and($notices->first()->data['actions'][0]['url'])->toContain('/settings/updates')
        ->and($notices->first()->data['version'])->toBe('99.0.0');

    Http::assertSentCount(2);
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

it('offers a newer release as a task on the hosting server under Settings > Updates', function () {
    Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '127.0.0.1', 'ssh_user' => 'mandal']);
    panelDeployedFrom("v{$this->current}");

    livewire(Updates::class)
        ->assertOk()
        ->assertSeeInOrder(['Release channel', 'Stable', 'Running', $this->current, 'release', "v{$this->current}", 'Latest release', 'Not checked yet'])
        ->assertDontSee('panel:update')
        ->assertActionHidden('updatePanel');

    Setting::set(PanelReleases::LATEST_SETTING, '99.0.0');

    livewire(Updates::class)
        ->assertSee('99.0.0')
        ->assertSee('Available')
        ->assertSee('Update to Mandal 99.0.0')
        ->assertSee('a task on panel-host')
        ->assertSee('sudo mandal panel:update --version=v99.0.0')
        ->assertDontSee('Sign in to')
        ->assertActionVisible('updatePanel');

    Setting::set(PanelReleases::LATEST_SETTING, $this->current);

    livewire(Updates::class)
        ->assertSee('Current')
        ->assertDontSee('panel:update')
        ->assertActionHidden('updatePanel');

    expect($this->ssh->commands)->toBe([]);
});

it('queues panel:update on the hosting server and opens its task', function () {
    Queue::fake();
    $host = Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '127.0.0.1']);
    Setting::set(PanelReleases::LATEST_SETTING, '99.0.0');

    livewire(Updates::class)
        ->callAction('updatePanel')
        ->assertNotified('Panel update started')
        ->assertRedirect(ServerResource::getUrl('task', ['record' => $host, 'task' => $host->tasks()->sole()]));

    $task = $host->tasks()->sole();

    expect($task->type)->toBe(TaskType::UpdatePanel)
        ->and($task->options)->toBe(['version' => 'v99.0.0'])
        ->and((new UpdatePanel($task))->arguments())->toBe('panel:update --version=v99.0.0');
    Queue::assertPushed(UpdatePanel::class);
});

it('refuses the panel update while another task runs on the hosting server', function () {
    Queue::fake();
    $host = Server::factory()->withPinnedHostKey()->create(['host' => '127.0.0.1']);
    Task::factory()->for($host)->running()->create();
    Setting::set(PanelReleases::LATEST_SETTING, '99.0.0');

    livewire(Updates::class)
        ->callAction('updatePanel')
        ->assertNotified('Another task is still running');

    expect($host->tasks()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('shows the shell steps when no server here is known as the panel host', function () {
    Setting::set(PanelReleases::LATEST_SETTING, '99.0.0');

    livewire(Updates::class)
        ->assertSee('from a checkout, not a deploy')
        ->assertSee('No server here is known to host the panel')
        ->assertSee('Sign in to that server')
        ->assertSeeHtml('ssh mandal@&lt;server address&gt;')
        ->assertSee('sudo mandal panel:update --version=v99.0.0')
        ->assertSee('Add key')
        ->assertActionHidden('updatePanel');
});

it('knows the hosting server from the inventory that reports the panel app, before the loopback address', function () {
    $loopback = Server::factory()->withPinnedHostKey()->create(['name' => 'loopback', 'host' => '127.0.0.1']);
    $host = Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '203.0.113.10']);
    $hash = app(CliArchive::class)->hash();

    expect(app(PanelReleases::class)->hostingServer()?->is($loopback))->toBeTrue();

    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => $hash, 'hosts_panel' => true, 'panel_app' => 'mandal-panel']]);
    app(MandalClient::class)->inventory($host, fresh: true);

    expect($host->fresh()->panel_app)->toBe('mandal-panel')
        ->and(app(PanelReleases::class)->hostingServer()?->is($host))->toBeTrue();

    livewire(Updates::class)->assertSeeInOrder(['panel-host', 'hosts this panel']);

    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => $hash]]);
    app(MandalClient::class)->inventory($host, fresh: true);

    expect($host->fresh()->panel_app)->toBe('mandal-panel');

    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => $hash, 'hosts_panel' => false, 'panel_app' => null]]);
    app(MandalClient::class)->inventory($host, fresh: true);

    expect($host->fresh()->panel_app)->toBeNull()
        ->and(app(PanelReleases::class)->hostingServer()?->is($loopback))->toBeTrue();
});

it('switches to the development channel and offers the head of main', function () {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => "v{$this->current}"]),
        'api.github.com/repos/acme/mandal/commits/main' => Http::response(['sha' => MAIN_HEAD]),
    ]);
    $host = Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '127.0.0.1']);
    panelDeployedFrom("v{$this->current}");

    livewire(Updates::class)
        ->assertDontSee('Latest development build')
        ->callAction('releaseChannel', data: ['channel' => 'development'])
        ->assertNotified('Release channel set to Development')
        ->assertSeeInOrder(['Release channel', 'Development', 'Latest development build', 'main@abcdef1', 'Available'])
        ->assertSee('Update to main@abcdef1')
        ->assertSee('sudo mandal panel:update --branch=main')
        ->assertActionVisible('updatePanel')
        ->callAction('updatePanel')
        ->assertNotified('Panel update started');

    $task = $host->tasks()->sole();
    $notice = DatabaseNotification::query()->where('type', PanelUpdateAvailable::class)->first();

    expect(app(PanelReleases::class)->channel())->toBe(ReleaseChannel::Development)
        ->and(Setting::get(PanelReleases::LATEST_COMMIT_SETTING))->toBe(MAIN_HEAD)
        ->and($task->options)->toBe(['branch' => 'main'])
        ->and((new UpdatePanel($task))->arguments())->toBe('panel:update --branch=main')
        ->and(DatabaseNotification::query()->count())->toBe(2)
        ->and($notice->data['title'])->toBe('Development build abcdef1 is available')
        ->and($notice->data['body'])->toBe("This panel runs {$this->current}. Update it under Settings > Updates.")
        ->and($notice->data['version'])->toBe(MAIN_HEAD);
    Queue::assertPushed(UpdatePanel::class);
});

it('treats a panel on the head of main as current and a newer commit as an update', function () {
    Server::factory()->withPinnedHostKey()->create(['name' => 'panel-host', 'host' => '127.0.0.1']);
    Setting::set(PanelReleases::CHANNEL_SETTING, 'development');
    Setting::set(PanelReleases::LATEST_SETTING, $this->current);
    Setting::set(PanelReleases::LATEST_COMMIT_SETTING, MAIN_HEAD);
    panelDeployedFrom('main');

    app(PanelReleases::class)->refreshNotices();

    expect(app(PanelReleases::class)->pending())->toBeNull()
        ->and(app(PanelReleases::class)->running())->toBe("{$this->current} (main@abcdef1)")
        ->and(DatabaseNotification::query()->count())->toBe(0);

    livewire(Updates::class)
        ->assertSeeInOrder(['Running', 'development build', 'main@abcdef1', 'deployed', 'Latest development build', 'main@abcdef1', 'Current'])
        ->assertActionHidden('updatePanel');

    Setting::set(PanelReleases::LATEST_COMMIT_SETTING, MAIN_NEXT);
    app(PanelReleases::class)->refreshNotices();

    expect(app(PanelReleases::class)->pending()?->label())->toBe('main@1234567')
        ->and(DatabaseNotification::query()->first()->data['body'])->toBe("This panel runs {$this->current} (main@abcdef1). Update it under Settings > Updates.");

    livewire(Updates::class)->assertSee('Update to main@1234567');
});

it('starts on the development channel when the panel was deployed from a branch and no channel was chosen', function () {
    Http::fake([
        'api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => "v{$this->current}"]),
        'api.github.com/repos/acme/mandal/commits/main' => Http::response(['sha' => MAIN_HEAD]),
    ]);
    Server::factory()->withPinnedHostKey()->create(['host' => '127.0.0.1']);
    panelDeployedFrom('main');

    expect(app(PanelReleases::class)->channel())->toBe(ReleaseChannel::Development)
        ->and(app(PanelReleases::class)->check())->toBe($this->current)
        ->and(app(PanelReleases::class)->pending())->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0);

    livewire(Updates::class)
        ->assertSeeInOrder(['Release channel', 'Development', 'Latest development build', 'main@abcdef1', 'Current'])
        ->assertDontSee("Update to Mandal {$this->current}");

    panelDeployedFrom("v{$this->current}");

    expect(app(PanelReleases::class)->channel())->toBe(ReleaseChannel::Stable);

    Setting::set(PanelReleases::CHANNEL_SETTING, 'development');

    expect(app(PanelReleases::class)->channel())->toBe(ReleaseChannel::Development);
});

it('offers the latest release again to a panel on main once the channel goes back to stable', function () {
    Http::fake(['api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => "v{$this->current}"])]);
    Server::factory()->withPinnedHostKey()->create(['host' => '127.0.0.1']);
    Setting::set(PanelReleases::CHANNEL_SETTING, 'development');
    Setting::set(PanelReleases::LATEST_COMMIT_SETTING, MAIN_HEAD);
    panelDeployedFrom('main');

    livewire(Updates::class)
        ->callAction('releaseChannel', data: ['channel' => 'stable'])
        ->assertNotified('Release channel set to Stable')
        ->assertSee("Update to Mandal {$this->current}")
        ->assertSee("sudo mandal panel:update --version=v{$this->current}")
        ->assertDontSee('Latest development build');

    expect(app(PanelReleases::class)->channel())->toBe(ReleaseChannel::Stable)
        ->and(DatabaseNotification::query()->first()->data['title'])->toBe("Mandal {$this->current} is available");
    Http::assertSentCount(1);
});

it('reports the panel release state from the scheduled command', function () {
    Http::fake([
        'api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => 'v99.0.0']),
        'api.github.com/repos/acme/mandal/commits/main' => Http::response(['sha' => MAIN_HEAD]),
    ]);

    $this->artisan('mandal:check-updates')
        ->expectsOutputToContain('Mandal 99.0.0 is available')
        ->assertSuccessful();

    Setting::set(PanelReleases::CHANNEL_SETTING, 'development');
    panelDeployedFrom('main', MAIN_NEXT);

    $this->artisan('mandal:check-updates')
        ->expectsOutputToContain('main@abcdef1 is available; this panel runs '.$this->current.' (main@1234567)')
        ->assertSuccessful();

    panelDeployedFrom('main');

    $this->artisan('mandal:check-updates')
        ->expectsOutputToContain('This panel runs the latest development build')
        ->assertSuccessful();
});
