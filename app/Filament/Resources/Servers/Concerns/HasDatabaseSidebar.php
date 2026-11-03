<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Filament\Resources\Servers\Pages\ServerDatabases;
use App\Filament\Resources\Servers\Pages\ServerDatabaseUsers;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Contracts\Support\Htmlable;

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

    /**
     * The heading names the section (the tab above), not the sidebar page;
     * the page's own title stays in the browser tab and the sidebar.
     */
    public function getHeading(): string|Htmlable|null
    {
        return 'Databases';
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems(self::DATABASE_PAGES);
    }
}
