<?php

namespace App\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubProvider implements SourceProvider
{
    public const string API = 'https://api.github.com';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
    ) {}

    public function tokenInfo(): array
    {
        $response = $this->client()->get('/user');

        $this->ensure($response->successful(), 'GitHub rejected the token');

        $expires = $response->header('github-authentication-token-expiration');

        return [
            'account' => $response->json('login'),
            'expires_at' => $expires ? CarbonImmutable::parse($expires) : null,
        ];
    }

    public function listBranches(string $repo): array
    {
        $response = $this->client()->get("/repos/{$this->path($repo)}/branches", ['per_page' => 100]);

        $this->ensure($response->successful(), "Could not list branches of {$repo}");

        return array_values(array_map(fn (array $branch): string => (string) $branch['name'], $response->json()));
    }

    public function addDeployKey(string $repo, string $title, string $publicKey): string
    {
        $response = $this->client()->post("/repos/{$this->path($repo)}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ]);

        if ($response->status() === 422 && str_contains((string) $response->body(), 'already in use')) {
            foreach ($this->deployKeys($repo) as $key) {
                if ($this->sameKey($key['key'], $publicKey)) {
                    return (string) $key['id'];
                }
            }
        }

        $this->ensure($response->status() === 201, "Could not add a deploy key to {$repo}", $response->json('message'));

        return (string) $response->json('id');
    }

    public function removeDeployKey(string $repo, string $publicKey): void
    {
        foreach ($this->deployKeys($repo) as $key) {
            if ($this->sameKey($key['key'], $publicKey)) {
                $this->client()->delete("/repos/{$this->path($repo)}/keys/{$key['id']}");
            }
        }
    }

    public function addWebhook(string $repo, string $url, string $secret): string
    {
        $response = $this->client()->post("/repos/{$this->path($repo)}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $secret,
                'insecure_ssl' => '0',
            ],
        ]);

        $this->ensure($response->status() === 201, "Could not add a webhook to {$repo}", $response->json('message'));

        return (string) $response->json('id');
    }

    public function removeWebhook(string $repo, string $hookId): void
    {
        $this->client()->delete("/repos/{$this->path($repo)}/hooks/{$this->id($hookId)}");
    }

    public function pingWebhook(string $repo, string $hookId): void
    {
        $response = $this->client()->post("/repos/{$this->path($repo)}/hooks/{$this->id($hookId)}/pings");

        $this->ensure($response->status() === 204, "Could not ping the webhook on {$repo}");
    }

    public function lastDelivery(string $repo, string $hookId): ?array
    {
        $response = $this->client()->get("/repos/{$this->path($repo)}/hooks/{$this->id($hookId)}/deliveries", ['per_page' => 1]);

        $this->ensure($response->successful(), "Could not read webhook deliveries for {$repo}");

        $delivery = $response->json('0');

        if (! is_array($delivery)) {
            return null;
        }

        return [
            'status' => (string) ($delivery['status'] ?? ''),
            'status_code' => (int) ($delivery['status_code'] ?? 0),
            'event' => (string) ($delivery['event'] ?? ''),
            'delivered_at' => (string) ($delivery['delivered_at'] ?? ''),
        ];
    }

    public function verifyWebhook(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parsePush(array $payload): ?array
    {
        $ref = (string) ($payload['ref'] ?? '');
        $commit = (string) ($payload['after'] ?? '');

        if (! str_starts_with($ref, 'refs/heads/') || ! preg_match('/^[0-9a-f]{40}$/', $commit) || $commit === str_repeat('0', 40)) {
            return null;
        }

        return ['branch' => substr($ref, strlen('refs/heads/')), 'commit' => $commit];
    }

    /**
     * @return list<array{id: int, key: string, title: string}>
     */
    private function deployKeys(string $repo): array
    {
        $response = $this->client()->get("/repos/{$this->path($repo)}/keys", ['per_page' => 100]);

        $this->ensure($response->successful(), "Could not list deploy keys of {$repo}");

        return $response->json();
    }

    private function sameKey(string $a, string $b): bool
    {
        $normalize = fn (string $key): string => implode(' ', array_slice(preg_split('/\s+/', trim($key)) ?: [], 0, 2));

        return $normalize($a) === $normalize($b);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function path(string $repo): string
    {
        if (! preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$#', $repo)) {
            throw new RuntimeException("Invalid repository [{$repo}].");
        }

        return $repo;
    }

    private function id(string $id): string
    {
        if (! preg_match('/^\d{1,20}$/', $id)) {
            throw new RuntimeException("Invalid identifier [{$id}].");
        }

        return $id;
    }

    private function ensure(bool $condition, string $message, ?string $detail = null): void
    {
        if (! $condition) {
            throw new RuntimeException($detail ? "{$message}: {$detail}" : $message);
        }
    }
}
