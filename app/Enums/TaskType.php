<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskType: string implements HasLabel
{
    case Provision = 'provision';
    case CreateApp = 'create-app';
    case DeleteApp = 'delete-app';
    case Deploy = 'deploy';
    case Rollback = 'rollback';
    case Certificate = 'certificate';
    case UpdateCli = 'update-cli';
    case Backup = 'backup';
    case Restore = 'restore';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provision => 'Provision',
            self::CreateApp => 'Create app',
            self::DeleteApp => 'Delete app',
            self::Deploy => 'Deploy',
            self::Rollback => 'Roll back',
            self::Certificate => 'Request certificate',
            self::UpdateCli => 'Update CLI',
            self::Backup => 'Back up',
            self::Restore => 'Restore backup',
        };
    }
}
