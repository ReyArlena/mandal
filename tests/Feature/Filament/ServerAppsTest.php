<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Jobs\CreateApp;
use App\Jobs\DeleteApp;
use App\Jobs\DeployApp;
use App\Jobs\RollbackApp;
use App\Models\Server;
use App\Models\SourceCredential;
use App\Models\Task;
use App\Models\User;
use App\Ssh\SshClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;
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
        ->assertSeeHtml('logs?source=laravel%3Ashop')
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

it('leaves the database unchecked by default and passes a given .env along', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('createApp')
        ->assertActionDataSet(['database' => false])
        ->assertSchemaComponentExists('deploy_script')
        ->assertSchemaComponentExists('env_file')
        ->setActionData(['name' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.5', 'env_file' => "APP_NAME=Blog\n"])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $task = $this->server->tasks()->sole();

    expect($task->options['database'])->toBeFalse()
        ->and($task->options['env_file'])->toBe("APP_NAME=Blog\n")
        ->and((new CreateApp($task))->arguments())->toEndWith('--no-database');
});

it('writes no .env option when the environment file is left empty', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['name' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.5', 'env_file' => "  \n"])
        ->assertHasNoActionErrors();

    expect($this->server->tasks()->sole()->options['env_file'])->toBeNull();
});

it('validates the app name, domain and repository', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['name' => 'Shop!', 'domain' => 'not a domain', 'www' => 'redirect-to-naked', 'php' => '8.5', 'branch' => 'main'])
        ->assertHasActionErrors(['name', 'domain']);

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['name' => 'blog', 'domain' => 'blog.example.com', 'www' => 'redirect-to-naked', 'php' => '8.5', 'repo' => 'git@github.com:acme/shop.git', 'branch' => 'main'])
        ->assertHasActionErrors(['repo']);

    Queue::assertNothingPushed();
});

it('checks the name and domain as they are typed and disables Create while they are wrong', function () {
    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('createApp')
        ->setActionData(['name' => 'shop'])
        ->assertHasActionErrors(['name' => 'An app called shop already exists.'])
        ->setActionData(['name' => 'root'])
        ->assertHasActionErrors(['name' => 'root is reserved for the system.'])
        ->setActionData(['name' => 'blog'])
        ->assertHasNoActionErrors(['name'])
        ->setActionData(['domain' => 'www.legacy.example.com'])
        ->assertHasActionErrors(['domain' => 'legacy.example.com is already served by a site on this server.'])
        ->setActionData(['domain' => 'blog.example.com', 'aliases' => ['shop.example.com']])
        ->assertHasActionErrors(['aliases' => 'shop.example.com is already served by a site on this server.'])
        ->assertSeeHtml('disabled');
});

it('refuses a Docker app name an existing compose project uses, and a fixed container_name', function () {
    $inventory = $this->inventory;
    $inventory['docker']['projects'] = [['Name' => 'valheim', 'Status' => 'running(1)', 'ConfigFiles' => '/opt/valheim/compose.yml']];
    $this->ssh->respondWithJson('inventory', $inventory);

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('createApp')
        ->setActionData(['type' => 'docker'])
        ->setActionData(['name' => 'valheim'])
        ->assertHasActionErrors(['name' => 'A Docker compose project called valheim already runs on this server.'])
        ->setActionData(['compose' => "services:\n  game:\n    image: x\n    container_name: game-server\n"])
        ->assertHasActionErrors(['compose']);
});

it('asks for ntsync before a compose file that passes /dev/ntsync', function () {
    $inventory = $this->inventory;
    $inventory['system']['ntsync'] = ['supported' => true, 'active' => false, 'persistent' => false];
    $this->ssh->respondWithJson('inventory', $inventory);
    $compose = "services:\n  game:\n    image: x\n    devices:\n      - /dev/ntsync:/dev/ntsync\n";

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('createApp')
        ->setActionData(['type' => 'docker', 'compose' => $compose])
        ->assertHasActionErrors(['compose' => 'This file passes /dev/ntsync, which this server does not have yet. Enable ntsync on the Server tab first.'])
        ->setActionData(['compose' => ServerApps::COMPOSE_TEMPLATE])
        ->assertHasNoActionErrors(['compose']);

    expect(ServerApps::composeProblem($compose, ['supported' => true, 'active' => true, 'persistent' => true]))->toBeNull()
        ->and(ServerApps::composeProblem($compose, ['supported' => false, 'active' => false, 'persistent' => false]))->toContain('needs Linux 6.14 or newer')
        ->and(ServerApps::composeProblem($compose))->toBeNull();
});

it('refuses a Laravel app name whose database already exists', function () {
    $inventory = $this->inventory;
    $inventory['database']['databases'] = ['old_blog'];
    $this->ssh->respondWithJson('inventory', $inventory);

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('createApp')
        ->setActionData(['name' => 'old-blog'])
        ->assertHasActionErrors(['name' => 'A database called old_blog already exists; the app would need it.']);
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

it('offers to keep only the project folder when deleting a Docker app', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('deleteApp', arguments: ['name' => 'game', 'type' => 'docker'])
        ->assertSchemaComponentExists('keep_files')
        ->assertSchemaComponentDoesNotExist('keep_database')
        ->fillForm(['keep_files' => true])
        ->callMountedAction();

    expect((new DeleteApp($this->server->tasks()->sole()))->arguments())->toBe('app:delete --name=game --keep-files');
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

it('queues a docker app behind nginx with its compose file, upstream port and environment', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: [
            'type' => 'docker',
            'name' => 'tool',
            'compose' => "services:\n  app:\n    image: ghcr.io/org/tool:1.2\n    ports:\n      - \"127.0.0.1:8080:80\"\n",
            'proxy' => true,
            'port' => 8080,
            'domain' => 'tool.example.com',
            'www' => 'naked-only',
            'aliases' => [],
            'env' => "APP_KEY=abc\n\nDEBUG=0",
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Creating tool');

    $task = $this->server->tasks()->sole();

    expect((new CreateApp($task))->arguments())
        ->toBe("app:create --name=tool --domain=tool.example.com --www=naked-only --type=docker --proxy --port=8080 --env='APP_KEY=abc' --env='DEBUG=0'")
        ->and($task->options['compose'])->toContain('ghcr.io/org/tool:1.2');
});

it('queues a docker app without nginx and without a domain', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: [
            'type' => 'docker',
            'name' => 'enshrouded',
            'compose' => "services:\n  game:\n    image: sknnr/enshrouded-dedicated-server:latest\n    ports:\n      - \"15637:15637/udp\"\n",
            'proxy' => false,
            'env' => '',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Creating enshrouded');

    expect((new CreateApp($this->server->tasks()->sole()))->arguments())->toBe('app:create --name=enshrouded --type=docker');
});

it('rejects a docker app without a compose file, or with a proxy but no port or domain', function () {
    Queue::fake();

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['type' => 'docker', 'name' => 'tool', 'compose' => '', 'proxy' => false])
        ->assertHasActionErrors(['compose']);

    livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createApp', data: ['type' => 'docker', 'name' => 'tool', 'compose' => "services:\n  app:\n    image: x\n", 'proxy' => true, 'port' => null, 'domain' => ''])
        ->assertHasActionErrors(['port', 'domain']);

    Queue::assertNothingPushed();
});

describe('choosing the repository', function () {
    it('lists the repositories the GitHub token can see and accepts any typed owner/name', function () {
        SourceCredential::factory()->create(['account' => 'acme']);
        Http::fake(['api.github.com/user/repos*' => Http::response([['full_name' => 'acme/shop'], ['full_name' => 'acme/blog'], ['full_name' => 'Acme/Shop-Admin']])]);

        $page = livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])->instance();

        expect($page->repositoryOptions())->toBe(['acme/shop' => 'acme/shop', 'acme/blog' => 'acme/blog', 'Acme/Shop-Admin' => 'Acme/Shop-Admin'])
            ->and($page->repositoryOptions('shop'))->toBe(['acme/shop' => 'acme/shop', 'Acme/Shop-Admin' => 'Acme/Shop-Admin'])
            ->and($page->repositoryOptions('laravel/laravel'))->toBe(['laravel/laravel' => 'laravel/laravel'])
            ->and($page->repositoryOptions('acme/shop'))->toBe(['acme/shop' => 'acme/shop', 'Acme/Shop-Admin' => 'Acme/Shop-Admin']);

        Http::assertSentCount(1);

        livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
            ->mountAction('createApp')
            ->assertSchemaComponentExists('repo', checkComponentUsing: fn ($component): bool => $component instanceof Select && $component->isSearchable());
    });

    it('keeps a text field without a GitHub token', function () {
        livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])
            ->mountAction('createApp')
            ->assertActionMounted('createApp')
            ->assertSchemaComponentExists('repo', checkComponentUsing: fn ($component): bool => $component instanceof TextInput);
    });

    it('does not cache a failed repository list', function () {
        SourceCredential::factory()->create(['account' => 'acme']);
        Http::fakeSequence('api.github.com/user/repos*')->push(['message' => 'oops'], 500)->push([['full_name' => 'acme/shop']]);

        $page = livewire(ServerApps::class, ['record' => $this->server->getRouteKey()])->instance();

        expect($page->repositoryOptions())->toBe([])
            ->and($page->repositoryOptions())->toBe(['acme/shop' => 'acme/shop']);
    });
});
