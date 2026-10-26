<?php

namespace App\Console\Commands;

use App\Mandal\CliUpdates;
use App\Mandal\MandalClient;
use Illuminate\Console\Command;

/**
 * Reads each connected server's CLI version and notifies the panel's
 * users about any that run an older build. Scheduled hourly.
 */
class CheckCli extends Command
{
    protected $signature = 'mandal:check-cli';

    protected $description = 'Check every server for an out-of-date mandal CLI and notify users';

    public function handle(CliUpdates $updates, MandalClient $mandal): int
    {
        $outdated = $updates->checkAll($mandal);

        if ($outdated === []) {
            $this->info('Every reachable server runs the current CLI.');

            return self::SUCCESS;
        }

        foreach ($outdated as $server) {
            $this->warn("{$server->name} runs CLI build {$server->cli_build}; the panel ships {$updates->panelBuild()}.");
        }

        return self::SUCCESS;
    }
}
