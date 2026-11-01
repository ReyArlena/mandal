<?php

namespace App\DetailsPages;

use App\Enums\GameStatus;
use App\Mandal\MandalClient;
use App\Models\DetailsPageEntry;
use App\Models\Server;
use App\Ssh\SshException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps the status of game servers on details pages in step with the
 * Docker containers they follow. Nothing polls: the status is stored
 * whenever the panel reads a server's inventory anyway, and after a task
 * on that server finishes, so visitors never cause an SSH connection.
 * Bound per request, so the container list is read once however many
 * fields ask for it.
 */
class GameStatuses
{
    /**
     * @var array<string, string>|null
     */
    private ?array $containerOptions = null;

    /**
     * Stores the status of every entry following a container on the
     * server, from an inventory just read from it.
     *
     * @param  array<string, mixed>  $inventory
     */
    public function record(Server $server, array $inventory): void
    {
        $entries = $this->linked($server);

        if ($entries->isEmpty()) {
            return;
        }

        $states = collect($inventory['docker']['containers'] ?? [])->pluck('State', 'Names');

        foreach ($entries as $entry) {
            $this->store($entry, match ($states->get($entry->container)) {
                null => GameStatus::Unknown,
                'running' => GameStatus::Online,
                default => GameStatus::Paused,
            });
        }
    }

    /**
     * The server could not be read, so nothing on it is known.
     */
    public function unreachable(Server $server): void
    {
        foreach ($this->linked($server) as $entry) {
            $this->store($entry, GameStatus::Unknown);
        }
    }

    /**
     * Reads the server's inventory again when anything follows a container
     * on it, after a change that may have started or stopped one. Called
     * at the end of tasks, so a failure here is reported and never fails
     * the task that finished.
     */
    public function refresh(Server $server): void
    {
        try {
            if (! $this->linked($server)->isEmpty()) {
                $this->check($server, fresh: true);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Stores the status of entries following containers on the server from
     * its inventory, the cached one unless asked for a fresh read.
     */
    public function check(Server $server, bool $fresh = false): void
    {
        try {
            $this->record($server, app(MandalClient::class)->inventory($server, $fresh));
        } catch (SshException) {
            // The inventory read has marked the entries unknown.
        }
    }

    /**
     * Every container on every connected server, running or not, as
     * "<server id>:<container>" => "<container> (<server>)". Servers that
     * cannot be read are left out.
     *
     * @return array<string, string>
     */
    public function containerOptions(): array
    {
        if ($this->containerOptions !== null) {
            return $this->containerOptions;
        }

        $options = [];

        foreach (Server::query()->whereNotNull('connected_at')->orderBy('name')->get() as $server) {
            // A server that just failed is skipped for a minute, so each
            // change in the form does not wait for its connection timeout.
            $failed = "details-pages.unreachable.{$server->getKey()}";

            if (Cache::has($failed)) {
                continue;
            }

            try {
                $containers = app(MandalClient::class)->inventory($server)['docker']['containers'] ?? [];
            } catch (SshException) {
                Cache::put($failed, true, 60);

                continue;
            }

            foreach ($containers as $container) {
                if (is_string($name = $container['Names'] ?? null)) {
                    $options[self::optionKey($server->getKey(), $name)] = "{$name} ({$server->name})";
                }
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->containerOptions = $options;
    }

    public static function optionKey(int|string $serverId, string $container): string
    {
        return "{$serverId}:{$container}";
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    public static function parseOptionKey(?string $key): ?array
    {
        if ($key === null || ! preg_match('/^(\d+):(.+)$/', $key, $matches)) {
            return null;
        }

        return [(int) $matches[1], $matches[2]];
    }

    private function store(DetailsPageEntry $entry, GameStatus $status): void
    {
        $entry->forceFill(['status' => $status, 'status_checked_at' => now()])->saveQuietly();
    }

    /**
     * @return Collection<int, DetailsPageEntry>
     */
    private function linked(Server $server): Collection
    {
        return DetailsPageEntry::query()
            ->where('status_source', DetailsPageEntry::SOURCE_CONTAINER)
            ->where('server_id', $server->getKey())
            ->whereNotNull('container')
            ->get();
    }
}
