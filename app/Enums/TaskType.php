<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskType: string implements HasLabel
{
    case Provision = 'provision';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provision => 'Provision',
        };
    }
}
