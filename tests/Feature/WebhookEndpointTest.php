<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\DeployApp;
use App\Models\Task;
use App\Models\Webhook;
use App\Ssh\SshClient;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSshClient;

beforeEach(function () {
    Queue::fake();
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->ssh->respondWithJson('inventory', json_decode(file_get_contents(base_path('tests/Fixtures/inventory.json')), true));
    $this->webhook = Webhook::factory()->create(['app' => 'shop', 'repo' => 'acme/shop']);
});

function signedPost(Webhook $webhook, array $payload, string $event = 'push', ?string $secret = null, string $delivery = 'd-1'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', "/webhooks/github/{$webhook->token}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $webhook->secret),
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
    ], $body);
}

it('queues a deploy for a push to the configured branch', function () {
    $commit = str_repeat('b', 40);

    signedPost($this->webhook, ['ref' => 'refs/heads/main', 'after' => $commit])
        ->assertOk()
        ->assertJson(['message' => 'Deploy queued.']);

    $task = Task::query()->sole();

    expect($task->type)->toBe(TaskType::Deploy)
        ->and($task->server_id)->toBe($this->webhook->server_id)
        ->and((new DeployApp($task))->arguments())->toBe("app:deploy --name=shop --commit={$commit}")
        ->and($this->webhook->fresh()->last_delivery_status)->toBe("deploy #{$task->id}");

    Queue::assertPushed(DeployApp::class);
});

it('ignores pushes to other branches', function () {
    signedPost($this->webhook, ['ref' => 'refs/heads/feature', 'after' => str_repeat('c', 40)])
        ->assertOk()
        ->assertJsonFragment(['message' => 'Not the deploy branch (main).']);

    expect(Task::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a bad signature', function () {
    signedPost($this->webhook, ['ref' => 'refs/heads/main', 'after' => str_repeat('d', 40)], secret: 'wrong')
        ->assertForbidden();

    expect(Task::query()->count())->toBe(0)
        ->and($this->webhook->fresh()->last_delivery_status)->toBe('bad signature');
});

it('answers pings and records them', function () {
    signedPost($this->webhook, ['zen' => 'Keep it logically awesome.'], event: 'ping')
        ->assertOk()
        ->assertJson(['message' => 'pong']);

    expect($this->webhook->fresh()->last_delivery_status)->toBe('ping');
});

it('handles each delivery once', function () {
    $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('e', 40)];

    signedPost($this->webhook, $payload, delivery: 'same')->assertOk();
    Task::query()->update(['status' => TaskStatus::Succeeded]);
    signedPost($this->webhook, $payload, delivery: 'same')->assertOk()->assertJson(['message' => 'Already handled.']);

    expect(Task::query()->count())->toBe(1);
});

it('returns 404 for an unknown token', function () {
    $this->postJson('/webhooks/github/'.str_repeat('x', 48), [])->assertNotFound();
});

it('refuses while another task is running', function () {
    Task::factory()->for($this->webhook->server)->running()->create();

    signedPost($this->webhook, ['ref' => 'refs/heads/main', 'after' => str_repeat('f', 40)])
        ->assertStatus(409);

    expect($this->webhook->fresh()->last_delivery_status)->toBe('busy');
});
