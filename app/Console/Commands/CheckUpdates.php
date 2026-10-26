<?php

namespace App\Console\Commands;

use App\Mandal\CliUpdates;
use App\Mandal\MandalClient;
use App\Mandal\PanelReleases;
use Illuminate\Console\Command;

/**
 * Reads each connected server's CLI version and asks GitHub for the
 * latest Mandal release, notifying the panel's users about servers that
 * run an older CLI and about a newer panel release. Scheduled hourly.
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

        if ($latest === null) {
            $this->line('The latest release could not be read from GitHub.');
        } elseif ($releases->available()) {
            $this->warn("Mandal {$latest} is available; this panel runs {$releases->current()}. Run `sudo mandal panel:update` on the hosting server.");
        } else {
            $this->info("This panel runs the latest release ({$releases->current()}).");
        }

        return self::SUCCESS;
    }
}
