<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Pages\ServerTasks;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\Pages\ViewTask;
use App\Jobs\ProvisionServer;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('starts provisioning from the server page', function () {
    Queue::fake();
    $server = Server::factory()->withPinnedHostKey()->create();

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('provision', data: [
            'database' => 'mariadb',
            'cache' => 'dragonfly',
            'js' => 'bun',
            'php' => '',
            'skip_ssh_hardening' => false,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Provisioning started');

    $task = $server->tasks()->sole();

    expect($task->type)->toBe(TaskType::Provision)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->user_id)->toBe($this->user->id)
        ->and($task->options['database'])->toBe('mariadb')
        ->and($task->options['php'])->toBeNull();

    Queue::assertPushed(ProvisionServer::class, fn (ProvisionServer $job): bool => $job->task->is($task));
});

it('hides provisioning until the server has connected', function () {
    $server = Server::factory()->create();

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->assertActionHidden('provision');
});

it('rejects an invalid PHP version', function () {
    Queue::fake();
    $server = Server::factory()->withPinnedHostKey()->create();

    livewire(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('provision', data: ['database' => 'mariadb', 'cache' => 'dragonfly', 'js' => 'bun', 'php' => 'latest'])
        ->assertHasActionErrors(['php']);

    Queue::assertNothingPushed();
});

it('lists the tasks for a server', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    Task::factory()->for($server)->succeeded()->create();
    Task::factory()->for($server)->failed()->create();

    livewire(ServerTasks::class, ['record' => $server->getRouteKey()])
        ->assertOk()
        ->assertSee('Succeeded')
        ->assertSee('Failed');
});

it('shows a task with its output and polls while it runs', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $task = Task::factory()->for($server)->running()->create();

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $task->getKey()])
        ->assertOk()
        ->assertSee('==> Base packages')
        ->assertSeeHtml('wire:poll.2s');
});

it('stops polling once the task has finished', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $task = Task::factory()->for($server)->succeeded()->create();

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $task->getKey()])
        ->assertSee('Provisioning finished.')
        ->assertDontSeeHtml('wire:poll');
});

it('does not show another server\'s task', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $other = Task::factory()->succeeded()->create();

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $other->getKey()])
        ->assertStatus(404);
});
