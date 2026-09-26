<?php

namespace App\Jobs;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\JsDriver;
use App\Models\Server;
use App\Ssh\CommandResult;

/**
 * Runs `mandal provision` on a server.
 */
class ProvisionServer extends RunMandalTask
{
    public function arguments(): string
    {
        $options = $this->task->options ?? [];

        $arguments = [
            'provision',
            '--database='.DatabaseDriver::from($options['database'] ?? DatabaseDriver::default()->value)->value,
            '--cache='.CacheDriver::from($options['cache'] ?? CacheDriver::default()->value)->value,
            '--js='.JsDriver::from($options['js'] ?? JsDriver::default()->value)->value,
        ];

        if (! empty($options['php']) && preg_match('/^\d+\.\d+$/', (string) $options['php'])) {
            $arguments[] = '--php='.$options['php'];
        }

        if (! empty($options['skip_ssh_hardening'])) {
            $arguments[] = '--skip-ssh-hardening';
        }

        return implode(' ', $arguments);
    }

    protected function succeeded(Server $server, CommandResult $result): void
    {
        $server->forceFill(['provisioned_at' => now()])->save();
    }
}
