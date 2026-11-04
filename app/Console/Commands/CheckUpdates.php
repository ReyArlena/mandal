<?php

namespace App\Console\Commands;

use App\Enums\ReleaseChannel;
use App\Mandal\CliUpdates;
use App\Mandal\MandalClient;
use App\Mandal\PanelReleases;
use Illuminate\Console\Command;

/**
 * Reads each connected server's CLI version and asks GitHub for the
 * latest Mandal release (and the development branch's head, on that
 * channel), notifying the panel's users about servers that run an older
 * CLI and about a newer panel build. Scheduled hourly.
 */
class CheckUpdates extends Command
{
    protected $signature = 'mandal:check-updates';

    protected $description = 'Check servers for an out-of-date CLI and GitHub for a newer Mandal release, and notify users';

    public function handle(CliUpdates $updates, MandalClient $mandal, PanelReleases $releases): int
    {
        $outdated = $updates->checkAll($mandal);

        if ($outdated === []) {
            $this->info('Every reachable server runs the current CLI.');
        }

        foreach ($outdated as $server) {
            $this->warn("{$server->name} runs CLI build {$server->cli_build}; the panel ships {$updates->panelBuild()}.");
        }

        $latest = $releases->check();
        $update = $releases->pending();

        if ($latest === null) {
            $this->line('The latest release could not be read from GitHub.');
        }

        if ($releases->channel() === ReleaseChannel::Development && $releases->latestCommit() === null) {
            $this->line('The '.ReleaseChannel::Development->branch().' branch could not be read from GitHub.');
        }

        if ($update !== null) {
            $this->warn("{$update->label()} is available; this panel runs {$releases->running()}. Update it under Settings > Updates.");
        } elseif ($releases->channel() === ReleaseChannel::Development) {
            $this->info("This panel runs the latest development build ({$releases->running()}).");
        } elseif ($latest !== null) {
            $this->info("This panel runs the latest release ({$releases->current()}).");
        }

        return self::SUCCESS;
    }
}
