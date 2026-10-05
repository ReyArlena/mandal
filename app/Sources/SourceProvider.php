<?php

namespace App\Sources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

interface SourceProvider
{
    /**
     * Details about the token: the account it belongs to and when it expires.
     *
     * @return array{account: string|null, expires_at: CarbonInterface|null}
     */
    public function tokenInfo(): array;

    /**
     * @return list<string>
     */
    public function listBranches(string $repo): array;

    /**
     * Add a read-only deploy key and return its id.
     */
    public function addDeployKey(string $repo, string $title, string $publicKey): string;

    /**
     * Remove every deploy key whose public key matches.
     */
    public function removeDeployKey(string $repo, string $publicKey): void;

    /**
     * Create a push webhook and return its id.
     */
    public function addWebhook(string $repo, string $url, string $secret): string;

    public function removeWebhook(string $repo, string $hookId): void;

    public function pingWebhook(string $repo, string $hookId): void;

    /**
     * The most recent delivery, or null if there is none yet.
     *
     * @return array{status: string, status_code: int, event: string, delivered_at: string}|null
     */
    public function lastDelivery(string $repo, string $hookId): ?array;

    /**
     * Whether the request carries a valid signature for the secret.
     */
    public function verifyWebhook(Request $request, string $secret): bool;

    /**
     * The branch and commit of a push payload, or null for other events.
     *
     * @param  array<string, mixed>  $payload
     * @return array{branch: string, commit: string}|null
     */
    public function parsePush(array $payload): ?array;
}
