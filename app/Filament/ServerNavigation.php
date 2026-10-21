<?php

namespace App\Filament;

use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Filament\Resources\Servers\Pages\ServerBackups;
use App\Filament\Resources\Servers\Pages\ServerFirewall;
use App\Filament\Resources\Servers\Pages\ServerInventory;
use App\Filament\Resources\Servers\Pages\ServerLogs;
use App\Filament\Resources\Servers\Pages\ServerPhp;
use App\Filament\Resources\Servers\Pages\ServerTasks;
use App\Filament\Resources\Servers\Pages\ServerUpdates;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

use function Filament\Support\original_request;

/**
 * The sidebar follows the server being looked at: inside a server it lists
 * that server's sections, everywhere else it lists the servers. The server
 * switcher in the top bar uses the same lookup to keep you in the section
 * you are on when you switch.
 */
class ServerNavigation
{
    /**
     * Sections of a server in sidebar order, keyed by resource page name.
     *
     * @var array<string, class-string>
     */
    public const array SECTIONS = [
        'view' => ViewServer::class,
        'apps' => ServerApps::class,
        'inventory' => ServerInventory::class,
        'php' => ServerPhp::class,
        'firewall' => ServerFirewall::class,
        'backups' => ServerBackups::class,
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
        'task' => 'tasks',
    ];

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $server = static::currentServer();

        return $builder->items($server === null ? static::serverItems() : static::sectionItems($server));
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
     * Where switching to a server leads: the same section, or its overview.
     */
    public static function switchUrl(Server $server): string
    {
        $section = static::currentSection();

        return ServerResource::getUrl(array_key_exists($section ?? '', self::SECTIONS) ? $section : 'view', ['record' => $server]);
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
     * @return array<NavigationItem>
     */
    private static function serverItems(): array
    {
        $page = static::currentPage();

        return [
            NavigationItem::make('All servers')
                ->icon(Heroicon::OutlinedServerStack)
                ->url(ServerResource::getUrl('index'))
                ->isActiveWhen(fn (): bool => $page === 'index'),
            ...static::servers()->map(fn (Server $server): NavigationItem => NavigationItem::make($server->name)
                ->icon(Heroicon::OutlinedServer)
                ->url(ServerResource::getUrl('view', ['record' => $server]))),
            NavigationItem::make('Add server')
                ->icon(Heroicon::OutlinedPlus)
                ->url(ServerResource::getUrl('create'))
                ->isActiveWhen(fn (): bool => $page === 'create'),
        ];
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
