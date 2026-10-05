<?php

namespace App\Http\Controllers;

use App\Enums\SourceProviderType;
use App\Enums\TaskType;
use App\Jobs\DeployApp;
use App\Mandal\MandalClient;
use App\Models\Webhook;
use App\Sources\SourceProviders;
use App\Ssh\SshException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Receives GitHub push webhooks and queues a deploy for the matching app.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, SourceProviders $providers, MandalClient $mandal): JsonResponse
    {
        $webhook = Webhook::query()->where('token', $token)->where('provider', SourceProviderType::GitHub)->first();

        abort_unless($webhook !== null, 404);

        $provider = $providers->make(SourceProviderType::GitHub, 'unused');

        if (! $provider->verifyWebhook($request, $webhook->secret)) {
            $webhook->recordDelivery('bad signature');

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $delivery = (string) $request->header('X-GitHub-Delivery', '');

        if ($delivery !== '' && ! Cache::add("webhooks.delivery.{$delivery}", true, now()->addMinutes(10))) {
            return response()->json(['message' => 'Already handled.']);
        }

        $event = (string) $request->header('X-GitHub-Event', '');

        if ($event === 'ping') {
            $webhook->recordDelivery('ping');

            return response()->json(['message' => 'pong']);
        }

        if ($event !== 'push') {
            $webhook->recordDelivery("ignored {$event}");

            return response()->json(['message' => 'Ignored.']);
        }

        $push = $provider->parsePush($request->json()->all());

        if ($push === null) {
            $webhook->recordDelivery('ignored push');

            return response()->json(['message' => 'Ignored.']);
        }

        $branch = $this->deployBranch($webhook, $mandal);

        if ($branch !== null && $push['branch'] !== $branch) {
            $webhook->recordDelivery("ignored {$push['branch']}");

            return response()->json(['message' => "Not the deploy branch ({$branch})."]);
        }

        $server = $webhook->server;

        if ($server->hasActiveTask()) {
            $webhook->recordDelivery('busy');

            return response()->json(['message' => 'A task is already running; push again later.'], 409);
        }

        $task = $server->tasks()->create([
            'type' => TaskType::Deploy,
            'options' => ['name' => $webhook->app, 'commit' => $push['commit'], 'trigger' => 'webhook'],
        ]);

        DeployApp::dispatch($task);
        $webhook->recordDelivery("deploy #{$task->id}");

        return response()->json(['message' => 'Deploy queued.', 'task' => $task->id]);
    }

    /**
     * The app's configured branch, read from the server's manifest.
     */
    private function deployBranch(Webhook $webhook, MandalClient $mandal): ?string
    {
        try {
            $apps = $mandal->inventory($webhook->server)['apps'] ?? [];
        } catch (SshException) {
            return null;
        }

        $app = collect($apps)->firstWhere('name', $webhook->app);

        return $app['branch'] ?? null;
    }
}
