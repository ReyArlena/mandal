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
        ->assertSeeHtml('wire:poll.2s')
        ->assertSeeHtml('x-ref="output"')
        ->assertSee('Jump to present')
        ->assertSee('Copy output');
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

it('links a started Docker app to its container logs with live refresh on', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $started = Task::factory()->for($server)->succeeded()->create(['type' => TaskType::Control, 'options' => ['name' => 'game', 'action' => 'start']]);
    $stopped = Task::factory()->for($server)->succeeded()->create(['type' => TaskType::Control, 'options' => ['name' => 'game', 'action' => 'stop']]);

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $started->getKey()])
        ->assertSee('Follow container logs')
        ->assertSeeHtml('source=docker%3Agame')
        ->assertSeeHtml('live=1');

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $stopped->getKey()])
        ->assertDontSee('Follow container logs');
});

it('shows output without terminal colour codes', function () {
    $server = Server::factory()->withPinnedHostKey()->create();
    $task = Task::factory()->for($server)->succeeded()->create(['output' => "  livewire/livewire \e[90m.\e[39m \e[90m.\e[39m  \e[32;1mDONE\e[39;22m\n\e]0;title\x07Loading Steam API...\e[0mOK\n"]);

    livewire(ViewTask::class, ['record' => $server->getRouteKey(), 'task' => $task->getKey()])
        ->assertSee('livewire/livewire . .  DONE')
        ->assertSee('Loading Steam API...OK')
        ->assertDontSee('[32;1m')
        ->assertDontSee('[0m');
});
