<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Jobs\CreateApp;
use App\Jobs\DeleteApp;
use App\Jobs\DeployApp;
use App\Jobs\RollbackApp;
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
    $this->inventory = json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('inventory', $this->inventory);
});

it('lists the apps from the inventory', function () {
    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('shop')
        ->assertSee('shop.example.com')
        ->assertSee('acme/shop@main')
        ->assertSee('api.example.com')
        ->assertSee('Deployed')
        ->assertSee('Not deployed');
});

it('asks for provisioning first on an unprovisioned server', function () {
    $server = Server::factory()->withPinnedHostKey()->create();

    livewire(ServerApps::class, ['record' => $server->getRouteKey()])
        ->assertSee('Provision this server before creating apps')
        ->assertActionHidden('createApp');
});

it('queues app creation with the chosen options', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: [
            'name' => 'blog',
            'domain' => 'blog.example.com',
            'www' => 'redirect-to-www',
            'aliases' => ['blog.example.org'],
            'php' => '8.5',
            'repo' => 'acme/blog',
            'branch' => 'main',
            'database' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Creating blog');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::CreateApp)
        ->and((new CreateApp($task))->arguments())
        ->toBe('app:create --name=blog --domain=blog.example.com --www=redirect-to-www --alias=blog.example.org --php=8.5 --repo=acme/blog --branch=main');

    Queue::assertPushed(CreateApp::class);
});

it('validates the app name, domain and repository', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: [
            'name' => 'Shop!',
            'domain' => 'not a domain',
            'www' => 'redirect-to-naked',
            'php' => '8.5',
            'repo' => 'git@github.com:acme/shop.git',
            'branch' => 'main',
        ])
        ->assertHasActionErrors(['name', 'domain', 'repo']);

    Queue::assertNothingPushed();
});

it('refuses a name that already exists on the server', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['name' => 'shop', 'domain' => 'x.example.com', 'www' => 'naked-only', 'php' => '8.5', 'branch' => 'main'])
        ->assertHasActionErrors(['name']);
});

it('queues app deletion with what to keep', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteApp', data: ['keep_database' => true, 'keep_files' => false], arguments: ['name' => 'shop'])
        ->assertNotified('Deleting shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::DeleteApp)
        ->and((new DeleteApp($task))->arguments())->toBe('app:delete --name=shop --keep-database');

    Queue::assertPushed(DeleteApp::class);
});

it('ignores a malformed name passed to delete', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteApp', arguments: ['name' => 'shop; rm -rf /']);

    expect($this->server->tasks()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues a deploy for an app with a repository', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deployApp', arguments: ['name' => 'shop'])
        ->assertNotified('Deploying shop');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Deploy)
        ->and((new DeployApp($task))->arguments())->toBe('app:deploy --name=shop');

    Queue::assertPushed(DeployApp::class);
});

it('queues a rollback', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('rollbackApp', arguments: ['name' => 'shop'])
        ->assertNotified('Rolling back shop');

    expect((new RollbackApp($this->server->tasks()->sole()))->arguments())->toBe('app:rollback --name=shop');
    Queue::assertPushed(RollbackApp::class);
});

it('builds deploy and rollback arguments only from safe values', function () {
    $deploy = Task::factory()->create(['options' => ['name' => 'shop', 'branch' => 'release/2', 'commit' => 'abc1234']]);
    $unsafe = Task::factory()->create(['options' => ['name' => 'shop', 'branch' => 'x;y', 'commit' => 'nope']]);
    $rollback = Task::factory()->create(['options' => ['name' => 'shop', 'release' => '20261027110000']]);
    $badRelease = Task::factory()->create(['options' => ['name' => 'shop', 'release' => '../etc']]);

    expect((new DeployApp($deploy))->arguments())->toBe('app:deploy --name=shop --branch=release/2 --commit=abc1234')
        ->and((new DeployApp($unsafe))->arguments())->toBe('app:deploy --name=shop')
        ->and((new RollbackApp($rollback))->arguments())->toBe('app:rollback --name=shop --release=20261027110000')
        ->and((new RollbackApp($badRelease))->arguments())->toBe('app:rollback --name=shop');
});

it('queues a docker app with its image, port and environment', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: [
            'type' => 'docker',
            'name' => 'tool',
            'domain' => 'tool.example.com',
            'www' => 'naked-only',
            'aliases' => [],
            'image' => 'ghcr.io/org/tool:1.2',
            'port' => 8080,
            'env' => "APP_KEY=abc\n\nDEBUG=0",
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Creating tool');

    expect((new CreateApp($this->server->tasks()->sole()))->arguments())
        ->toBe("app:create --name=tool --domain=tool.example.com --www=naked-only --type=docker --image=ghcr.io/org/tool:1.2 --port=8080 --env='APP_KEY=abc' --env='DEBUG=0'");
});

it('rejects a docker app with a bad image or port', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['type' => 'docker', 'name' => 'tool', 'domain' => 'tool.example.com', 'www' => 'naked-only', 'image' => 'evil; rm', 'port' => 99999])
        ->assertHasActionErrors(['image', 'port']);

    Queue::assertNothingPushed();
});
