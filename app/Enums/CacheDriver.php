<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Redis-compatible caches mandal can install. See server/lib/roles/cache.
 */
enum CacheDriver: string implements HasLabel
{
    case Dragonfly = 'dragonfly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dragonfly => 'Dragonfly',
        };
    }

    public static function default(): self
    {
        return self::Dragonfly;
    }
}
