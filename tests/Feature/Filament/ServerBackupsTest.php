<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Jobs\RestoreBackup;
use App\Jobs\RunBackup;
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
        'schedule' => '30 2 * * *',
        'keep_local' => 7,
        'keep_remote' => 30,
        'remote' => ['enabled' => true, 'bucket' => 'my-backups', 'path' => 'srv1', 'endpoint' => 'https://s3.example.com'],
        'last_run' => ['at' => '2026-10-27T02:30:04Z', 'status' => 'ok', 'rest' => 'shop /var/backups/mandal/db/shop/shop-20261027023000.sql.zst 2048'],
        'databases' => ['shop'],
    ];

    return $inventory;
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

it('shows the schedule, last run and backups', function () {
    $this->ssh->respondWithJson('inventory', configuredInventory($this->inventory));
    $this->ssh->respondWithJson('backup:list', [[
        'database' => 'shop',
        'local' => [['file' => 'shop-20261027023000.sql.zst', 'size' => 2048, 'modified' => '2026-10-27T02:30:04Z']],
        'remote' => [['file' => 'shop-20261026023000.sql.zst', 'size' => 2000, 'modified' => '2026-10-26T02:30:04Z']],
    ]]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('30 2 * * *')
        ->assertSee('my-backups/srv1')
        ->assertSee('shop-20261027023000.sql.zst')
        ->assertSee('shop-20261026023000.sql.zst')
        ->assertSee('1 local, 1 remote');
});

it('saves settings through an uploaded file so secrets stay off the command line', function () {
    $this->inventory['backups'] = ['configured' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
    $this->ssh->respondTo('backup:configure --from=', "Backups run at \"0 3 * * *\".\n");

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('configure', data: [
            'schedule' => '0 3 * * *',
            'keep_local' => 3,
            'keep_remote' => 10,
            'remote' => true,
            's3_endpoint' => 'https://s3.example.com',
            's3_bucket' => 'my-backups',
            's3_path' => 'srv1',
            's3_region' => 'auto',
            's3_key' => 'AKIAEXAMPLE',
            's3_secret' => 'verysecret',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Backup settings saved');

    $upload = collect($this->ssh->uploads)->first();

    expect($upload['contents'])->toContain("SCHEDULE=0 3 * * *\n")
        ->toContain("REMOTE=true\n")
        ->toContain("S3_SECRET=verysecret\n")
        ->and(implode("\n", $this->ssh->commands))->not->toContain('verysecret');
});

it('validates the schedule and bucket', function () {
    $this->inventory['backups'] = ['configured' => false];
    $this->ssh->respondWithJson('inventory', $this->inventory);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('configure', data: ['schedule' => 'whenever', 'keep_local' => 3, 'keep_remote' => 3, 'remote' => true, 's3_endpoint' => 'https://s3.example.com', 's3_bucket' => 'Bad Bucket'])
        ->assertHasActionErrors(['schedule', 's3_bucket']);

    expect($this->ssh->ran('backup:configure'))->toBeFalse();
});

it('queues a backup run and a restore', function () {
    Queue::fake();
    $this->ssh->respondWithJson('inventory', configuredInventory($this->inventory));
    $this->ssh->respondWithJson('backup:list', [['database' => 'shop', 'local' => [['file' => 'shop-20261027023000.sql.zst', 'size' => 1, 'modified' => '']], 'remote' => []]]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->callAction('runBackup')
        ->assertNotified('Backup started');

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
