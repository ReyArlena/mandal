<?php

use App\Filament\ServerNavigation;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Illuminate\Http\Request;

/**
 * Make the given URL the page being looked at, the way Filament sees it
 * during a full request or a Livewire update.
 */
function openPage(string $url): void
{
    $request = Request::create($url);
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    app()->instance('originalRequest', $request);
}

/**
 * @return array<NavigationItem>
 */
function sidebarItems(): array
{
    return collect(ServerNavigation::build(new NavigationBuilder)->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->values()
        ->all();
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->web = Server::factory()->create(['name' => 'web-1']);
    $this->db = Server::factory()->provisioned()->create(['name' => 'db-1']);
});

it('lists the server sections in the sidebar inside a server', function () {
    openPage("/servers/{$this->web->getKey()}/php");

    $items = sidebarItems();

    expect(collect($items)->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all())
        ->toBe(['View', 'Apps', 'Inventory', 'PHP', 'Firewall', 'Databases', 'Logs', 'Updates', 'Tasks', 'Edit'])
        ->and(collect($items)->filter(fn (NavigationItem $item): bool => $item->isActive())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->values()->all())->toBe(['PHP'])
        ->and($items[3]->getUrl())->toEndWith("/servers/{$this->web->getKey()}/php");
});

it('keeps the parent section active on app and task pages', function () {
    openPage("/servers/{$this->web->getKey()}/apps/shop");
    expect(ServerNavigation::currentSection())->toBe('apps');

    openPage("/servers/{$this->web->getKey()}/tasks/1");
    expect(ServerNavigation::currentSection())->toBe('tasks');

    openPage("/servers/{$this->web->getKey()}/backups");
    expect(ServerNavigation::currentSection())->toBe('databases');
});

it('lists the servers in the sidebar outside a server', function () {
    openPage('/settings');

    expect(collect(sidebarItems())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all())
        ->toBe(['All servers', 'db-1', 'web-1', 'Add server'])
        ->and(ServerNavigation::currentServer())->toBeNull();
});

it('switches to the same section of another server', function () {
    openPage("/servers/{$this->web->getKey()}/firewall");
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}/firewall");

    openPage("/servers/{$this->web->getKey()}/apps/shop");
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}/apps");

    openPage("/servers/{$this->web->getKey()}/backups");
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}/backups");

    openPage('/');
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}");
});

it('renders the switcher with every server and the current one', function () {
    openPage("/servers/{$this->web->getKey()}/logs");

    $html = view('filament.server-switcher')->render();

    expect($html)
        ->toContain('web-1')
        ->toContain('db-1')
        ->toContain("/servers/{$this->db->getKey()}/logs")
        ->toContain('All servers')
        ->toContain('Add server');
});

it('asks to choose a server outside one', function () {
    openPage('/servers');

    expect(view('filament.server-switcher')->render())->toContain('Choose a server');
});

it('carries the switcher in the sidebar drawer on small screens only', function () {
    openPage("/servers/{$this->web->getKey()}/logs");

    expect(view('filament.server-switcher', ['placement' => 'sidebar'])->render())
        ->toContain('lg:hidden')
        ->toContain('web-1')
        ->and(view('filament.server-switcher')->render())->not->toContain('lg:hidden');
});

it('offers settings in the user menu instead of the sidebar', function () {
    expect(Filament::getPanel('admin')->getUserMenuItems())->toHaveKey('settings');
});
