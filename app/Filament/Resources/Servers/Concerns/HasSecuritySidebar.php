<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Filament\Resources\Servers\Pages\ServerFail2ban;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerSsh;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Contracts\Support\Htmlable;

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

    /**
     * The heading names the section (the tab above), not the sidebar page;
     * the page's own title stays in the browser tab and the sidebar.
     */
    public function getHeading(): string|Htmlable|null
    {
        return 'Security';
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems(self::SECURITY_PAGES);
    }
}
