<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Jobs\CreateApp;
use App\Mandal\ComposeImport;
use App\Mandal\InvalidImport;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Ssh\SshClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

const COMPOSE = "services:\n  game:\n    image: sknnr/enshrouded-dedicated-server:latest\n";

beforeEach(function () {
    $this->dir = storage_path('framework/testing/imports-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * @param  array<string, string|null>  $entries  name => contents, null for a folder
 */
function makeZip(string $path, array $entries, array $links = []): string
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $contents === null ? $zip->addEmptyDir($name) : $zip->addFromString($name, $contents);
    }

    foreach ($links as $name => $target) {
        $zip->addFromString($name, $target);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }

    $zip->close();

    return $path;
}

/**
 * @param  array<string, string>  $files
 */
function makeTarGz(string $dir, string $path, array $files): string
{
    $source = $dir.'/src-'.uniqid();

    foreach ($files as $name => $contents) {
        File::ensureDirectoryExists(dirname($source.'/'.$name));
        File::put($source.'/'.$name, $contents);
    }

    (new Process(['tar', '-czf', $path, '-C', $source, '.']))->mustRun();
    File::deleteDirectory($source);

    return $path;
}

function tarNames(string $path): array
{
    $names = explode("\n", trim((new Process(['tar', '-tzf', $path]))->mustRun()->getOutput()));
    sort($names);

    return $names;
}

it('reads a zip with the compose file at the top', function () {
    $env = "# settings\nSERVER_NAME=Mandal\n\nlowercase=ignored\n";
    $zip = makeZip($this->dir.'/game.zip', [
        'docker-compose.yml' => COMPOSE,
        'enshrouded_server.json' => '{}',
        'savegame/' => null,
        'savegame/world0.ksd' => 'world',
        '.env' => $env,
    ]);

    $import = ComposeImport::inspect($zip);

    expect($import->format)->toBe('zip')
        ->and($import->prefix)->toBe('')
        ->and($import->composeName)->toBe('docker-compose.yml')
        ->and($import->compose)->toBe(COMPOSE)
        ->and($import->envLines())->toBe('SERVER_NAME=Mandal')
        ->and($import->files)->toBe(4)
        ->and($import->bytes)->toBe(strlen(COMPOSE) + strlen('{}') + strlen('world') + strlen($env));
});

it('finds the compose file inside a single top-level folder and prefers Compose\'s own names', function () {
    $zip = makeZip($this->dir.'/game.zip', [
        'enshrouded/' => null,
        'enshrouded/docker-compose.yml' => 'services: {old: {image: x}}',
        'enshrouded/compose.yaml' => COMPOSE,
        'enshrouded/savegame/world0.ksd' => 'world',
        '__MACOSX/enshrouded/._compose.yaml' => 'junk',
        'enshrouded/.DS_Store' => 'junk',
    ]);

    $import = ComposeImport::inspect($zip);

    expect($import->prefix)->toBe('enshrouded/')
        ->and($import->composeName)->toBe('compose.yaml')
        ->and($import->compose)->toBe(COMPOSE);
});

it('reads a .tar.gz the same way', function () {
    $tar = makeTarGz($this->dir, $this->dir.'/game.tar.gz', ['compose.yml' => COMPOSE, 'data/save.bin' => 'save']);

    $import = ComposeImport::inspect($tar);

    expect($import->format)->toBe('tar.gz')
        ->and($import->composeName)->toBe('compose.yml')
        ->and($import->compose)->toBe(COMPOSE)
        ->and($import->files)->toBe(2);
});

it('repacks the files without the compose file and .env, relative to the project folder', function () {
    $zip = makeZip($this->dir.'/game.zip', [
        'enshrouded/docker-compose.yml' => COMPOSE,
        'enshrouded/.env' => 'A=1',
        'enshrouded/savegame/world0.ksd' => 'world',
        'enshrouded/server.json' => '{}',
    ]);

    $size = ComposeImport::inspect($zip)->repack($this->dir.'/files.tar.gz');

    expect($size)->toBeGreaterThan(0)
        ->and(tarNames($this->dir.'/files.tar.gz'))->toBe(['./', './savegame/', './savegame/world0.ksd', './server.json']);
});

it('refuses archives that cannot be imported', function (array $entries, array $links, string $message) {
    $zip = makeZip($this->dir.'/bad.zip', $entries, $links);

    expect(fn () => ComposeImport::inspect($zip))->toThrow(InvalidImport::class, $message);
})->with([
    'no compose file' => [['readme.txt' => 'hi'], [], 'No compose file at the top of the archive'],
    'a path outside the folder' => [['compose.yml' => COMPOSE, '../escape.txt' => 'x'], [], 'a path outside its folder'],
    'an absolute path' => [['compose.yml' => COMPOSE, '/etc/cron.d/x' => 'x'], [], 'cannot be imported'],
    'a symlink' => [['compose.yml' => COMPOSE], ['savegame' => '/etc'], 'holds a link'],
    'an empty compose file' => [['compose.yml' => '  '], [], 'compose.yml is empty'],
]);

it('refuses a file that is not an archive', function () {
    File::put($this->dir.'/notes.zip', 'just text');

    expect(fn () => ComposeImport::inspect($this->dir.'/notes.zip'))->toThrow(InvalidImport::class, 'not a .zip or .tar.gz');
});

it('refuses an archive that unpacks to more than the limit', function () {
    config(['mandal.imports.max_unpacked' => '1K']);
    $zip = makeZip($this->dir.'/big.zip', ['compose.yml' => COMPOSE, 'data.bin' => str_repeat('a', 4096)]);

    expect(fn () => ComposeImport::inspect($zip))->toThrow(InvalidImport::class, 'more than the 1 KB allowed');
});

it('falls back to the default limits when the settings are missing', function () {
    config(['mandal.imports' => null]);

    expect(ComposeImport::configuredUploadBytes())->toBe(1073741824)
        ->and(ComposeImport::inspect(makeZip($this->dir.'/game.zip', ['compose.yml' => COMPOSE]))->composeName)->toBe('compose.yml');
});

it('converts sizes and caps uploads at the smallest limit', function () {
    config(['mandal.imports.max_upload' => '1K']);

    expect(ComposeImport::bytes('1G'))->toBe(1073741824)
        ->and(ComposeImport::bytes('512m'))->toBe(536870912)
        ->and(ComposeImport::bytes('2048'))->toBe(2048)
        ->and(ComposeImport::human(19134385))->toBe('18.2 MB')
        ->and(ComposeImport::maxUploadBytes())->toBe(1024);
});

describe('creating an app from an import', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        $this->ssh = new FakeSshClient;
        $this->app->instance(SshClient::class, $this->ssh);
        $this->server = Server::factory()->provisioned()->create();
        $this->ssh->respondWithJson('inventory', json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true));
    });

    it('fills the compose file, environment and name from an uploaded backup', function () {
        $zip = makeZip($this->dir.'/enshrouded.zip', ['docker-compose.yml' => COMPOSE, '.env' => 'SERVER_NAME=Mandal', 'savegame/world0.ksd' => 'world']);

        livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
            ->mountAction('createApp')
            ->setActionData(['type' => 'docker', 'name' => ''])
            ->setActionData(['import' => UploadedFile::fake()->createWithContent('enshrouded.zip', file_get_contents($zip))])
            ->assertHasNoActionErrors()
            ->assertActionDataSet(['compose' => COMPOSE, 'env' => 'SERVER_NAME=Mandal', 'name' => 'enshrouded']);
    });

    it('shows why an uploaded backup cannot be imported', function () {
        $zip = makeZip($this->dir.'/broken.zip', ['readme.txt' => 'hi']);

        livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
            ->mountAction('createApp')
            ->setActionData(['type' => 'docker'])
            ->setActionData(['import' => UploadedFile::fake()->createWithContent('broken.zip', file_get_contents($zip))])
            ->assertHasActionErrors(['import']);
    });

    it('queues the app with the stored upload', function () {
        Queue::fake();
        Storage::fake('local');
        $zip = makeZip($this->dir.'/enshrouded.zip', ['docker-compose.yml' => COMPOSE, 'savegame/world0.ksd' => 'world']);

        livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
            ->mountAction('createApp')
            ->setActionData(['type' => 'docker', 'name' => 'enshrouded', 'proxy' => false])
            ->setActionData(['import' => UploadedFile::fake()->createWithContent('enshrouded.zip', file_get_contents($zip))])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Creating enshrouded');

        $options = $this->server->tasks()->sole()->options;

        expect($options['import'])->toStartWith('imports/')
            ->and($options['import_name'])->toBe('enshrouded.zip')
            ->and(Storage::disk('local')->exists($options['import']))->toBeTrue();
    });

    it('uploads the repacked files with the compose file and removes both copies afterwards', function () {
        Storage::fake('local');
        $zip = makeZip($this->dir.'/enshrouded.zip', ['enshrouded/docker-compose.yml' => COMPOSE, 'enshrouded/savegame/world0.ksd' => 'world']);
        Storage::disk('local')->put('imports/upload.zip', file_get_contents($zip));
        $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
        $this->ssh->respondTo('app:create', "Docker app enshrouded created.\n");

        $task = Task::factory()->for($this->server)->create(['type' => TaskType::CreateApp, 'options' => [
            'type' => 'docker', 'name' => 'enshrouded', 'compose' => COMPOSE, 'proxy' => false, 'env' => [],
            'import' => 'imports/upload.zip', 'import_name' => 'enshrouded.zip',
        ]]);

        (new CreateApp($task))->handle(app(MandalClient::class));

        $task->refresh();
        $command = collect($this->ssh->commands)->first(fn (string $c): bool => str_contains($c, 'app:create'));
        $files = collect($this->ssh->uploads)->filter(fn (array $upload, string $path): bool => str_ends_with($path, '-files.tar.gz'));
        File::put($this->dir.'/uploaded.tar.gz', $files->first()['contents']);

        expect($task->status)->toBe(TaskStatus::Succeeded)
            ->and($task->output)->toContain('Reading enshrouded.zip')
            ->toContain('Packing 2 files')
            ->toContain('Uploading: 100%')
            ->and($command)->toContain("--from='/home/mandal/.mandal/")
            ->toContain("--files='/home/mandal/.mandal/")
            ->and($files)->toHaveCount(1)
            ->and(tarNames($this->dir.'/uploaded.tar.gz'))->toBe(['./', './savegame/', './savegame/world0.ksd'])
            ->and(Storage::disk('local')->exists('imports/upload.zip'))->toBeFalse()
            ->and(File::exists(storage_path('app/private/imports/task-'.$task->id.'-files.tar.gz')))->toBeFalse();
    });

    it('fails the task when the upload is gone', function () {
        Storage::fake('local');
        $task = Task::factory()->for($this->server)->create(['type' => TaskType::CreateApp, 'options' => [
            'type' => 'docker', 'name' => 'enshrouded', 'compose' => COMPOSE, 'proxy' => false, 'env' => [],
            'import' => 'imports/missing.zip', 'import_name' => 'enshrouded.zip',
        ]]);

        (new CreateApp($task))->handle(app(MandalClient::class));

        $task->refresh();

        expect($task->exit_code)->toBe(255)
            ->and($task->output)->toContain('no longer on the panel')
            ->and($this->ssh->ran('app:create'))->toBeFalse();
    });
});
