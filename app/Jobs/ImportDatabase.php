<?php

namespace App\Jobs;

use App\Mandal\InvalidImport;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Runs `mandal db:import` with a dump uploaded through the panel. The CLI
 * backs the database up first, so the previous contents can be restored
 * from the Backups tab.
 */
class ImportDatabase extends RunMandalTask
{
    public int $timeout = 7200;

    public function arguments(): string
    {
        $options = $this->task->options ?? [];
        $database = (string) ($options['database'] ?? '');

        if (! preg_match('/^[a-z0-9_]{1,64}$/', $database)) {
            throw new InvalidArgumentException("Invalid database [{$database}].");
        }

        return 'db:import --name='.$database.(empty($options['replace']) ? '' : ' --replace');
    }

    /**
     * The dump travels as a file, streamed from the panel's disk.
     */
    protected function files(): array
    {
        $options = $this->task->options ?? [];
        $name = (string) ($options['file_name'] ?? 'the dump');
        $path = CreateApp::importPath((string) ($options['file'] ?? ''));

        if ($path === null) {
            throw new InvalidImport("The uploaded file {$name} is no longer on the panel; import it again.");
        }

        $this->task->appendOutput("Uploading {$name}\n");

        return ['--file' => $path];
    }

    protected function cleanUp(): void
    {
        $path = CreateApp::importPath((string) ($this->task->options['file'] ?? ''));

        if ($path !== null) {
            File::delete($path);
        }
    }
}
