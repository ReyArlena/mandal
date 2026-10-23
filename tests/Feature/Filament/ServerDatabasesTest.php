<?php

use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Filament\Resources\Servers\Pages\ServerDatabases;
use App\Models\Server;
use App\Models\User;
use App\Ssh\SshClient;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('db:list', [
        'driver' => 'mariadb',
        'installed' => true,
        'active' => 'active',
        'version' => '11.8.6',
        'databases' => [
            ['name' => 'reports', 'size' => 16384, 'tables' => 3, 'app' => null],
            ['name' => 'shop', 'size' => 49152, 'tables' => 12, 'app' => 'shop'],
        ],
        'users' => [
            ['user' => 'reporter', 'host' => 'localhost', 'databases' => ['reports'], 'app' => null],
            ['user' => 'shop', 'host' => 'localhost', 'databases' => ['shop'], 'app' => 'shop'],
            ['user' => 'legacy', 'host' => '10.0.0.5', 'databases' => [], 'app' => null],
        ],
    ]);
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
});

it('lists databases and users with their access', function () {
    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('reports')
        ->assertSee('16 KB, 3 tables')
        ->assertSee('app shop')
        ->assertSee('reporter')
        ->assertSee('Not managed by Mandal');
});

it('formats sizes, including empty databases', function () {
    $page = new ServerDatabases;

    expect($page->bytes(0))->toBe('0 B')
        ->and($page->bytes(512))->toBe('512 B')
        ->and($page->bytes(16384))->toBe('16 KB')
        ->and($page->bytes(1536))->toBe('1.5 KB')
        ->and($page->bytes(10 * 1024 * 1024))->toBe('10 MB');
});

it('offers delete only for databases and users no app uses', function () {
    $html = livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])->html();

    expect($html)
        ->toContain("mountAction('deleteDatabase', JSON.parse('{\\u0022name\\u0022:\\u0022reports\\u0022}')")
        ->not->toContain("mountAction('deleteDatabase', JSON.parse('{\\u0022name\\u0022:\\u0022shop\\u0022}')")
        ->toContain("mountAction('deleteUser', JSON.parse('{\\u0022name\\u0022:\\u0022reporter\\u0022}')")
        ->not->toContain("mountAction('deleteUser', JSON.parse('{\\u0022name\\u0022:\\u0022shop\\u0022}')");
});

it('creates a database with the chosen users', function () {
    $this->ssh->respondTo('db:create', "Database metrics created.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createDatabase', data: ['name' => 'metrics', 'users' => ['reporter']])
        ->assertHasNoActionErrors()
        ->assertNotified('Database metrics created');

    expect($this->ssh->ran('db:create --name=metrics --user=reporter'))->toBeTrue();
});

it('refuses a database name that already exists or is invalid', function () {
    foreach (['reports', 'bad;name'] as $name) {
        livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
            ->callAction('createDatabase', data: ['name' => $name])
            ->assertHasActionErrors(['name']);
    }

    expect($this->ssh->ran('db:create'))->toBeFalse();
});

it('creates a user with the password in an uploaded file', function () {
    $this->ssh->respondTo('db:user:create', "User analyst created.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createUser', data: ['name' => 'analyst', 'password' => 'Str0ng-and-long-enough', 'databases' => ['reports']])
        ->assertHasNoActionErrors()
        ->assertNotified('User analyst created');

    $command = collect($this->ssh->commands)->first(fn (string $command): bool => str_contains($command, 'db:user:create'));

    expect($command)->toContain('db:user:create --name=analyst --database=reports --from=')
        ->not->toContain('Str0ng-and-long-enough')
        ->and(collect($this->ssh->uploads)->first()['contents'])->toBe("PASSWORD=Str0ng-and-long-enough\n")
        ->and(collect($this->ssh->uploads)->first()['mode'])->toBe(0600);
});

it('rejects a password the server would refuse', function () {
    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('createUser', data: ['name' => 'analyst', 'password' => "it's short"])
        ->assertHasActionErrors(['password']);

    expect($this->ssh->uploads)->toBeEmpty();
});

it('sets who can use a database', function () {
    $this->ssh->respondTo('db:access', "Access to reports updated.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('access', ['name' => 'reports'])
        ->assertActionDataSet(['users' => ['reporter']])
        ->setActionData(['users' => ['reporter', 'shop']])
        ->callMountedAction()
        ->assertNotified('Access to reports saved');

    expect($this->ssh->ran('db:access --name=reports --user=reporter --user=shop'))->toBeTrue();
});

it('updates a user without a new password over the command line', function () {
    $this->ssh->respondTo('db:user:update', "User reporter updated.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('editUser', arguments: ['name' => 'reporter'], data: ['databases' => ['reports', 'shop'], 'password' => null])
        ->assertNotified('User reporter saved');

    expect($this->ssh->ran('db:user:update --name=reporter --database=reports --database=shop'))->toBeTrue()
        ->and($this->ssh->uploads)->toBeEmpty();
});

it('uploads a new password when one is given', function () {
    $this->ssh->respondTo('db:user:update', "User reporter updated.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('editUser', arguments: ['name' => 'reporter'], data: ['databases' => ['reports'], 'password' => 'Another-l0ng-password']);

    expect($this->ssh->ran('db:user:update --name=reporter --database=reports --from='))->toBeTrue()
        ->and(collect($this->ssh->uploads)->first()['contents'])->toBe("PASSWORD=Another-l0ng-password\n");
});

it('deletes a database and a user', function () {
    $this->ssh->respondTo('db:delete', "Database reports deleted.\n");
    $this->ssh->respondTo('db:user:delete', "User reporter deleted.\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteDatabase', arguments: ['name' => 'reports'])
        ->assertNotified('Database reports deleted')
        ->callAction('deleteUser', arguments: ['name' => 'reporter'])
        ->assertNotified('User reporter deleted');

    expect($this->ssh->ran('db:delete --name=reports'))->toBeTrue()
        ->and($this->ssh->ran('db:user:delete --name=reporter'))->toBeTrue();
});

it('shows the server refusal when it says no', function () {
    $this->ssh->respondTo('db:delete', '', exitCode: 2, errorOutput: "error: app 'shop' uses database 'shop'; delete the app instead\n");

    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteDatabase', arguments: ['name' => 'shop'])
        ->assertNotified('Could not delete the database');
});

it('ignores names that could not have come from the page', function () {
    livewire(ServerDatabases::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteDatabase', arguments: ['name' => 'x; rm -rf /']);

    expect($this->ssh->ran('db:delete'))->toBeFalse();
});

it('explains when the server is not provisioned', function () {
    $server = Server::factory()->create();

    livewire(ServerDatabases::class, ['record' => $server->getRouteKey()])
        ->assertSee('Provision this server before managing its databases.')
        ->assertActionHidden('createDatabase');

    expect($this->ssh->commands)->toBeEmpty();
});

it('links the databases and backups tabs', function () {
    $this->ssh->respondWithJson('inventory', ['backups' => ['configured' => false]]);

    livewire(ServerBackups::class, ['record' => $this->server->getRouteKey()])
        ->assertSee("/servers/{$this->server->getKey()}/databases")
        ->assertSee('Databases');
});
