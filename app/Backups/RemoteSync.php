<?php

namespace App\Backups;

use App\Mandal\MandalClient;
use App\Models\BackupDestination;
use App\Models\Server;
use App\Ssh\CommandResult;
use App\Ssh\SshException;

/**
 * Keeps the remotes on managed servers in step with the destinations in
 * the panel: a destination is pushed right before a backup starts using
 * it, and detached from every server when it is removed.
 */
class RemoteSync
{
    public function __construct(
        private readonly MandalClient $mandal,
    ) {}

    /**
     * Write the destination's remote on the server. The CLI checks the
     * bucket is reachable and fails otherwise.
     *
     * @throws SshException when the server cannot be reached or the CLI refuses
     */
    public function push(Server $server, BackupDestination $destination): void
    {
        $result = $this->mandal->runWithUpload(
            $server,
            'backup:remote --name='.$destination->slug,
            $destination->remoteFile(),
            '.conf',
            timeoutSeconds: 180,
        );

        if ($result->failed()) {
            throw new SshException($this->message($result, 'backup:remote'));
        }
    }

    /**
     * Push the destination the target uses (if any), then upload the target
     * file to the configure command.
     *
     * @param  list<string>  $lines  the target conf lines, REMOTE= among them
     *
     * @throws SshException
     */
    public function configure(Server $server, string $command, array $lines): void
    {
        $slug = BackupTargetForm::remoteSlug($lines);

        if ($slug !== null) {
            $destination = BackupDestination::query()->where('slug', $slug)->first();

            if ($destination === null) {
                throw new SshException('That destination no longer exists. Choose another one.');
            }

            $this->push($server, $destination);
        }

        $result = $this->mandal->runWithUpload($server, $command, implode("\n", $lines)."\n", '.conf', timeoutSeconds: 120);

        if ($result->failed()) {
            throw new SshException($this->message($result, $command));
        }

        $this->mandal->forgetInventory($server);
    }

    /**
     * Remove the destination's remote from every provisioned server. The
     * CLI switches any target using it off that remote and disables targets
     * left without a destination, reporting both as JSON on its last line.
     * Servers that cannot be reached are reported, never a reason to stop.
     *
     * @return array{servers: array<string, array{detached: list<string>, disabled: list<string>}>, unreachable: array<string, string>}
     */
    public function remove(BackupDestination $destination): array
    {
        $servers = [];
        $unreachable = [];

        foreach (Server::query()->whereNotNull('provisioned_at')->orderBy('name')->get() as $server) {
            try {
                $result = $this->mandal->run($server, 'backup:remote --name='.$destination->slug.' --remove --force', timeoutSeconds: 120);
            } catch (SshException $exception) {
                $unreachable[$server->name] = $exception->getMessage();

                continue;
            }

            if ($result->failed()) {
                $unreachable[$server->name] = $this->message($result, 'backup:remote');

                continue;
            }

            $this->mandal->forgetInventory($server);

            $lines = array_values(array_filter(array_map('trim', explode("\n", $result->output))));
            $report = json_decode((string) end($lines), associative: true);

            $servers[$server->name] = [
                'detached' => is_array($report['detached'] ?? null) ? array_values($report['detached']) : [],
                'disabled' => is_array($report['disabled'] ?? null) ? array_values($report['disabled']) : [],
            ];
        }

        return ['servers' => $servers, 'unreachable' => $unreachable];
    }

    private function message(CommandResult $result, string $command): string
    {
        return trim($result->errorOutput ?: $result->output) ?: "mandal {$command} failed with exit code {$result->exitCode}";
    }
}
