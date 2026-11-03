<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Filament\Resources\Servers\Pages\ServerFail2ban;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerSsh;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * The sidebar shared by the security pages of a server: Firewall, SSH and
 * fail2ban, laid out like the settings pages.
 */
trait HasSecuritySidebar
{
    /** @var list<class-string> */
    public const array SECURITY_PAGES = [
        ServerFirewall::class,
        ServerSsh::class,
        ServerFail2ban::class,
    ];

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems(self::SECURITY_PAGES);
    }
}
