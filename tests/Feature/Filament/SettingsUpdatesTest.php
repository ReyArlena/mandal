<?php

use App\Enums\TaskType;
use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\Pages\Updates;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\PanelReleases;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CliUpdateRequired;
use App\Ssh\SshClient;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['mandal.repository' => 'acme/mandal']);
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->hash = app(CliArchive::class)->hash();
});

it('sits below Administrators in the settings sidebar under the Settings heading', function () {
    livewire(General::class)->assertSeeInOrder(['Administrators', 'Updates', 'Source providers']);

    expect(livewire(Updates::class)->instance()->getHeading())->toBe('Settings');
});

it('lists every server with its recorded CLI build without connecting to any', function () {
    $outdated = Server::factory()->withPinnedHostKey()->create(['name' => 'old-box', 'cli_build' => 'release00001']);
    $current = Server::factory()->withPinnedHostKey()->create(['name' => 'fresh-box', 'cli_build' => $this->hash]);
    Server::factory()->withPinnedHostKey()->create(['name' => 'quiet-box', 'cli_build' => null]);
    Server::factory()->create(['name' => 'new-box', 'cli_build' => null]);

    livewire(Updates::class)
        ->assertOk()
        ->assertSeeInOrder(['fresh-box', 'Current', 'new-box', 'Not connected', 'old-box', 'Update required', 'quiet-box', 'Unknown'])
        ->assertSee('build release00001')
        ->assertActionVisible('updateCli', ['server' => $outdated->getKey()])
        ->assertActionHidden('updateCli', ['server' => $current->getKey()])
        ->assertActionHidden('updateAll');

    expect($this->ssh->commands)->toBe([]);
});

it('updates the CLI on one server and opens its task', function () {
    Queue::fake();
    $server = Server::factory()->withPinnedHostKey()->create(['cli_build' => 'release00001']);

    livewire(Updates::class)
        ->callAction('updateCli', arguments: ['server' => $server->getKey()])
        ->assertNotified('CLI update started')
        ->assertRedirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $server->tasks()->sole()]));

    expect($server->tasks()->sole()->type)->toBe(TaskType::UpdateCli);
    Queue::assertPushed(UpdateCli::class);
});

it('refuses an update while another task runs on the server', function () {
    Queue::fake();
    $server = Server::factory()->withPinnedHostKey()->create(['cli_build' => 'release00001']);
    Task::factory()->for($server)->running()->create();

    livewire(Updates::class)
        ->callAction('updateCli', arguments: ['server' => $server->getKey()])
        ->assertNotified('Another task is still running');

    expect($server->tasks()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('updates every outdated server at once and skips busy ones', function () {
    Queue::fake();
    $first = Server::factory()->withPinnedHostKey()->create(['name' => 'alpha', 'cli_build' => 'release00001']);
    $second = Server::factory()->withPinnedHostKey()->create(['name' => 'beta', 'cli_build' => 'release00002']);
    $busy = Server::factory()->withPinnedHostKey()->create(['name' => 'gamma', 'cli_build' => 'release00001']);
    $current = Server::factory()->withPinnedHostKey()->create(['cli_build' => $this->hash]);
    Task::factory()->for($busy)->running()->create();

    livewire(Updates::class)
        ->assertActionVisible('updateAll')
        ->callAction('updateAll')
        ->assertNotified('CLI updates started on 2 servers');

    expect($first->tasks()->sole()->type)->toBe(TaskType::UpdateCli)
        ->and($second->tasks()->sole()->type)->toBe(TaskType::UpdateCli)
        ->and($busy->tasks()->count())->toBe(1)
        ->and($current->tasks()->count())->toBe(0);
    Queue::assertPushed(UpdateCli::class, 2);
});

it('checks every connected server and GitHub now', function () {
    Http::fake(['api.github.com/repos/acme/mandal/releases/latest' => Http::response(['tag_name' => 'v99.0.0'])]);
    $server = Server::factory()->withPinnedHostKey()->create(['name' => 'old-box', 'cli_build' => $this->hash]);
    $this->ssh->respondWithJson('version --json', ['version' => '0.0.9', 'build' => 'release00001']);

    livewire(Updates::class)
        ->callAction('checkNow')
        ->assertNotified('Checked')
        ->assertSee('Update required')
        ->assertSee('99.0.0');

    expect($server->fresh()->cli_build)->toBe('release00001')
        ->and(Setting::get(PanelReleases::LATEST_SETTING))->toBe('99.0.0')
        ->and(DatabaseNotification::query()->where('type', CliUpdateRequired::class)->count())->toBe(1);
});
