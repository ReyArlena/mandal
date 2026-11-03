<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\RunBenchmark;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create();
    $this->ssh->respondWithJson('inventory', ['mandal' => ['build' => 'abc123abc123']]);
});

it('queues a benchmark of the chosen server with the panel setup and opens the task', function () {
    Queue::fake();
    $other = Server::factory()->provisioned()->create(['name' => 'other-box']);

    livewire(General::class)
        ->assertActionVisible('benchmark')
        ->callAction('benchmark', data: ['server' => $other->getKey()])
        ->assertNotified('Benchmark started')
        ->assertRedirect(ServerResource::getUrl('task', ['record' => $other, 'task' => $other->tasks()->sole()]));

    $task = $other->tasks()->sole();

    expect($task->type)->toBe(TaskType::Benchmark)
        ->and($task->options['panel'])->toHaveKeys(['php', 'opcache', 'config_cached', 'debug', 'cache', 'extensions', 'phpseclib_math'])
        ->and($this->server->tasks()->count())->toBe(0);
    Queue::assertPushed(RunBenchmark::class);
});

it('picks the server that hosts the panel by default and lists only connected servers', function () {
    $host = Server::factory()->provisioned()->create(['name' => 'panel-host', 'host' => '127.0.0.1']);
    $unconnected = Server::factory()->create(['name' => 'new-box']);

    livewire(General::class)
        ->mountAction('benchmark')
        ->assertSchemaStateSet(['server' => $host->getKey()])
        ->assertSchemaComponentExists('server', checkComponentUsing: function (Select $select) use ($host, $unconnected): bool {
            $options = $select->getOptions();

            return array_key_exists($host->getKey(), $options)
                && array_key_exists($this->server->getKey(), $options)
                && ! array_key_exists($unconnected->getKey(), $options);
        });
});

it('offers no benchmark until a server has been connected', function () {
    $this->server->forceFill(['connected_at' => null])->save();

    livewire(General::class)->assertActionHidden('benchmark');
});

it('refuses a benchmark while another task runs on the server', function () {
    Queue::fake();
    Task::factory()->for($this->server)->running()->create();

    livewire(General::class)
        ->callAction('benchmark', data: ['server' => $this->server->getKey()])
        ->assertNotified('Another task is still running');

    expect($this->server->tasks()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('reports the panel, SSH, inventory and CLI timings with a JSON block', function () {
    $task = Task::factory()->for($this->server)->create(['type' => TaskType::Benchmark, 'options' => ['panel' => RunBenchmark::panelEnvironment()]]);
    $this->ssh->respondTo('true', '');
    $this->ssh->respondWithJson('version --json', ['version' => '0.2.0', 'build' => 'abc123abc123']);
    $this->ssh->respondWithJson('benchmark', [
        'version' => '0.2.0',
        'build' => 'abc123abc123',
        'vcpus' => 6,
        'load' => 0.42,
        'process_start' => ['count' => 200, 'seconds' => 0.8, 'per_process_ms' => 4],
        'collectors' => [['name' => 'docker', 'seconds' => 0.9], ['name' => 'system', 'seconds' => 0.1]],
        'commands' => [['command' => 'inventory', 'seconds' => 2.1, 'exit_code' => 0], ['command' => 'db:list', 'seconds' => 0.3, 'exit_code' => 1]],
    ]);

    (new RunBenchmark($task))->handle(app(MandalClient::class));

    $task->refresh();
    $json = json_decode(substr($task->output, strpos($task->output, "JSON\n") + 5), true);

    expect($task->status)->toBe(TaskStatus::Succeeded)
        ->and($task->output)->toContain('Panel (as the web server runs it)')
        ->toContain('sudo overhead')
        ->toContain('Fresh, over a new SSH connection')
        ->toContain('mandal 0.2.0 (build abc123abc123), 6 vCPUs, load 0.42')
        ->toContain('Starting a process: 4.00 ms')
        ->toContain('db:list (exit 1)')
        ->toContain('on the server, so the panel side (SSH, transfer, JSON, cache) adds')
        ->and($json)->toHaveKeys(['panel', 'ssh', 'inventory', 'cli'])
        ->and($json['cli']['vcpus'])->toBe(6)
        ->and($this->ssh->ran('sudo -n /opt/mandal/bin/mandal benchmark'))->toBeTrue();
});

it('fails the task when the server cannot be reached', function () {
    $task = Task::factory()->for($this->server)->create(['type' => TaskType::Benchmark, 'options' => []]);
    $this->ssh->connectionFailure = new SshException('Could not connect to the server.');

    (new RunBenchmark($task))->handle(app(MandalClient::class));

    $task->refresh();

    expect($task->exit_code)->toBe(255)
        ->and($task->output)->toContain('(not recorded)')
        ->toContain('error: Could not connect to the server.');
});
