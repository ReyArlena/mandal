<?php

namespace App\Mandal;

use App\Enums\ReleaseChannel;
use App\Enums\SourceProviderType;
use App\Enums\TaskType;
use App\Jobs\UpdatePanel;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Models\Task;
use App\Models\User;
use App\Notifications\PanelUpdateAvailable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Knows what build this panel runs and whether a newer one exists on its
 * release channel (the latest release, or the head of the development
 * branch), keeps a notice in front of users until it is installed, and
 * queues `mandal panel:update` on the server that hosts the panel.
 */
class PanelReleases
{
    public const string LATEST_SETTING = 'panel_latest_version';

    public const string LATEST_COMMIT_SETTING = 'panel_latest_commit';

    public const string CHANNEL_SETTING = 'panel_release_channel';

    public function __construct(
        private readonly CliArchive $archive,
    ) {}

    public function current(): string
    {
        return $this->archive->version();
    }

    public function channel(): ReleaseChannel
    {
        return ReleaseChannel::tryFrom((string) Setting::get(self::CHANNEL_SETTING)) ?? ReleaseChannel::Stable;
    }

    public function setChannel(ReleaseChannel $channel): void
    {
        Setting::set(self::CHANNEL_SETTING, $channel->value);
    }

    /**
     * The newest release seen on GitHub, from the last check.
     */
    public function latest(): ?string
    {
        return Setting::get(self::LATEST_SETTING);
    }

    /**
     * The head of the development branch on GitHub, from the last check.
     */
    public function latestCommit(): ?string
    {
        return Setting::get(self::LATEST_COMMIT_SETTING);
    }

    /**
     * What the CLI deployed this panel from, read from the release record
     * it wrote next to the code; null for a panel run from a checkout.
     *
     * @return array{ref: string, commit: string, deployed_at: ?string}|null
     */
    public function deployed(): ?array
    {
        $file = (string) config('mandal.release_file');

        if (! is_file($file)) {
            return null;
        }

        $record = parse_ini_string((string) file_get_contents($file), scanner_mode: INI_SCANNER_RAW) ?: [];
        $ref = (string) ($record['BRANCH'] ?? '');
        $commit = (string) ($record['COMMIT'] ?? '');

        if ($ref === '' || $commit === '') {
            return null;
        }

        return [
            'ref' => $ref,
            'commit' => $commit,
            'deployed_at' => isset($record['DEPLOYED_AT']) ? (string) $record['DEPLOYED_AT'] : null,
        ];
    }

    /**
     * Whether the panel was deployed from a branch rather than a release.
     */
    public function deployedFromBranch(): bool
    {
        $deployed = $this->deployed();

        return $deployed !== null && ! self::isTag($deployed['ref']);
    }

    /**
     * The running build as a notice or the command line names it: the
     * version, with the branch and commit for a development build.
     */
    public function running(): string
    {
        $deployed = $this->deployed();

        if ($deployed === null || self::isTag($deployed['ref'])) {
            return $this->current();
        }

        return "{$this->current()} ({$deployed['ref']}@".substr($deployed['commit'], 0, 7).')';
    }

    /**
     * The build the channel points at when it differs from the running
     * one: a newer release (or the latest, for a panel that left the
     * releases for a branch), or a commit on the development branch the
     * panel does not run yet. Null while nothing newer is known.
     */
    public function pending(): ?PanelUpdate
    {
        $channel = $this->channel();

        if ($channel === ReleaseChannel::Stable) {
            $latest = $this->latest();

            if ($latest === null) {
                return null;
            }

            return version_compare($latest, $this->current(), '>') || $this->deployedFromBranch()
                ? new PanelUpdate($channel, "v{$latest}", $latest)
                : null;
        }

        $commit = $this->latestCommit();
        $deployed = $this->deployed();
        $branch = (string) $channel->branch();

        if ($commit === null || $deployed === null) {
            return null;
        }

        return $deployed['ref'] !== $branch || $deployed['commit'] !== $commit
            ? new PanelUpdate($channel, $branch, $commit)
            : null;
    }

    public function available(): bool
    {
        return $this->pending() !== null;
    }

    /**
     * Asks GitHub for the latest release tag, or null when it cannot be
     * read (offline, rate limited, private repository, no release yet).
     */
    public function fetchLatest(): ?string
    {
        $response = $this->get('/releases/latest');

        if ($response === null) {
            return null;
        }

        $tag = ltrim((string) ($response['tag_name'] ?? ''), 'v');

        return preg_match('/^\d+\.\d+\.\d+$/', $tag) ? $tag : null;
    }

    /**
     * Asks GitHub for the commit at the head of the development branch, or
     * null when it cannot be read.
     */
    public function fetchLatestCommit(): ?string
    {
        $response = $this->get('/commits/'.ReleaseChannel::Development->branch());

        if ($response === null) {
            return null;
        }

        $sha = (string) ($response['sha'] ?? '');

        return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    /**
     * Fetches the latest release, and the development branch's head when
     * that is the channel, records them, and raises or clears the notice.
     * Returns the latest release when it could be read.
     */
    public function check(): ?string
    {
        $latest = $this->fetchLatest();

        if ($latest !== null) {
            Setting::set(self::LATEST_SETTING, $latest);
        }

        if ($this->channel() === ReleaseChannel::Development) {
            $commit = $this->fetchLatestCommit();

            if ($commit !== null) {
                Setting::set(self::LATEST_COMMIT_SETTING, $commit);
            }
        }

        $this->refreshNotices();

        return $latest;
    }

    /**
     * One unread notice per user while a newer build exists on the
     * channel; none, or none for another build, otherwise.
     */
    public function refreshNotices(): void
    {
        $update = $this->pending();

        if ($update === null) {
            $this->notices()->delete();

            return;
        }

        $this->notices()->where('data->version', '!=', $update->id)->delete();

        if ($this->notices()->whereNull('read_at')->exists()) {
            return;
        }

        foreach (User::query()->get() as $user) {
            $user->notify(new PanelUpdateAvailable($update, $this->running()));
        }
    }

    /**
     * The server the panel runs on, when it manages itself: the one whose
     * inventory reports the panel app, else the one registered at the
     * loopback address by install.sh.
     */
    public function hostingServer(): ?Server
    {
        return Server::query()->whereNotNull('panel_app')->orderBy('name')->first()
            ?? Server::query()->where('host', '127.0.0.1')->first();
    }

    /**
     * Queues `panel:update` for a build on the hosting server, as a task
     * started by the signed-in user. The caller checks for a running task
     * first.
     */
    public function queue(PanelUpdate $update, Server $server): Task
    {
        /** @var Task $task */
        $task = $server->tasks()->create([
            'type' => TaskType::UpdatePanel,
            'user_id' => auth()->id(),
            'options' => $update->channel === ReleaseChannel::Stable
                ? ['version' => $update->ref]
                : ['branch' => $update->ref],
        ]);

        UpdatePanel::dispatch($task);

        return $task;
    }

    public static function isTag(string $ref): bool
    {
        return preg_match('/^v\d+\.\d+\.\d+$/', $ref) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        $repository = (string) config('mandal.repository');

        try {
            $response = $this->client()->get("/repos/{$repository}{$path}");
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
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
