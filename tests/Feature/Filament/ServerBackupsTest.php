<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Jobs\RestoreBackup;
use App\Jobs\RunBackup;
use App\Models\BackupDestination;
use App\Models\Server;
use App\Models\Task;
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
    $this->inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
});

function configuredInventory(array $inventory): array
{
    $inventory['backups'] = [
        'configured' => true,
        'enabled' => true,
        'local' => ['enabled' => true, 'schedule' => '0 */6 * * *', 'keep' => 7],
        'remote' => ['name' => 'offsite', 'schedule' => '0 3 * * 0', 'keep' => 30, 'path' => 'srv1', 'bucket' => 'my-backups', 'prefix' => 'prod', 'endpoint' => 'https://s3.example.com'],
        'last_run' => ['at' => '2026-10-27T02:30:04Z', 'status' => 'ok', 'rest' => 'shop /var/backups/mandal/db/shop/shop-20261027023000.sql.zst 2048'],
        'databases' => ['shop'],
        'apps' => [],
    ];

    return $inventory;
}

function offsiteDestination(): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'Offsite',
        'slug' => 'offsite',
        'endpoint' => 'https://s3.example.com',
        'region' => 'auto',
        'bucket' => 'my-backups',
        'path' => 'prod',
        'access_key' => 'AKIAEXAMPLE',
        'secret_key' => 'verysecret',
    ]);
}

it('explains when backups are not configured', function () {
    $this->inventory['backups'] = ['configured' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('Backups are not set up yet')
        ->assertActionHidden('runBackup')
        ->assertActionVisible('configure');
});

it('shows each destination with its schedule, the last run and the backups', function () {
    offsiteDestination();
    $this->ssh->respondWithJson('inventory', configuredInventory($this->inventory));
    $this->ssh->respondWithJson('backup:list', [[
        'database' => 'shop',
        'local' => [['file' => 'shop-20261027023000.sql.zst', 'size' => 2048, 'modified' => '2026-10-27T02:30:04Z']],
        'remote' => [['file' => 'shop-20261026023000.sql.zst', 'size' => 2000, 'modified' => '2026-10-26T02:30:04Z']],
    ]]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('Every 6 hours, keeps 7')
        ->assertSee('Offsite: weekly, keeps 30')
        ->assertSee('s3://my-backups/prod/srv1/db/')
        ->assertSee('shop-20261027023000.sql.zst')
        ->assertSee('shop-20261026023000.sql.zst')
        ->assertSee('1 local, 1 remote');
});

it('pushes the destination and saves settings through uploaded files so secrets stay off the command line', function () {
    offsiteDestination();
    $this->inventory['backups'] = ['configured' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
    $this->ssh->respondTo('backup:remote --name=offsite --from=', "Remote offsite saved.\n");
    $this->ssh->respondTo('backup:configure --from=', "Backup settings saved.\n");

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('configure', data: [
            'enabled' => true,
            'local' => true,
            'local_frequency' => 'daily',
            'local_keep' => 3,
            'remote' => 'offsite',
            'remote_path' => 'srv1',
            'remote_frequency' => 'custom',
            'remote_cron' => '0 3 * * *',
            'remote_keep' => 10,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Backup settings saved');

    $uploads = collect($this->ssh->uploads)->pluck('contents');
    $remote = $uploads->first(fn (string $contents): bool => str_contains($contents, 'SECRET='));
    $target = $uploads->first(fn (string $contents): bool => str_contains($contents, 'LOCAL_SCHEDULE='));

    expect($remote)->toContain("BUCKET=my-backups\n")
        ->toContain("SECRET=verysecret\n")
        ->and($target)->toContain("ENABLED=true\n")
        ->toContain("LOCAL_SCHEDULE=30 2 * * *\n")
        ->toContain("LOCAL_KEEP=3\n")
        ->toContain("REMOTE=offsite\n")
        ->toContain("REMOTE_SCHEDULE=0 3 * * *\n")
        ->toContain("REMOTE_KEEP=10\n")
        ->toContain("REMOTE_PATH=srv1\n")
        ->not->toContain('verysecret')
        ->and(implode("\n", $this->ssh->commands))->not->toContain('verysecret');
});

it('validates the cron line, subfolder and copies to keep', function () {
    offsiteDestination();
    $this->inventory['backups'] = ['configured' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('configure', data: [
            'enabled' => true,
            'local' => true,
            'local_frequency' => 'daily',
            'local_keep' => -1,
            'remote' => 'offsite',
            'remote_path' => 'bad path!',
            'remote_frequency' => 'custom',
            'remote_cron' => 'whenever',
            'remote_keep' => 10,
        ])
        ->assertHasActionErrors(['local_keep', 'remote_path', 'remote_cron']);

    expect($this->ssh->ran('backup:configure'))->toBeFalse()
        ->and($this->ssh->ran('backup:remote'))->toBeFalse();
});

it('queues a backup run and a restore', function () {
    Queue::fake();
    $this->ssh->respondWithJson('inventory', configuredInventory($this->inventory));
    $this->ssh->respondWithJson('backup:list', [['database' => 'shop', 'local' => [['file' => 'shop-20261027023000.sql.zst', 'size' => 1, 'modified' => '']], 'remote' => []]]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('runBackup')
        ->assertNotified('Backup started');

    Task::query()->update(['status' => TaskStatus::Succeeded]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('restore', data: ['into' => ''], arguments: ['database' => 'shop', 'file' => 'shop-20261027023000.sql.zst'])
        ->assertNotified('Restoring shop-20261027023000.sql.zst');

    $tasks = $this->server->tasks()->orderBy('id')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->type)->toBe(TaskType::Backup)
        ->and((new RunBackup($tasks[0]))->arguments())->toBe('backup:run')
        ->and($tasks[1]->type)->toBe(TaskType::Restore)
        ->and((new RestoreBackup($tasks[1]))->arguments())->toBe('backup:restore --database=shop --file=shop-20261027023000.sql.zst');

    Queue::assertPushed(RunBackup::class);
    Queue::assertPushed(RestoreBackup::class);
});

it('builds restore arguments only from safe values', function () {
    $task = Task::factory()->create(['options' => ['database' => 'shop', 'file' => 'shop-20261027023000.sql.zst', 'into' => 'shop_check']]);
    $bad = Task::factory()->create(['options' => ['database' => 'shop', 'file' => '../../etc/passwd']]);

    expect((new RestoreBackup($task))->arguments())->toBe('backup:restore --database=shop --file=shop-20261027023000.sql.zst --into=shop_check')
        ->and(fn () => (new RestoreBackup($bad))->arguments())->toThrow(InvalidArgumentException::class)
        ->and((new RunBackup(Task::factory()->create(['options' => ['database' => 'x;y']])))->arguments())->toBe('backup:run');
});
