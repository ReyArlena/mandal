<?php

use App\Mandal\CliArchive;
use App\Models\Server;

it('serves the setup script for a valid token', function () {
    $server = Server::factory()->create(['ssh_user' => 'mandal']);
    $token = $server->issueSetupToken();

    $response = $this->get(route('setup.script', ['server' => $server, 'token' => $token]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');

    $script = $response->getContent();
    $keyBlob = explode(' ', $server->public_key)[1];

    expect($script)->toStartWith('#!/usr/bin/env bash')
        ->toContain("user='mandal'")
        ->toContain($keyBlob)
        ->toContain(route('setup.cli', ['server' => $server, 'token' => $token]))
        ->toContain('/etc/sudoers.d/mandal')
        ->toContain('NOPASSWD: %s/bin/mandal')
        ->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

it('refuses the setup script with a wrong, expired or cleared token', function () {
    $server = Server::factory()->create();
    $token = $server->issueSetupToken();

    $this->get(route('setup.script', ['server' => $server, 'token' => 'nope']))->assertNotFound();

    $this->travel(Server::SETUP_TOKEN_HOURS + 1)->hours();
    $this->get(route('setup.script', ['server' => $server, 'token' => $token]))->assertNotFound();

    $this->travelBack();
    $token = $server->issueSetupToken();
    $server->clearSetupToken();
    $this->get(route('setup.script', ['server' => $server, 'token' => $token]))->assertNotFound();
});

it('serves the CLI archive containing the tool and its version', function () {
    $server = Server::factory()->create();
    $token = $server->issueSetupToken();

    $response = $this->get(route('setup.cli', ['server' => $server, 'token' => $token]));

    $response->assertOk()->assertDownload('mandal-cli.tar.gz');

    $listing = shell_exec('tar -tzf '.escapeshellarg($response->getFile()->getPathname()));

    expect($listing)->toContain('bin/mandal')
        ->toContain('lib/core.sh')
        ->toContain('lib/roles/database/mariadb.sh')
        ->toContain('VERSION')
        ->not->toContain('tests/');
});

it('reports the version from the VERSION file', function () {
    expect(app(CliArchive::class)->version())->toMatch('/^\d+\.\d+\.\d+$/');
});
