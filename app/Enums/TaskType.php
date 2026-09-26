<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskType: string implements HasLabel
{
    case Provision = 'provision';
    case CreateApp = 'create-app';
    case DeleteApp = 'delete-app';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provision => 'Provision',
            self::CreateApp => 'Create app',
            self::DeleteApp => 'Delete app',
        };
    }
}
