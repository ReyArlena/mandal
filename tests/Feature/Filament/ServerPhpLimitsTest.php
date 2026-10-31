<?php

use App\Filament\Resources\Servers\Pages\ServerPhp;
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
    $limits = ['upload_max_filesize' => '1G', 'post_max_size' => '1G', 'memory_limit' => '256M', 'max_execution_time' => '300', 'max_input_time' => '300'];
    $this->ssh->respondWithJson('php:list', [
        ['version' => '8.5', 'apps' => ['shop'], 'package' => '8.5.12-1', 'default' => true, 'limits' => $limits],
    ]);
    $this->ssh->respondWithJson('php:available', [['version' => '8.5', 'complete' => true, 'installed' => true]]);
    $this->ssh->respondWithJson('inventory', ['nginx' => ['client_max_body_size' => ['value' => '1g', 'managed' => true]]]);
});

it('shows each version\'s limits and the nginx body limit', function () {
    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('upload 1G, post 1G, memory 256M, 300s/300s')
        ->assertSee('client_max_body_size 1g')
        ->assertActionVisible('nginxBodySize');
});

it('saves a version\'s limits through php:limits', function () {
    $this->ssh->respondTo('php:limits', "PHP 8.5 limits: ...\n");

    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->mountAction('limits', arguments: ['version' => '8.5'])
        ->assertActionDataSet(['upload_max_filesize' => '1G', 'memory_limit' => '256M'])
        ->setActionData(['upload_max_filesize' => '2g', 'post_max_size' => '2G', 'memory_limit' => '-1', 'max_execution_time' => 600])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('PHP 8.5 limits saved');

    expect($this->ssh->ran("php:limits --version=8.5 --upload-max-filesize='2G' --post-max-size='2G' --memory-limit='-1' --max-execution-time='600' --max-input-time='300'"))->toBeTrue();
});

it('refuses a post_max_size below upload_max_filesize', function () {
    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->callAction('limits', data: ['upload_max_filesize' => '2G', 'post_max_size' => '1G', 'memory_limit' => '256M', 'max_execution_time' => 300, 'max_input_time' => 300], arguments: ['version' => '8.5'])
        ->assertHasActionErrors(['post_max_size']);

    expect($this->ssh->ran('php:limits'))->toBeFalse();
});

it('validates limit sizes', function () {
    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->callAction('limits', data: ['upload_max_filesize' => 'lots', 'post_max_size' => '1G', 'memory_limit' => '256MB', 'max_execution_time' => -1, 'max_input_time' => 300], arguments: ['version' => '8.5'])
        ->assertHasActionErrors(['upload_max_filesize', 'memory_limit', 'max_execution_time']);
});

it('saves the nginx body limit through nginx:limits', function () {
    $this->ssh->respondTo('nginx:limits', "nginx accepts request bodies up to 2g.\n");

    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->callAction('nginxBodySize', data: ['client_max_body_size' => '2G'])
        ->assertHasNoActionErrors()
        ->assertNotified('nginx limit saved');

    expect($this->ssh->ran("nginx:limits --client-max-body-size='2g'"))->toBeTrue();
});

it('leaves the nginx limit alone when nginx.conf sets it', function () {
    $this->ssh->respondWithJson('inventory', ['nginx' => ['client_max_body_size' => ['value' => '20m', 'managed' => false]]]);

    livewire(ServerPhp::class, ['record' => $this->server->getRouteKey()])
        ->assertSee('Set in /etc/nginx/nginx.conf')
        ->assertActionHidden('nginxBodySize');
});
