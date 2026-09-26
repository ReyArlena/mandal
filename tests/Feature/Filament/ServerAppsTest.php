<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Jobs\CreateApp;
use App\Jobs\DeleteApp;
use App\Models\Server;
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
