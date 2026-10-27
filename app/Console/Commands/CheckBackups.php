<?php

namespace App\Console\Commands;

use App\Backups\BackupFailures;
use Illuminate\Console\Command;

/**
 * Reads each provisioned server's backup status and notifies the panel's
 * users about scheduled runs that failed, once per run. Scheduled hourly.
 */
class CheckBackups extends Command
{
    protected $signature = 'mandal:check-backups';

    protected $description = 'Check servers for failed scheduled backups and notify users';

    public function handle(BackupFailures $failures): int
    {
        $notified = $failures->checkAll();

        if ($notified === []) {
            $this->info('No new backup failures.');
        }

        foreach ($notified as $server => $targets) {
            $this->warn("{$server}: ".implode(', ', $targets).' failed; users have been notified.');
        }

        return self::SUCCESS;
    }
}
