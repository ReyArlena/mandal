<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WwwMode: string implements HasLabel
{
    case RedirectToNaked = 'redirect-to-naked';
    case RedirectToWww = 'redirect-to-www';
    case NakedOnly = 'naked-only';

    public function getLabel(): string
    {
        return match ($this) {
            self::RedirectToNaked => 'Redirect www to the naked domain',
            self::RedirectToWww => 'Redirect the naked domain to www',
            self::NakedOnly => 'Naked domain only',
        };
    }

    public static function default(): self
    {
        return self::RedirectToNaked;
    }
}
