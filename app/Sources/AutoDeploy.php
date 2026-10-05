<?php

namespace App\Sources;

use App\Enums\SourceProviderType;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Webhook;
use RuntimeException;
use Throwable;

/**
 * Switches push-to-deploy on and off for an app by creating or removing
 * the provider's webhook, and proves the panel is reachable before
 * leaving it on.
 */
class AutoDeploy
{
    public const int PING_ATTEMPTS = 5;

    public const int PING_WAIT_MICROSECONDS = 1_500_000;

    public function __construct(
        private readonly SourceProviders $providers,
    ) {}

    public function isEnabled(Server $server, string $app): bool
    {
        return Webhook::query()->where('server_id', $server->getKey())->where('app', $app)->exists();
    }

    public function webhook(Server $server, string $app): ?Webhook
    {
        return Webhook::query()->where('server_id', $server->getKey())->where('app', $app)->first();
    }

    /**
     * @throws RuntimeException with a message fit for showing to the user
     */
    public function enable(Server $server, string $app, string $repo): Webhook
    {
        if (Setting::publicUrl() === null) {
            throw new RuntimeException('Set the panel\'s public URL in Settings first; GitHub has to be able to reach it.');
        }

        $provider = $this->providers->for(SourceProviderType::GitHub)
            ?? throw new RuntimeException('Connect a GitHub token in Settings first.');

        $existing = $this->webhook($server, $app);

        if ($existing !== null) {
            return $existing;
        }

        $webhook = Webhook::query()->create([
            'server_id' => $server->getKey(),
            'app' => $app,
            'provider' => SourceProviderType::GitHub,
            'repo' => $repo,
        ]);

        try {
            $hookId = $provider->addWebhook($repo, (string) $webhook->url(), $webhook->secret);
            $webhook->forceFill(['remote_id' => $hookId])->save();
            $provider->pingWebhook($repo, $hookId);

            $delivery = $this->waitForDelivery($provider, $repo, $hookId);
        } catch (Throwable $exception) {
            $this->cleanUp($provider, $webhook);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        if ($delivery === null || $delivery['status_code'] < 200 || $delivery['status_code'] >= 300) {
            $this->cleanUp($provider, $webhook);

            $reason = $delivery === null ? 'no delivery was recorded' : "GitHub got HTTP {$delivery['status_code']} ({$delivery['status']})";

            throw new RuntimeException("GitHub could not reach {$webhook->url()}: {$reason}. Check the public URL and that the panel is reachable from the internet.");
        }

        if ($webhook->fresh()?->last_delivery_status !== 'ping') {
            $this->cleanUp($provider, $webhook);

            throw new RuntimeException("GitHub's test delivery did not arrive at this panel. Is {$webhook->url()} really this panel?");
        }

        return $webhook;
    }

    public function disable(Server $server, string $app): void
    {
        $webhook = $this->webhook($server, $app);

        if ($webhook === null) {
            return;
        }

        $provider = $this->providers->for(SourceProviderType::GitHub);

        if ($provider !== null && $webhook->remote_id !== null) {
            try {
                $provider->removeWebhook($webhook->repo, $webhook->remote_id);
            } catch (Throwable) {
                // The hook may already be gone; the local record goes regardless.
            }
        }

        $webhook->delete();
    }

    /**
     * @return array{status: string, status_code: int, event: string, delivered_at: string}|null
     */
    private function waitForDelivery(SourceProvider $provider, string $repo, string $hookId): ?array
    {
        for ($attempt = 1; $attempt <= self::PING_ATTEMPTS; $attempt++) {
            $delivery = $provider->lastDelivery($repo, $hookId);

            if ($delivery !== null && $delivery['event'] === 'ping') {
                return $delivery;
            }

            if ($attempt < self::PING_ATTEMPTS && ! app()->runningUnitTests()) {
                usleep(self::PING_WAIT_MICROSECONDS);
            }
        }

        return null;
    }

    private function cleanUp(SourceProvider $provider, Webhook $webhook): void
    {
        if ($webhook->remote_id !== null) {
            try {
                $provider->removeWebhook($webhook->repo, $webhook->remote_id);
            } catch (Throwable) {
                // Best effort; the token may lack permission to delete.
            }
        }

        $webhook->delete();
    }
}
