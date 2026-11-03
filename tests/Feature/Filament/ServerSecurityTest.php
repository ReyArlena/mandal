<?php

use App\Filament\Resources\Servers\Pages\ServerFail2ban;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerSsh;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use App\Models\User;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ownFingerprint = KeyPair::fingerprint($this->server->public_key);
    $this->ssh->respondWithJson('firewall:list', ['installed' => true, 'status' => 'active', 'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'rules' => [], 'docker' => ['active' => false, 'exposed' => []]]);
    $this->ssh->respondWithJson('ssh:status', [
        'sshd' => ['installed' => true, 'active' => 'active', 'hardened' => true, 'port' => 2222, 'password_authentication' => 'no', 'permit_root_login' => 'no', 'pubkey_authentication' => 'yes', 'max_auth_tries' => 3],
        'keys' => [
            ['fingerprint' => $this->ownFingerprint, 'type' => 'ssh-ed25519', 'comment' => 'mandal-panel'],
            ['fingerprint' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ssh-ed25519', 'comment' => 'mandal-panel: Laptop panel'],
        ],
    ]);
    $this->ssh->respondWithJson('fail2ban:status', [
        'installed' => true, 'active' => 'active',
        'jails' => [
            ['name' => 'sshd', 'failed' => 1, 'banned' => 2, 'total_banned' => 12, 'ips' => ['1.2.3.4', '2001:db8::1']],
            ['name' => 'nginx-http-auth', 'failed' => 0, 'banned' => 0, 'total_banned' => 0, 'ips' => []],
        ],
    ]);
});

it('shows the security sidebar on every security page', function (string $page) {
    $base = "/servers/{$this->server->getKey()}";

    livewire($page, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('fi-page-sub-navigation')
        ->assertSeeHtml("{$base}/security\"")
        ->assertSeeHtml("{$base}/security/ssh\"")
        ->assertSeeHtml("{$base}/security/fail2ban\"")
        ->assertSeeInOrder(['Firewall', 'SSH', 'fail2ban']);
})->with([
    'firewall' => ServerFirewall::class,
    'ssh' => ServerSsh::class,
    'fail2ban' => ServerFail2ban::class,
]);

it('shows sshd settings and the keys, marking this panel', function () {
    livewire(ServerSsh::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('Hardened by Mandal')
        ->assertSee('2222')
        ->assertSee('This panel')
        ->assertSee('Laptop panel')
        ->assertSee('SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')
        ->assertSee($this->ownFingerprint);
});

it('adds a key through key:add', function () {
    $this->ssh->respondTo('key:add', "Added key SHA256:x for \"Laptop panel\".\n");

    livewire(ServerSsh::class, ['record' => $this->server->getRouteKey()])
        ->callAction('addKey', data: ['name' => 'Laptop panel', 'key' => "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 someone@laptop\n"])
        ->assertHasNoActionErrors()
        ->assertNotified('Key added');

    expect($this->ssh->ran("key:add --name='Laptop panel' --key='ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1'"))->toBeTrue();
});

it('validates a key before adding it', function () {
    livewire(ServerSsh::class, ['record' => $this->server->getRouteKey()])
        ->callAction('addKey', data: ['name' => 'bad;name', 'key' => 'not a key'])
        ->assertHasActionErrors(['name', 'key']);

    expect($this->ssh->ran('key:add'))->toBeFalse();
});

it('removes another key but never this panel\'s', function () {
    $this->ssh->respondTo('key:remove', "Removed SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.\n");

    livewire(ServerSsh::class, ['record' => $this->server->getRouteKey()])
        ->callAction('removeKey', arguments: ['fingerprint' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'comment' => 'mandal-panel: Laptop panel'])
        ->assertNotified('Key removed')
        ->callAction('removeKey', arguments: ['fingerprint' => $this->ownFingerprint, 'comment' => 'mandal-panel'])
        ->assertNotNotified('Key removed');

    expect($this->ssh->ran('key:remove --fingerprint=SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'))->toBeTrue()
        ->and($this->ssh->ran("key:remove --fingerprint={$this->ownFingerprint}"))->toBeFalse();
});

it('asks to connect a server before reading its keys', function () {
    $server = Server::factory()->create();

    livewire(ServerSsh::class, ['record' => $server->getRouteKey()])
        ->assertSee('Connect this server first')
        ->assertActionHidden('addKey');

    expect($this->ssh->ran('ssh:status'))->toBeFalse();
});

it('shows the jails with their banned addresses', function () {
    livewire(ServerFail2ban::class, ['record' => $this->server->getRouteKey()])
        ->assertOk()
        ->assertSee('sshd')
        ->assertSee('2 banned')
        ->assertSee('1.2.3.4')
        ->assertSee('2001:db8::1')
        ->assertSee('nginx-http-auth')
        ->assertSee('0 banned');
});

it('unbans an address in its jail', function () {
    $this->ssh->respondTo('fail2ban:unban', "Unbanned 1.2.3.4 in sshd.\n");

    livewire(ServerFail2ban::class, ['record' => $this->server->getRouteKey()])
        ->callAction('unban', arguments: ['jail' => 'sshd', 'ip' => '1.2.3.4'])
        ->assertNotified('Unbanned 1.2.3.4')
        ->callAction('unban', arguments: ['jail' => 'sshd; rm', 'ip' => '1.2.3.4'])
        ->callAction('unban', arguments: ['jail' => 'sshd', 'ip' => '$(x)']);

    expect($this->ssh->ran('fail2ban:unban --jail=sshd --ip=1.2.3.4'))->toBeTrue()
        ->and(collect($this->ssh->commands)->filter(fn (string $command): bool => str_contains($command, 'fail2ban:unban'))->count())->toBe(1);
});

it('says when fail2ban is missing', function () {
    $this->ssh->respondWithJson('fail2ban:status', ['installed' => false, 'active' => 'inactive', 'jails' => []]);

    livewire(ServerFail2ban::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('fail2ban is not installed');
});

it('no longer grants access from the Server tab', function () {
    $this->ssh->respondWithJson('inventory', []);

    livewire(ViewServer::class, ['record' => $this->server->getRouteKey()])
        ->assertActionDoesNotExist('grantAccess');
});
