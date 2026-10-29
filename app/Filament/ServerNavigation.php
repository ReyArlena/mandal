<?php

namespace App\Filament;

use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Filament\Resources\Servers\Pages\ServerDatabases;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerLogs;
use App\Filament\Resources\Servers\Pages\ServerPhp;
use App\Filament\Resources\Servers\Pages\ServerTasks;
use App\Filament\Resources\Servers\Pages\ServerUpdates;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Navigation\NavigationItem;
use Illuminate\Database\Eloquent\Collection;

use function Filament\Support\original_request;

/**
 * The tab bar above the page title lists the sections of the server being
 * looked at; outside a server there is none, as the servers table and the
 * switcher already list every server. The server switcher in the top bar
 * uses the same lookup to keep you in the section you are on when you switch.
 */
class ServerNavigation
{
    /**
     * Sections of a server in tab order, keyed by resource page name.
     *
     * @var array<string, class-string>
     */
    public const array SECTIONS = [
        'view' => ViewServer::class,
        'apps' => ServerApps::class,
        'php' => ServerPhp::class,
        'firewall' => ServerFirewall::class,
        'databases' => ServerDatabases::class,
        'logs' => ServerLogs::class,
        'updates' => ServerUpdates::class,
        'tasks' => ServerTasks::class,
        'edit' => EditServer::class,
    ];

    /**
     * Pages below a section, which keep that section highlighted.
     *
     * @var array<string, string>
     */
    private const array CHILD_PAGES = [
        'app' => 'apps',
        'backups' => 'databases',
        'task' => 'tasks',
    ];

    /**
     * Child pages that exist on every server (tabs of a section), so the
     * switcher can stay on them instead of going back to the section.
     *
     * @var list<string>
     */
    private const array SERVER_WIDE_CHILD_PAGES = ['backups'];

    /**
     * The sections of the open server, or none outside a server.
     *
     * @return array<NavigationItem>
     */
    public static function items(): array
    {
        $server = static::currentServer();

        return $server === null ? [] : static::sectionItems($server);
    }

    /**
     * The server whose pages are open, also during Livewire updates.
     */
    public static function currentServer(): ?Server
    {
        if (static::currentPage() === null) {
            return null;
        }

        $record = original_request()->route('record');

        if ($record instanceof Server) {
            return $record;
        }

        return filled($record) ? Server::query()->find($record) : null;
    }

    /**
     * The section the open page belongs to, such as "php" or "apps".
     */
    public static function currentSection(): ?string
    {
        $page = static::currentPage();

        return $page === null ? null : (self::CHILD_PAGES[$page] ?? $page);
    }

    /**
     * Where switching to a server leads: the same page or section, or its overview.
     */
    public static function switchUrl(Server $server): string
    {
        $page = static::currentPage();
        $section = static::currentSection();

        $target = match (true) {
            in_array($page, self::SERVER_WIDE_CHILD_PAGES, true) => $page,
            array_key_exists($section ?? '', self::SECTIONS) => $section,
            default => 'view',
        };

        return ServerResource::getUrl($target, ['record' => $server]);
    }

    /**
     * @return Collection<int, Server>
     */
    public static function servers(): Collection
    {
        return Server::query()->orderBy('name')->get();
    }

    /**
     * @return array<NavigationItem>
     */
    private static function sectionItems(Server $server): array
    {
        $current = static::currentSection();
        $items = [];

        foreach (self::SECTIONS as $name => $page) {
            $items[] = NavigationItem::make($page::getNavigationLabel())
                ->icon($page::getNavigationIcon())
                ->url(ServerResource::getUrl($name, ['record' => $server]))
                ->isActiveWhen(fn (): bool => $current === $name);
        }

        return $items;
    }

    /**
     * The server resource page name of the open page, or null elsewhere.
     */
    private static function currentPage(): ?string
    {
        $prefix = ServerResource::getRouteBaseName().'.';
        $route = original_request()->route()?->getName() ?? '';

        return str_starts_with($route, $prefix) ? substr($route, strlen($prefix)) : null;
    }
}
