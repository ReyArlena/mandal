<?php

use App\Enums\TaskStatus;
use App\Jobs\CreateApp;
use App\Jobs\DeleteApp;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use Tests\Support\FakeSshClient;

it('builds app:create arguments without optional parts', function () {
    $task = Task::factory()->create(['options' => ['name' => 'blog', 'domain' => 'blog.example.com', 'database' => false]]);

    expect((new CreateApp($task))->arguments())
        ->toBe('app:create --name=blog --domain=blog.example.com --www=redirect-to-naked --no-database');
});

it('drops unsafe aliases, branches and repositories', function () {
    $task = Task::factory()->create(['options' => [
        'name' => 'blog',
        'domain' => 'blog.example.com',
        'aliases' => ['ok.example.com', 'bad alias', '$(whoami).example.com'],
        'branch' => 'main;rm',
        'repo' => '../../etc',
    ]]);

    expect((new CreateApp($task))->arguments())
        ->toBe('app:create --name=blog --domain=blog.example.com --www=redirect-to-naked --alias=ok.example.com');
});

it('clones over https when asked', function () {
    $task = Task::factory()->create(['options' => ['name' => 'pub', 'domain' => 'pub.example.com', 'repo' => 'laravel/laravel', 'https' => true]]);

    expect((new CreateApp($task))->arguments())
        ->toBe('app:create --name=pub --domain=pub.example.com --www=redirect-to-naked --repo=laravel/laravel --https');
});

it('refuses to build arguments for an invalid app name', function () {
    $task = Task::factory()->create(['options' => ['name' => 'Bad Name', 'domain' => 'blog.example.com']]);

    expect(fn () => (new CreateApp($task))->arguments())->toThrow(InvalidArgumentException::class);
    expect(fn () => (new DeleteApp($task))->arguments())->toThrow(InvalidArgumentException::class);
});

it('runs the command and clears the inventory cache afterwards', function () {
    $ssh = new FakeSshClient;
    $mandal = new MandalClient($ssh);
    $server = Server::factory()->provisioned()->create();
    $task = Task::factory()->for($server)->create(['options' => ['name' => 'blog', 'domain' => 'blog.example.com']]);
    $ssh->respondWithJson('inventory', ['apps' => []]);
    $ssh->respondTo('app:create', "==> Creating app blog\n\nApp blog created.\n");

    $mandal->inventory($server);
    (new CreateApp($task))->handle($mandal);
    $mandal->inventory($server);

    expect($task->fresh()->status)->toBe(TaskStatus::Succeeded)
        ->and($task->fresh()->output)->toContain('App blog created.')
        ->and(array_filter($ssh->commands, fn (string $c): bool => str_contains($c, 'inventory')))->toHaveCount(2);
});

it('drops unsafe docker environment pairs and refuses a bad image', function () {
    $task = Task::factory()->create(['options' => ['type' => 'docker', 'name' => 'tool', 'domain' => 'tool.example.com', 'image' => 'nginx:1.27', 'port' => '80', 'env' => ['OK=1', 'bad key=2', '$(x)=3']]]);
    $bad = Task::factory()->create(['options' => ['type' => 'docker', 'name' => 'tool', 'domain' => 'tool.example.com', 'image' => 'evil;rm', 'port' => '80']]);

    expect((new CreateApp($task))->arguments())->toBe("app:create --name=tool --domain=tool.example.com --www=redirect-to-naked --type=docker --image=nginx:1.27 --port=80 --env='OK=1'")
        ->and(fn () => (new CreateApp($bad))->arguments())->toThrow(InvalidArgumentException::class);
});
