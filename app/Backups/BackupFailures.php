<?php

namespace App\Backups;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BackupFailed;
use App\Ssh\SshException;

/**
 * Raises one notice per failed backup run. The last run the panel has
 * already notified is remembered per server and target in the settings
 * table, so a failure is reported once and a later good run clears it.
 */
class BackupFailures
{
    public function __construct(
        private readonly MandalClient $mandal,
    ) {}

    /**
     * Checks every provisioned server. Returns the targets notified about,
     * keyed by server name.
     *
     * @return array<string, list<string>>
     */
    public function checkAll(): array
    {
        $notified = [];

        foreach (Server::query()->whereNotNull('provisioned_at')->orderBy('name')->get() as $server) {
            try {
                $targets = $this->check($server);
            } catch (SshException) {
                continue;
            }

            if ($targets !== []) {
                $notified[$server->name] = $targets;
            }
        }

        return $notified;
    }

    /**
     * @return list<string> the targets a notice was sent for
     *
     * @throws SshException
     */
    public function check(Server $server): array
    {
        $result = $this->mandal->run($server, 'backup:status', timeoutSeconds: 60);

        if ($result->failed()) {
            throw new SshException(trim($result->errorOutput ?: $result->output) ?: 'backup:status failed');
        }

        $status = $result->json();
        $key = 'backups_notified:'.$server->getKey();
        $seen = json_decode(Setting::get($key) ?? '[]', associative: true);
        $seen = is_array($seen) ? $seen : [];
        $notified = [];

        $runs = ['databases' => $status['last_run'] ?? null];

        foreach (is_array($status['apps'] ?? null) ? $status['apps'] : [] as $app) {
            if (is_string($app['name'] ?? null)) {
                $runs['app:'.$app['name']] = $app['last_run'] ?? null;
            }
        }

        foreach ($runs as $target => $run) {
            $at = is_array($run) ? (string) ($run['at'] ?? '') : '';
            $state = is_array($run) ? (string) ($run['status'] ?? '') : '';

            if ($at === '' || $state === '' || $state === 'ok') {
                unset($seen[$target]);

                continue;
            }

            if (($seen[$target] ?? null) === $at) {
                continue;
            }

            foreach (User::query()->get() as $user) {
                $user->notify(new BackupFailed($server, $target, $state, (string) ($run['rest'] ?? '')));
            }

            $seen[$target] = $at;
            $notified[] = $target;
        }

        Setting::set($key, $seen === [] ? null : json_encode($seen, JSON_THROW_ON_ERROR));

        return $notified;
    }
}
