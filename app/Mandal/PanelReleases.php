<?php

namespace App\Mandal;

use App\Enums\SourceProviderType;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Models\User;
use App\Notifications\PanelUpdateAvailable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Knows whether a newer Mandal release exists than the one this panel
 * runs, and keeps a notice in front of users until it is installed.
 * Updating is done on the server (`sudo mandal panel:update`); the panel
 * only points the way.
 */
class PanelReleases
{
    public const string LATEST_SETTING = 'panel_latest_version';

    public function __construct(
        private readonly CliArchive $archive,
    ) {}

    public function current(): string
    {
        return $this->archive->version();
    }

    /**
     * The newest release seen on GitHub, from the last check.
     */
    public function latest(): ?string
    {
        return Setting::get(self::LATEST_SETTING);
    }

    public function available(): bool
    {
        $latest = $this->latest();

        return $latest !== null && version_compare($latest, $this->current(), '>');
    }

    /**
     * Asks GitHub for the latest release tag, or null when it cannot be
     * read (offline, rate limited, private repository, no release yet).
     */
    public function fetchLatest(): ?string
    {
        $repository = (string) config('mandal.repository');

        try {
            $response = $this->client()->get("/repos/{$repository}/releases/latest");
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $tag = ltrim((string) $response->json('tag_name', ''), 'v');

        return preg_match('/^\d+\.\d+\.\d+$/', $tag) ? $tag : null;
    }

    /**
     * Fetches the latest release, records it, and raises or clears the
     * notice. Returns the latest version when it could be read.
     */
    public function check(): ?string
    {
        $latest = $this->fetchLatest();

        if ($latest !== null) {
            Setting::set(self::LATEST_SETTING, $latest);
        }

        $this->refreshNotices();

        return $latest;
    }

    /**
     * One unread notice per user while a newer release exists; none, or
     * none for an older release, otherwise.
     */
    public function refreshNotices(): void
    {
        if (! $this->available()) {
            $this->notices()->delete();

            return;
        }

        $latest = (string) $this->latest();
        $this->notices()->where('data->version', '!=', $latest)->delete();

        if ($this->notices()->whereNull('read_at')->exists()) {
            return;
        }

        foreach (User::query()->get() as $user) {
            $user->notify(new PanelUpdateAvailable($latest, $this->current(), $this->hostingServer()));
        }
    }

    /**
     * The server the panel runs on, when it manages itself.
     */
    public function hostingServer(): ?Server
    {
        return Server::query()->where('host', '127.0.0.1')->first();
    }

    private function client(): PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com')->acceptJson()->timeout(10);
        $credential = SourceCredential::forProvider(SourceProviderType::GitHub);

        if ($credential !== null && ! $credential->isExpired()) {
            $client = $client->withToken($credential->token);
        }

        return $client;
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function notices(): Builder
    {
        return DatabaseNotification::query()->where('type', PanelUpdateAvailable::class);
    }
}
