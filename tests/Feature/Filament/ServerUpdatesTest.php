<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerUpdates;
use App\Jobs\ApplyUpdates;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Ssh\SshClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $inventory['mandal']['build'] = app(CliArchive::class)->hash();
    $this->ssh->respondWithJson('inventory', $inventory);
    $this->ssh->respondWithJson('updates:status', [
        'os' => ['id' => 'ubuntu', 'version' => '26.04', 'name' => 'Ubuntu 26.04.1 LTS'],
        'uptime_seconds' => 90000,
        'reboot_required' => true,
        'reboot_packages' => ['linux-image-generic'],
        'kernel' => ['running' => '6.8.0-100-generic', 'newest' => '6.8.0-147-generic', 'newer_installed' => true],
        'pending' => [
            'security' => [['package' => 'nginx', 'current' => '1.24.0-1', 'candidate' => '1.24.0-2', 'security' => true]],
            'other' => [['package' => 'curl', 'current' => '8.5.0-1', 'candidate' => '8.5.0-2', 'security' => false]],
            'held' => ['linux-image-generic'],
        ],
        'components' => [
            ['id' => 'cache', 'name' => 'dragonfly', 'installed' => '2.0.2', 'latest' => '2.1.0', 'update_available' => true, 'note' => 'Restart drops the cache'],
            ['id' => 'js', 'name' => 'bun', 'installed' => '1.3.13', 'latest' => '1.3.13', 'update_available' => false, 'note' => ''],
            ['id' => 'mandal', 'name' => 'mandal CLI', 'installed' => '0.1.0', 'latest' => '', 'update_available' => false, 'note' => 'Updated from the panel'],
        ],
        'unattended' => ['last' => '2026-10-27 06:00:09', 'status' => 'ok'],
        'history' => [['at' => '2026-10-27  02:30:01', 'action' => 'Upgrade', 'packages' => 'nginx:amd64 (1.24.0-1, 1.24.0-2)']],
        'release_upgrade' => '28.04 LTS',
    ]);
});

it('shows the update status', function () {
    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('Required')
        ->assertSee('6.8.0-147-generic')
        ->assertSee('nginx')
        ->assertSee('1 security, 1 other')
        ->assertSee('Held back: linux-image-generic')
        ->assertSee('2.1.0')
        ->assertSee('28.04 LTS')
        ->assertActionVisible('installSecurity')
        ->assertActionVisible('reboot');
});

it('queues security, all, single package and component updates', function () {
    Queue::fake();

    $finish = function (): void {
        Task::query()->update(['status' => TaskStatus::Succeeded]);
        (new UniqueLock(Cache::store()))->release(new ApplyUpdates(Task::query()->latest('id')->firstOrFail()));
    };

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('installSecurity')
        ->assertNotified('Installing security updates');
    $finish();

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('updatePackage', arguments: ['package' => 'curl'])
        ->assertNotified('Updating curl');
    $finish();

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('updateComponent', arguments: ['id' => 'cache', 'name' => 'dragonfly', 'note' => ''])
        ->assertNotified('Updating dragonfly');

    $arguments = $this->server->tasks()->orderBy('id')->get()->map(fn (Task $task): string => (new ApplyUpdates($task))->arguments())->all();

    expect($arguments)->toBe(['updates:apply --security', 'updates:apply --package=curl', 'component:update --component=cache']);
    Queue::assertPushed(ApplyUpdates::class, 3);
});

it('updates the CLI through the CLI update job', function () {
    Queue::fake();

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('updateComponent', arguments: ['id' => 'mandal', 'name' => 'mandal CLI', 'note' => ''])
        ->assertNotified('CLI update started');

    expect($this->server->tasks()->sole()->type)->toBe(TaskType::UpdateCli);
    Queue::assertPushed(UpdateCli::class);
});

it('ignores unsafe package and component names', function () {
    Queue::fake();

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('updatePackage', arguments: ['package' => 'curl; rm -rf /'])
        ->callAction('updateComponent', arguments: ['id' => 'kernel', 'name' => 'x', 'note' => '']);

    expect($this->server->tasks()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('reboots through the CLI', function () {
    $this->ssh->respondTo('reboot', "Rebooting in 5 seconds.\n");

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('reboot')
        ->assertNotified('Rebooting');

    expect($this->ssh->ran('sudo -n /opt/mandal/bin/mandal reboot'))->toBeTrue();
});

it('builds update arguments only from known modes', function () {
    expect((new ApplyUpdates(Task::factory()->create(['options' => ['mode' => 'all']])))->arguments())->toBe('updates:apply --all')
        ->and(fn () => (new ApplyUpdates(Task::factory()->create(['options' => ['mode' => 'component', 'component' => 'mandal']])))->arguments())->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ApplyUpdates(Task::factory()->create(['options' => ['mode' => 'nope']])))->arguments())->toThrow(InvalidArgumentException::class);
});

it('refuses to queue while another task is active', function () {
    Queue::fake();
    Task::factory()->for($this->server)->running()->create();

    livewire(ServerUpdates::class, ['record' => $this->server->getRouteKey()])
        ->callAction('installSecurity')
        ->assertNotified('Another task is still running');

    expect($this->server->tasks()->count())->toBe(1);
    Queue::assertNothingPushed();
});
