<?php

use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CliUpdateRequired;
use App\Ssh\SshClient;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->users = User::factory()->count(2)->create();
    $this->actingAs($this->users->first());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->hash = app(CliArchive::class)->hash();
});

it('records the build on version and inventory reads', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('version --json', ['version' => '0.0.9', 'build' => 'release00001']);
    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => $this->hash]]);

    app(MandalClient::class)->version($server);
    expect($server->fresh()->cli_build)->toBe('release00001');

    app(MandalClient::class)->inventory($server, fresh: true);
    expect($server->fresh()->cli_build)->toBe($this->hash);
});

it('notifies every user once while a server is outdated and clears the notice when it catches up', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $this->ssh->respondWithJson('version --json', ['version' => '0.0.9', 'build' => 'release00001']);

    app(MandalClient::class)->version($server);
    app(MandalClient::class)->version($server);

    $notices = DatabaseNotification::query()->where('type', CliUpdateRequired::class)->get();

    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('notifiable_id')->sort()->values()->all())->toBe($this->users->pluck('id')->sort()->values()->all())
        ->and($notices->first()->data['title'])->toBe("CLI update required on {$server->name}")
        ->and($notices->first()->data['server_id'])->toBe($server->id)
        ->and($notices->first()->data['actions'][0]['url'])->toContain('/servers');

    $this->ssh->respondWithJson('version --json', ['version' => '0.1.0', 'build' => $this->hash]);
    app(MandalClient::class)->version($server);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('checks every connected server from the scheduled command', function () {
    $outdated = Server::factory()->withPinnedHostKey()->create(['name' => 'old-box']);
    Server::factory()->create(['name' => 'never-connected']);
    $this->ssh->respondWithJson('version --json', ['version' => '0.0.9', 'build' => 'release00001']);

    $this->artisan('mandal:check-updates')
        ->expectsOutputToContain('old-box runs CLI build release00001')
        ->assertSuccessful();

    expect($outdated->fresh()->cli_build)->toBe('release00001')
        ->and(DatabaseNotification::query()->count())->toBe(2)
        ->and(count(array_filter($this->ssh->commands, fn (string $c): bool => str_contains($c, 'version --json'))))->toBe(1);
});

it('flags outdated servers in the list without connecting to them', function () {
    Server::factory()->withPinnedHostKey()->create(['cli_build' => 'release00001']);
    Server::factory()->withPinnedHostKey()->create(['cli_build' => $this->hash]);
    Server::factory()->create(['cli_build' => null]);

    livewire(ListServers::class)
        ->assertSee('Update required')
        ->assertSee('Current');

    expect($this->ssh->commands)->toBe([]);
});

it('offers the update on the server page from a fresh read of the build', function () {
    $server = Server::factory()->withPinnedHostKey()->create(['cli_build' => $this->hash]);
    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => 'release00001']]);

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])->assertActionVisible('updateCli');

    expect($server->fresh()->cli_build)->toBe('release00001');
});

it('clears the notice right after a successful CLI update', function () {
    $server = Server::factory()->withPinnedHostKey()->create(['cli_build' => 'release00001']);
    $this->users->first()->notify(new CliUpdateRequired($server, '0.1.0'));
    $this->ssh->respondTo('cli:update', "Installed mandal 0.1.0.\n");
    $this->ssh->respondTo('printf %s "$HOME"', '/home/mandal');
    $this->ssh->respondWithJson('version --json', ['version' => '0.1.0', 'build' => $this->hash]);
    $task = Task::factory()->for($server)->create(['options' => []]);

    (new UpdateCli($task))->handle(new MandalClient($this->ssh));

    expect($server->fresh()->cli_build)->toBe($this->hash)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});
