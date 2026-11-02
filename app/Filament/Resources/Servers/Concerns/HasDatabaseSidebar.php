<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Filament\Resources\Servers\Pages\ServerDatabases;
use App\Filament\Resources\Servers\Pages\ServerDatabaseUsers;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * The sidebar shared by the database pages of a server: Databases, Users
 * and Backups, laid out like the settings pages.
 */
trait HasDatabaseSidebar
{
    /** @var list<class-string> */
    public const array DATABASE_PAGES = [
        ServerDatabases::class,
        ServerDatabaseUsers::class,
        ServerBackups::class,
    ];

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems(self::DATABASE_PAGES);
    }
}
