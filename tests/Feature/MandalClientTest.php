<?php

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use Tests\Support\FakeSshClient;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->client = new MandalClient($this->ssh);
    $this->server = Server::factory()->withPinnedHostKey()->create();
});

it('runs inventory as root and decodes the JSON', function () {
    $this->ssh->respondWithJson('inventory', ['mandal' => ['version' => '0.1.0'], 'apps' => []]);

    $inventory = $this->client->inventory($this->server);

    expect($inventory['mandal']['version'])->toBe('0.1.0')
        ->and($this->ssh->commands)->toBe(['sudo -n /opt/mandal/bin/mandal inventory']);
});

it('caches inventory per server and can be refreshed', function () {
    $this->ssh->respondWithJson('inventory', ['apps' => []]);

    $this->client->inventory($this->server);
    $this->client->inventory($this->server);

    expect($this->ssh->commands)->toHaveCount(1);

    $this->client->inventory($this->server, fresh: true);

    expect($this->ssh->commands)->toHaveCount(2);
});

it('reads the version without sudo', function () {
    $this->ssh->respondWithJson('version', ['version' => '0.1.0']);

    expect($this->client->version($this->server)['version'])->toBe('0.1.0')
        ->and($this->ssh->commands[0])->toBe('/opt/mandal/bin/mandal version --json');
});

it('turns a failed command into an exception carrying its error output', function () {
    $this->ssh->respondTo('provision --check', output: '', exitCode: 77, errorOutput: 'error: this command must be run as root');

    expect(fn () => $this->client->provisionCheck($this->server))
        ->toThrow(SshException::class, 'this command must be run as root');
});

it('rejects output that is not JSON', function () {
    $this->ssh->respondTo('inventory', output: 'sudo: a password is required');

    expect(fn () => $this->client->inventory($this->server))->toThrow(SshException::class, 'Expected JSON');
});
