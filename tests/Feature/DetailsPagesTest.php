<?php

use App\DetailsPages\GameStatuses;
use App\Enums\GameStatus;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\CreateDetailsPage;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\EditDetailsPage;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\ListDetailsPages;
use App\Mandal\MandalClient;
use App\Models\DetailsPage;
use App\Models\DetailsPageEntry;
use App\Models\Server;
use App\Models\User;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Tests\Support\FakeSshClient;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->provisioned()->create(['name' => 'games', 'connected_at' => now()]);
    $this->page = DetailsPage::query()->create(['name' => 'Friends', 'slug' => 'friends-abc123xyz0', 'title' => 'Current Status', 'intro' => 'We switch games around.']);
});

/**
 * An inventory listing Docker containers and their states.
 *
 * @param  array<string, string>  $containers  name => state
 * @return array<string, mixed>
 */
function gamesInventory(array $containers): array
{
    return [
        'apps' => [],
        'docker' => ['containers' => collect($containers)->map(fn (string $state, string $name): array => ['Names' => $name, 'State' => $state])->values()->all()],
    ];
}

function addEntry(DetailsPage $page, array $attributes = []): DetailsPageEntry
{
    return $page->entries()->create([
        'name' => 'Enshrouded',
        'address' => 'play.example.com',
        'password' => 'ears',
        ...$attributes,
    ]);
}

function followContainer(DetailsPage $page, Server $server, string $container, array $attributes = []): DetailsPageEntry
{
    return addEntry($page, ['status_source' => DetailsPageEntry::SOURCE_CONTAINER, 'server_id' => $server->id, 'container' => $container, ...$attributes]);
}

it('shows the page to visitors without signing in or contacting a server', function () {
    followContainer($this->page, $this->server, 'enshrouded-enshrouded-1')
        ->forceFill(['status' => GameStatus::Online])->saveQuietly();
    addEntry($this->page, ['name' => 'Minecraft (Vanilla)', 'version' => '26.1.2 (Java)', 'password' => '(must be added to whitelist)', 'sort' => 1]);

    $this->get('/p/friends-abc123xyz0')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSeeInOrder(['Current Status', 'We switch games around.', 'Enshrouded', 'Online', 'play.example.com', 'ears', 'Minecraft (Vanilla)', 'Version 26.1.2 (Java)', '(must be added to whitelist)']);

    expect($this->ssh->commands)->toBe([]);
});

it('shows the Mandal logo and credit unless the page removes them', function () {
    $this->get('/p/friends-abc123xyz0')
        ->assertSee('Made with')
        ->assertSee('Mandal on GitHub');

    $this->page->update(['hide_branding' => true]);

    $this->get('/p/friends-abc123xyz0')
        ->assertSee('Made with')
        ->assertDontSee('Mandal on GitHub');

    $this->page->update(['hide_branding' => false, 'hide_credit' => true]);

    $this->get('/p/friends-abc123xyz0')
        ->assertDontSee('Made with')
        ->assertSee('Mandal on GitHub');
});

it('returns 404 for an unknown address', function () {
    $this->get('/p/friends')->assertNotFound();
});

it('leaves out the header and statuses when there is nothing to show', function () {
    $this->page->update(['title' => null, 'intro' => null]);
    addEntry($this->page);

    $this->get('/p/friends-abc123xyz0')
        ->assertOk()
        ->assertDontSee('Current Status')
        ->assertDontSee('Paused');
});

it('lists online servers first, then unknown, and folds paused ones away', function () {
    addEntry($this->page, ['name' => 'Paused game', 'status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Paused, 'sort' => 0]);
    addEntry($this->page, ['name' => 'No status game', 'sort' => 1]);
    followContainer($this->page, $this->server, 'unchecked-game-1', ['name' => 'Unknown game', 'sort' => 2]);
    addEntry($this->page, ['name' => 'Online game', 'status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Online, 'sort' => 3]);

    $this->get('/p/friends-abc123xyz0')
        ->assertSeeInOrder(['Online game', 'Unknown game', 'No status game', 'Paused game', 'Show paused servers (1)']);
});

it('opens the paused section when nothing else is listed', function () {
    addEntry($this->page, ['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Paused]);

    expect($this->get('/p/friends-abc123xyz0')->getContent())->toMatch('/id="paused-servers"[^>]*\schecked/');
});

it('shows a status set by hand', function () {
    addEntry($this->page, ['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Paused]);

    $this->get('/p/friends-abc123xyz0')->assertSeeInOrder(['Enshrouded', 'Paused']);
});

it('shows a followed container as unknown until it has been checked', function () {
    followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');

    $this->get('/p/friends-abc123xyz0')->assertSee('Unknown');
});

it('records container states whenever the inventory is read', function () {
    $running = followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
    $stopped = followContainer($this->page, $this->server, 'minecraft-mc-1');
    $gone = followContainer($this->page, $this->server, 'deleted-game-1');
    $manual = addEntry($this->page, ['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Online]);
    $hidden = addEntry($this->page);

    $this->ssh->respondWithJson('inventory', gamesInventory(['enshrouded-enshrouded-1' => 'running', 'enshrouded-init-1' => 'exited', 'minecraft-mc-1' => 'exited']));

    app(MandalClient::class)->inventory($this->server, fresh: true);

    expect($running->fresh()->status)->toBe(GameStatus::Online)
        ->and($stopped->fresh()->status)->toBe(GameStatus::Paused)
        ->and($gone->fresh()->status)->toBe(GameStatus::Unknown)
        ->and($manual->fresh()->status)->toBe(GameStatus::Online)
        ->and($manual->fresh()->status_checked_at)->toBeNull()
        ->and($hidden->fresh()->status)->toBeNull()
        ->and($running->fresh()->status_checked_at)->not->toBeNull();
});

it('marks followed containers unknown when the server cannot be read', function () {
    $entry = followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
    $entry->forceFill(['status' => GameStatus::Online])->saveQuietly();
    $this->ssh->connectionFailure = new SshException('Connection refused');

    expect(fn () => app(MandalClient::class)->inventory($this->server, fresh: true))->toThrow(SshException::class)
        ->and($entry->fresh()->status)->toBe(GameStatus::Unknown);
});

it('reads the inventory again after a change only when a container on the server is followed', function () {
    $this->ssh->respondWithJson('inventory', gamesInventory(['enshrouded-enshrouded-1' => 'running']));
    addEntry($this->page, ['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Paused]);

    app(GameStatuses::class)->refresh($this->server);
    expect($this->ssh->commands)->toBe([]);

    $entry = followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
    app(GameStatuses::class)->refresh($this->server);

    expect($this->ssh->commands)->not->toBe([])
        ->and($entry->fresh()->status)->toBe(GameStatus::Online);
});

it('never fails the caller when refreshing after a task goes wrong', function () {
    followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
    $this->ssh->respondTo('inventory', 'not json at all');

    app(GameStatuses::class)->refresh($this->server);

    expect(DetailsPageEntry::query()->sole()->status)->toBe(GameStatus::Unknown);
});

it('forgets a container status when the container changes, but keeps one set by hand', function () {
    $entry = followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
    $entry->forceFill(['status' => GameStatus::Online, 'status_checked_at' => now()])->saveQuietly();

    $entry->update(['password' => 'new']);
    expect($entry->fresh()->status)->toBe(GameStatus::Online);

    $entry->update(['container' => 'minecraft-mc-1']);
    expect($entry->fresh()->status)->toBeNull()
        ->and($entry->fresh()->status_checked_at)->toBeNull();

    $entry->update(['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'server_id' => null, 'container' => null, 'status' => GameStatus::Paused]);
    expect($entry->fresh()->status)->toBe(GameStatus::Paused);
});

it('generates slugs from the name with a random part', function () {
    expect(DetailsPage::generateSlug('Redfield Servers!'))->toMatch('/^redfield-servers-[a-z0-9]{10}$/')
        ->and(DetailsPage::generateSlug(''))->toMatch('/^[a-z0-9]{10}$/')
        ->and(DetailsPage::generateSlug('Same'))->not->toBe(DetailsPage::generateSlug('Same'));
});

it('lists every container on every connected server in one list', function () {
    $other = Server::factory()->provisioned()->create(['name' => 'alpha', 'connected_at' => now()]);
    Server::factory()->provisioned()->create(['name' => 'never-connected', 'connected_at' => null]);
    $this->ssh->respondWithJson('inventory', gamesInventory(['enshrouded-enshrouded-1' => 'running', 'minecraft-mc-1' => 'exited']));

    expect(app(GameStatuses::class)->containerOptions())->toBe([
        "{$other->id}:enshrouded-enshrouded-1" => 'enshrouded-enshrouded-1 (alpha)',
        "{$this->server->id}:enshrouded-enshrouded-1" => 'enshrouded-enshrouded-1 (games)',
        "{$other->id}:minecraft-mc-1" => 'minecraft-mc-1 (alpha)',
        "{$this->server->id}:minecraft-mc-1" => 'minecraft-mc-1 (games)',
    ]);
});

it('leaves out servers that cannot be read', function () {
    $this->ssh->connectionFailure = new SshException('Connection refused');

    expect(app(GameStatuses::class)->containerOptions())->toBe([]);
});

describe('in the panel', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        $this->ssh->respondWithJson('inventory', gamesInventory(['enshrouded-enshrouded-1' => 'running', 'minecraft-mc-1' => 'exited']));
    });

    it('creates a page with servers and checks followed containers right away', function () {
        $undoRepeaterFake = Repeater::fake();

        livewire(CreateDetailsPage::class)
            ->fillForm([
                'name' => 'Redfield',
                'slug' => 'redfield-k3m9x2qa',
                'title' => '',
                'entries' => [
                    ['name' => 'Enshrouded', 'address' => 'play.example.com', 'password' => 'ears', 'status_source' => 'container', 'link' => "{$this->server->id}:enshrouded-enshrouded-1"],
                    ['name' => 'Minecraft', 'version' => '26.1.2', 'address' => 'play.example.com', 'status_source' => 'manual', 'status' => 'paused'],
                    ['name' => 'Valheim', 'address' => 'valheim.example.com', 'status_source' => 'none'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $page = DetailsPage::query()->where('slug', 'redfield-k3m9x2qa')->sole();
        [$enshrouded, $minecraft, $valheim] = $page->entries->all();

        expect($page->title)->toBeNull()
            ->and($enshrouded->only(['status_source', 'server_id', 'container']))->toBe(['status_source' => 'container', 'server_id' => $this->server->id, 'container' => 'enshrouded-enshrouded-1'])
            ->and($enshrouded->status)->toBe(GameStatus::Online)
            ->and($minecraft->only(['status_source', 'server_id', 'container']))->toBe(['status_source' => 'manual', 'server_id' => null, 'container' => null])
            ->and($minecraft->status)->toBe(GameStatus::Paused)
            ->and($valheim->only(['status_source', 'status']))->toBe(['status_source' => null, 'status' => null])
            ->and($minecraft->sort)->toBeGreaterThan($enshrouded->sort);
    });

    it('refuses an address that is taken or not slug-shaped', function () {
        livewire(CreateDetailsPage::class)
            ->fillForm(['name' => 'Other', 'slug' => 'friends-abc123xyz0'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        livewire(CreateDetailsPage::class)
            ->fillForm(['name' => 'Other', 'slug' => 'Not A Slug'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'regex']);
    });

    it('switches a followed container to a status set by hand', function () {
        $entry = followContainer($this->page, $this->server, 'enshrouded-enshrouded-1');
        $item = "entries.record-{$entry->id}";

        livewire(EditDetailsPage::class, ['record' => $this->page->getRouteKey()])
            ->assertFormSet(["{$item}.status_source" => 'container', "{$item}.link" => "{$this->server->id}:enshrouded-enshrouded-1"])
            ->fillForm(["{$item}.status_source" => 'manual', "{$item}.status" => 'online'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($entry->fresh()->only(['status_source', 'server_id', 'container']))->toBe(['status_source' => 'manual', 'server_id' => null, 'container' => null])
            ->and($entry->fresh()->status)->toBe(GameStatus::Online);
    });

    it('hides the status again', function () {
        $entry = addEntry($this->page, ['status_source' => DetailsPageEntry::SOURCE_MANUAL, 'status' => GameStatus::Online]);

        livewire(EditDetailsPage::class, ['record' => $this->page->getRouteKey()])
            ->fillForm(["entries.record-{$entry->id}.status_source" => 'none'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($entry->fresh()->only(['status_source', 'status']))->toBe(['status_source' => null, 'status' => null]);
    });

    it('lists the pages with their address and description', function () {
        // The description comes from a panel render hook, registered when the panel boots.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        livewire(ListDetailsPages::class)
            ->assertOk()
            ->assertSee('Friends')
            ->assertSee('/p/friends-abc123xyz0')
            ->assertSeeHtml('fi-page-description')
            ->assertSee('Public pages for players');
    });
});
