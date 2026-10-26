<?php

namespace App\Mandal;

use App\Models\Server;
use App\Models\User;
use App\Notifications\CliUpdateRequired;
use App\Ssh\SshException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Keeps the "CLI update required" notices in step with what each server
 * runs: one unread notice per user while a server's build differs from
 * the panel's, none once it matches.
 */
class CliUpdates
{
    public function __construct(
        private readonly CliArchive $archive,
    ) {}

    /**
     * Reads every connected server's version and raises or clears its
     * notice. The panel's own build may have changed since the server was
     * last read, so the notice is checked even when the server's has not.
     * Returns the servers that need an update.
     *
     * @return list<Server>
     */
    public function checkAll(MandalClient $mandal): array
    {
        $outdated = [];

        foreach (Server::query()->whereNotNull('connected_at')->orderBy('name')->get() as $server) {
            try {
                $mandal->version($server);
            } catch (SshException) {
                continue;
            }

            $this->check($server);

            if ($server->cliOutdated($this->archive->hash())) {
                $outdated[] = $server;
            }
        }

        return $outdated;
    }

    /**
     * Raises the notice for a server whose recorded build differs from the
     * panel's, or clears it when they match.
     */
    public function check(Server $server): void
    {
        $notices = $this->notices($server);

        if (! $server->cliOutdated($this->archive->hash())) {
            $notices->delete();

            return;
        }

        if ($notices->whereNull('read_at')->exists()) {
            return;
        }

        foreach (User::query()->get() as $user) {
            $user->notify(new CliUpdateRequired($server, $this->archive->version()));
        }
    }

    public function clear(Server $server): void
    {
        $this->notices($server)->delete();
    }

    public function panelBuild(): string
    {
        return $this->archive->hash();
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function notices(Server $server): Builder
    {
        return DatabaseNotification::query()
            ->where('type', CliUpdateRequired::class)
            ->where('data->server_id', $server->getKey());
    }
}
