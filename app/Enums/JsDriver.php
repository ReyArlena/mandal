<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * JavaScript tooling used for front-end builds. See server/lib/roles/js.
 */
enum JsDriver: string implements HasLabel
{
    case Bun = 'bun';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bun => 'Bun',
        };
    }

    public static function default(): self
    {
        return self::Bun;
    }
}
