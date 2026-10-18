<?php

use App\Enums\TaskStatus;
use App\Jobs\ProvisionServer;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use Tests\Support\FakeSshClient;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->mandal = new MandalClient($this->ssh);
    $this->server = Server::factory()->withPinnedHostKey()->create();
});

it('runs provisioning with the chosen options and records the output', function () {
    $task = Task::factory()->for($this->server)->create([
        'options' => ['database' => 'mariadb', 'cache' => 'dragonfly', 'js' => 'bun', 'php' => '8.4', 'skip_ssh_hardening' => true],
    ]);
    $this->ssh->respondTo('provision', "==> Base packages\n+ apt-get update\n\nProvisioning finished.\n");

    (new ProvisionServer($task))->handle($this->mandal);

    $task->refresh();

    expect($this->ssh->commands)->toBe(['sudo -n /opt/mandal/bin/mandal provision --database=mariadb --cache=dragonfly --js=bun --php=8.4 --skip-ssh-hardening 2>&1'])
        ->and($task->status)->toBe(TaskStatus::Succeeded)
        ->and($task->exit_code)->toBe(0)
        ->and($task->output)->toContain('Provisioning finished.')
        ->and($task->started_at)->not->toBeNull()
        ->and($task->finished_at)->not->toBeNull()
        ->and($this->server->fresh()->provisioned_at)->not->toBeNull();
});

it('uses the default drivers when no options are given', function () {
    $task = Task::factory()->for($this->server)->create(['options' => []]);

    expect((new ProvisionServer($task))->arguments())->toBe('provision --database=mariadb --cache=dragonfly --js=bun');
});

it('ignores a malformed PHP version', function () {
    $task = Task::factory()->for($this->server)->create(['options' => ['php' => '8.4; rm -rf /']]);

    expect((new ProvisionServer($task))->arguments())->not->toContain('--php');
});

it('marks the task failed when mandal exits non-zero', function () {
    $task = Task::factory()->for($this->server)->create();
    $this->ssh->respondTo('provision', '', exitCode: 1, errorOutput: "error: provisioning cannot continue:\nApache is installed\n");

    (new ProvisionServer($task))->handle($this->mandal);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->exit_code)->toBe(1)
        ->and($task->output)->toContain('Apache is installed')
        ->and($this->server->fresh()->provisioned_at)->toBeNull();
});

it('marks the task failed when the connection drops', function () {
    $task = Task::factory()->for($this->server)->create();
    $this->ssh->connectionFailure = new SshException('Could not connect: timed out');

    (new ProvisionServer($task))->handle($this->mandal);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->output)->toContain('timed out');
});

it('is unique per server while running', function () {
    $task = Task::factory()->for($this->server)->create();

    expect((new ProvisionServer($task))->uniqueId())->toBe((string) $this->server->id);
});

it('caps task output', function () {
    $task = Task::factory()->for($this->server)->create();

    $task->appendOutput(str_repeat('x', Task::MAX_OUTPUT_BYTES + 100));

    expect(strlen($task->fresh()->output))->toBe(Task::MAX_OUTPUT_BYTES);
});
