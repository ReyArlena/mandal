<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Database servers mandal can install. One option for now; the server-side
 * driver files live in server/lib/roles/database.
 */
enum DatabaseDriver: string implements HasLabel
{
    case MariaDb = 'mariadb';

    public function getLabel(): string
    {
        return match ($this) {
            self::MariaDb => 'MariaDB',
        };
    }

    public static function default(): self
    {
        return self::MariaDb;
    }
}
