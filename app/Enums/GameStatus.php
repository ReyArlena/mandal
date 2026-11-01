<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a server details page shows next to a game server linked to a
 * Docker app: its container running, stopped, or not known (the server
 * could not be reached, the app is gone, or it was never checked).
 */
enum GameStatus: string implements HasColor, HasLabel
{
    case Online = 'online';
    case Paused = 'paused';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Paused => 'warning',
            self::Unknown => 'gray',
        };
    }
}
