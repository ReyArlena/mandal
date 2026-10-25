<?php

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerPhp;
use App\Jobs\ManagePhp;
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
    $this->ssh->respondWithJson('firewall:list', [
        'installed' => true, 'status' => 'active', 'default_incoming' => 'deny', 'default_outgoing' => 'allow',
        'rules' => [
            ['number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'ipv6' => false, 'comment' => 'SSH'],
            ['number' => 2, 'to' => '3306', 'action' => 'ALLOW IN', 'from' => '10.0.0.0/8', 'ipv6' => false, 'comment' => ''],
        ],
        'docker' => ['active' => true, 'exposed' => [
            ['container' => 'enshrouded-server', 'host' => '0.0.0.0', 'port' => '15637', 'target' => '15637', 'protocol' => 'udp'],
        ]],
    ]);
    $this->ssh->respondWithJson('php:list', [
        ['version' => '8.3', 'apps' => [], 'package' => '8.3.20-1', 'default' => false],
        ['version' => '8.5', 'apps' => ['shop'], 'package' => '8.5.12-1', 'default' => true],
    ]);
    $this->ssh->respondWithJson('php:available', [
        ['version' => '8.3', 'complete' => true, 'installed' => true],
        ['version' => '8.4', 'complete' => true, 'installed' => false],
        ['version' => '8.5', 'complete' => true, 'installed' => true],
        ['version' => '8.6', 'complete' => false, 'installed' => false],
    ]);
});

it('shows the firewall rules and the ports Docker publishes past them', function () {
    livewire(ServerFirewall::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('Active')
        ->assertSee('22/tcp')
        ->assertSee('10.0.0.0/8')
        ->assertSee('SSH')
        ->assertSee('15637/udp')
        ->assertSee('enshrouded-server')
        ->assertSee('ufw rule cannot block it');
});

it('asks for a CLI update when firewall:list predates the Docker section', function () {
    $this->ssh->respondWithJson('firewall:list', ['installed' => true, 'status' => 'active', 'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'rules' => []]);

    livewire(ServerFirewall::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('does not report Docker yet')
        ->assertDontSee('Docker is not running');
});

it('adds and deletes rules through the CLI', function () {
    $this->ssh->respondTo('firewall:allow', "Allowed 8443/tcp.\n");
    $this->ssh->respondTo('firewall:delete', "Deleted rule 2.\n");

    livewire(ServerFirewall::class, ['record' => $this->server->getRouteKey()])
        ->callAction('allow', data: ['port' => '8443', 'proto' => 'tcp', 'from' => '203.0.113.0/24', 'comment' => 'Alt HTTPS'])
        ->assertHasNoActionErrors()
        ->assertNotified('Allowed 8443/tcp')
        ->callAction('deleteRule', arguments: ['number' => 2, 'to' => '3306', 'action' => 'ALLOW IN', 'from' => '10.0.0.0/8'])
        ->assertNotified('Deleted rule 2');

    expect($this->ssh->ran("firewall:allow --port=8443 --proto=tcp --from=203.0.113.0/24 --comment='Alt HTTPS'"))->toBeTrue()
        ->and($this->ssh->ran('firewall:delete --number=2'))->toBeTrue();
});

it('validates firewall input', function () {
    livewire(ServerFirewall::class, ['record' => $this->server->getRouteKey()])
        ->callAction('allow', data: ['port' => '80; rm', 'proto' => 'tcp', 'from' => 'nope', 'comment' => '$(x)'])
        ->assertHasActionErrors(['port', 'from', 'comment']);

    expect($this->ssh->ran('firewall:allow'))->toBeFalse();
});

it('reports a refused rule deletion', function () {
    $this->ssh->respondTo('firewall:delete', '', exitCode: 2, errorOutput: 'error: refusing to delete the SSH rule');

    livewire(ServerFirewall::class, ['record' => $this->server->getRouteKey()])
        ->callAction('deleteRule', arguments: ['number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'])
        ->assertNotified('Could not delete the rule');
});

it('shows installed and available PHP versions', function () {
    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('PHP 8.5')
        ->assertSee('CLI default')
        ->assertSee('Used by shop')
        ->assertSee('8.4')
        ->assertSee('8.6 (incomplete)')
        ->assertActionVisible('install');
});

it('queues a PHP install for an installable version only', function () {
    Queue::fake();

    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->callAction('install', data: ['version' => '8.4'])
        ->assertNotified('Installing PHP 8.4');

    $task = $this->server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Php)
        ->and((new ManagePhp($task))->arguments())->toBe('php:install --version=8.4');

    Queue::assertPushed(ManagePhp::class);
});

it('changes the CLI default and queues a removal', function () {
    Queue::fake();
    $this->ssh->respondTo('php:default', "PHP 8.3 is now the CLI default.\n");

    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->callAction('makeDefault', arguments: ['version' => '8.3'])
        ->assertNotified('PHP 8.3 is now the CLI default')
        ->callAction('removeVersion', arguments: ['version' => '8.3'])
        ->assertNotified('Removing PHP 8.3');

    expect($this->ssh->ran('php:default --version=8.3'))->toBeTrue()
        ->and((new ManagePhp($this->server->tasks()->sole()))->arguments())->toBe('php:remove --version=8.3');
});

it('builds PHP arguments only from valid versions and modes', function () {
    expect(fn () => (new ManagePhp(Task::factory()->create(['options' => ['mode' => 'install', 'version' => '8.4; rm']])))->arguments())->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ManagePhp(Task::factory()->create(['options' => ['mode' => 'purge', 'version' => '8.4']])))->arguments())->toThrow(InvalidArgumentException::class);
});
