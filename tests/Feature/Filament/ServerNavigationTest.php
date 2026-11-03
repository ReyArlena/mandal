<?php

use App\Filament\ServerNavigation;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
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

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->web = Server::factory()->create(['name' => 'web-1']);
    $this->db = Server::factory()->provisioned()->create(['name' => 'db-1']);
});

it('lists the server sections as tabs inside a server', function () {
    openPage("/servers/{$this->web->getKey()}/php");

    $items = ServerNavigation::items();

    expect(collect($items)->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all())
        ->toBe(['Server', 'Apps', 'PHP', 'Security', 'Databases', 'Logs', 'Updates', 'Tasks', 'Edit'])
        ->and(collect($items)->filter(fn (NavigationItem $item): bool => $item->isActive())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->values()->all())->toBe(['PHP'])
        ->and($items[2]->getUrl())->toEndWith("/servers/{$this->web->getKey()}/php");
});

it('keeps the parent section active on app and task pages', function () {
    openPage("/servers/{$this->web->getKey()}/apps/shop");
    expect(ServerNavigation::currentSection())->toBe('apps');

    openPage("/servers/{$this->web->getKey()}/tasks/1");
    expect(ServerNavigation::currentSection())->toBe('tasks');

    openPage("/servers/{$this->web->getKey()}/backups");
    expect(ServerNavigation::currentSection())->toBe('databases');

    openPage("/servers/{$this->web->getKey()}/security/ssh");
    expect(ServerNavigation::currentSection())->toBe('security');
});

it('switches between servers and settings above the servers table and settings pages', function () {
    $labels = fn (): array => collect(ServerNavigation::items())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all();
    $active = fn (): array => collect(ServerNavigation::items())->filter(fn (NavigationItem $item): bool => $item->isActive())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->values()->all();

    openPage('/servers');
    expect($labels())->toBe(['Servers', 'Settings'])
        ->and($active())->toBe(['Servers'])
        ->and(ServerNavigation::currentServer())->toBeNull();

    openPage('/settings/backup-destinations');
    expect($labels())->toBe(['Servers', 'Settings'])
        ->and($active())->toBe(['Settings']);

    openPage('/settings/details-pages/create');
    expect($active())->toBe(['Settings']);
});

it('shows no tabs on other pages outside a server', function () {
    openPage('/servers/create');

    expect(ServerNavigation::items())->toBe([]);
});

it('switches to the same section of another server', function () {
    openPage("/servers/{$this->web->getKey()}/security");
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}/security");

    openPage("/servers/{$this->web->getKey()}/security/fail2ban");
    expect(ServerNavigation::switchUrl($this->db))->toEndWith("/servers/{$this->db->getKey()}/security/fail2ban");

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

it('shows all servers outside one, with the server list between all servers and add server', function () {
    openPage('/servers');

    expect(view('filament.server-switcher')->render())
        ->not->toContain('Choose a server')
        ->toMatch('/All servers.*db-1.*web-1.*Add server/s');
});

it('offers settings in the user menu', function () {
    expect(Filament::getPanel('admin')->getUserMenuItems())->toHaveKey('settings');
});
