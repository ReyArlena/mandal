<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerDatabases;
use App\Jobs\ImportDatabase;
use App\Jobs\RunBackup;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Ssh\SshClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('db:list', [
        'installed' => true,
        'active' => 'active',
        'driver' => 'mariadb',
        'databases' => [['name' => 'shop', 'app' => 'shop', 'size' => 49152, 'tables' => 12]],
        'users' => [],
    ]);
});

it('offers import and both export formats for each database', function () {
    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('Import')
        ->assertSeeHtml("/servers/{$this->server->id}/databases/shop/export/sql")
        ->assertSeeHtml("/servers/{$this->server->id}/databases/shop/export/zst");
});

it('queues an import with the uploaded dump', function () {
    Queue::fake();
    Storage::fake('local');

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('import', data: [
            'file' => UploadedFile::fake()->createWithContent('shop.sql', "CREATE TABLE t (id int);\n"),
            'replace' => true,
        ], arguments: ['name' => 'shop'])
        ->assertHasNoActionErrors()
        ->assertNotified('Importing into shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::ImportDatabase)
        ->and($task->options['database'])->toBe('shop')
        ->and($task->options['file_name'])->toBe('shop.sql')
        ->and($task->options['replace'])->toBeTrue()
        ->and(Storage::disk('local')->exists($task->options['file']))->toBeTrue();

    Queue::assertPushed(ImportDatabase::class);
});

it('backs up one database when backups are set up', function () {
    Queue::fake();
    $this->ssh->respondWithJson('inventory', ['backups' => ['configured' => true]]);

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->assertActionEnabled(TestAction::make('backupDatabase')->arguments(['name' => 'shop']))
        ->callAction('backupDatabase', arguments: ['name' => 'shop'])
        ->assertNotified('Backing up shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Backup)
        ->and((new RunBackup($task))->arguments())->toBe('backup:run --database=shop');

    Queue::assertPushed(RunBackup::class);
});

it('disables the database backup until backups are set up', function () {
    $this->ssh->respondWithJson('inventory', ['backups' => ['configured' => false]]);

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->assertActionDisabled(TestAction::make('backupDatabase')->arguments(['name' => 'shop']));
});

it('refuses files that are not dumps', function () {
    Storage::fake('local');

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('import', data: ['file' => UploadedFile::fake()->createWithContent('shop.zip', 'PK')], arguments: ['name' => 'shop'])
        ->assertHasActionErrors(['file']);
});

it('uploads only the dump, runs db:import with it and removes the panel copy', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/upload.sql', "CREATE TABLE t (id int);\n");
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
    $this->ssh->respondTo('db:import', "Imported into shop.\n");

    $task = Task::factory()->for($this->server)->create(['type' => TaskType::ImportDatabase, 'options' => [
        'database' => 'shop', 'file' => 'imports/upload.sql', 'file_name' => 'shop.sql', 'replace' => true,
    ]]);

    (new ImportDatabase($task))->handle(app(MandalClient::class));

    $task->refresh();
    $command = collect($this->ssh->commands)->first(fn (string $c): bool => str_contains($c, 'db:import'));

    expect($task->status)->toBe(TaskStatus::Succeeded)
        ->and($task->output)->toContain('Uploading shop.sql')
        ->and($command)->toContain('db:import --name=shop --replace')
        ->toContain("--file='/home/mandal/.mandal/")
        ->not->toContain('--from=')
        ->and($this->ssh->uploads)->toHaveCount(1)
        ->and(array_values($this->ssh->uploads)[0]['contents'])->toBe("CREATE TABLE t (id int);\n")
        ->and(Storage::disk('local')->exists('imports/upload.sql'))->toBeFalse();
});

it('fails the import when the upload is gone', function () {
    Storage::fake('local');
    $task = Task::factory()->for($this->server)->create(['type' => TaskType::ImportDatabase, 'options' => [
        'database' => 'shop', 'file' => 'imports/missing.sql', 'file_name' => 'shop.sql',
    ]]);

    (new ImportDatabase($task))->handle(app(MandalClient::class));

    expect($task->refresh()->exit_code)->toBe(255)
        ->and($task->output)->toContain('no longer on the panel')
        ->and($this->ssh->ran('db:import'))->toBeFalse();
});

// The body streams with output buffering switched off, which a test cannot
// capture; the headers show what the browser saves.
it('streams an export named like a backup', function (string $format, string $extension, string $type) {
    $this->withoutMiddleware(Filament::getPanel('admin')->getMultiFactorAuthenticationRequiredMiddleware());

    $response = $this->get("/servers/{$this->server->id}/databases/shop/export/{$format}");

    $response->assertOk()
        ->assertHeader('Content-Type', $type)
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="shop-\d{14}'.preg_quote($extension).'"$/');
})->with([
    'plain' => ['sql', '.sql', 'application/sql; charset=utf-8'],
    'compressed' => ['zst', '.sql.zst', 'application/zstd'],
]);

it('refuses export formats and names it does not know', function () {
    $this->withoutMiddleware(Filament::getPanel('admin')->getMultiFactorAuthenticationRequiredMiddleware());

    $this->get("/servers/{$this->server->id}/databases/shop/export/tar")->assertNotFound();
    $this->get("/servers/{$this->server->id}/databases/Shop;drop/export/sql")->assertNotFound();
});
