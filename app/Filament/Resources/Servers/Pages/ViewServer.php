<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\JsDriver;
use App\Enums\ServerSetupMode;
use App\Enums\TaskType;
use App\Filament\Clusters\Settings\Pages\Updates;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ProvisionServer;
use App\Mandal\CliArchive;
use App\Mandal\CliUpdates;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use App\Support\Terminal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * The server tab: connection details and the panel's key, then everything
 * on the server, read live through the mandal CLI.
 */
class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected static ?string $navigationLabel = 'Server';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected string $view = 'filament.servers.pages.server';

    /** @var array<string, mixed>|null */
    public ?array $inventory = null;

    public ?string $error = null;

    private ?string $cliState = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadInventory();
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function loadInventory(bool $fresh = false): void
    {
        $this->error = null;
        $this->cliState = null;

        /** @var Server $server */
        $server = $this->getRecord();

        if ($server->connected_at === null) {
            $this->inventory = null;
            $this->error = 'This server has not been connected yet. Use "Test connection" above first.';

            return;
        }

        try {
            $this->inventory = app(MandalClient::class)->inventory($server, $fresh);
        } catch (SshException $exception) {
            $this->inventory = null;
            $this->error = $exception->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        $section = $this->inventory[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    public function bytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return rtrim(rtrim(number_format($bytes, $unit === 'B' ? 0 : 1, '.', ''), '0'), '.').' '.$unit;
            }

            $bytes /= 1024;
        }

        return '0 B';
    }

    public function duration(?int $seconds): string
    {
        $seconds = (int) ($seconds ?? 0);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }

    /**
     * The heading line keeps only the CLI update link; the buttons sit on
     * their own line below it, rendered by the page view.
     *
     * @return array<Action>
     */
    public function getCachedHeaderActions(): array
    {
        return array_values(array_filter(parent::getCachedHeaderActions(), fn (Action $action): bool => $action->getName() === 'cliUpdateRequired'));
    }

    /**
     * @return array<Action>
     */
    public function getToolbarActions(): array
    {
        return array_values(array_filter(parent::getCachedHeaderActions(), fn (Action $action): bool => $action->getName() !== 'cliUpdateRequired'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->loadInventory(fresh: true)),
            $this->setupCommandAction(),
            $this->testConnectionAction(),
            $this->cliUpdateRequiredAction(),
            $this->provisionAction(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Queues `mandal provision` with the chosen drivers and opens the task
     * so its output can be followed.
     */
    private function provisionAction(): Action
    {
        return Action::make('provision')
            ->label(fn (Server $record): string => $record->isProvisioned() ? 'Provision again' : 'Provision')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color(fn (Server $record): string => $record->isProvisioned() ? 'gray' : 'primary')
            ->visible(fn (Server $record): bool => $record->connected_at !== null)
            ->disabled(fn (Server $record): bool => $record->hasActiveTask())
            ->modalHeading('Provision the server')
            ->modalDescription('Installs and hardens the stack. Existing configuration is kept; Mandal only adds what is missing. This runs in the background and usually takes 5 to 15 minutes.')
            ->modalSubmitActionLabel('Start provisioning')
            ->schema([
                Select::make('database')
                    ->label('Database')
                    ->options(DatabaseDriver::class)
                    ->default(DatabaseDriver::default())
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('cache')
                    ->label('Cache')
                    ->options(CacheDriver::class)
                    ->default(CacheDriver::default())
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('js')
                    ->label('JavaScript tooling')
                    ->options(JsDriver::class)
                    ->default(JsDriver::default())
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('php')
                    ->label('PHP version')
                    ->placeholder('Newest available')
                    ->helperText('Leave empty to install the newest version for which every required extension exists.')
                    ->rules(['nullable', 'regex:/^\d+\.\d+$/']),
                Checkbox::make('skip_ssh_hardening')
                    ->label('Leave SSH configuration untouched')
                    ->helperText('By default password logins and root logins over SSH are disabled once the panel\'s key is confirmed to work.'),
            ])
            ->action(function (Server $record, array $data): void {
                if ($record->hasActiveTask()) {
                    Notification::make()->title('Another task is still running')->warning()->send();

                    return;
                }

                $task = $record->tasks()->create([
                    'type' => TaskType::Provision,
                    'user_id' => auth()->id(),
                    'options' => [
                        'database' => $data['database'],
                        'cache' => $data['cache'],
                        'js' => $data['js'],
                        'php' => $data['php'] ?: null,
                        'skip_ssh_hardening' => (bool) ($data['skip_ssh_hardening'] ?? false),
                    ],
                ]);

                ProvisionServer::dispatch($task);

                Notification::make()
                    ->title('Provisioning started')
                    ->success()
                    ->send();

                $this->redirect(ServerResource::getUrl('task', ['record' => $record, 'task' => $task]));
            });
    }

    /**
     * Shows the one-line command to run on the server as root.
     */
    private function setupCommandAction(): Action
    {
        return Action::make('setupCommand')
            ->label('Setup command')
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->visible(fn (Server $record): bool => $record->connected_at === null && $record->setup_mode !== ServerSetupMode::Existing)
            ->modalHeading('Set up the server')
            ->modalContent(fn (Server $record): View => view('filament.servers.setup-command', ['server' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Loads the ntsync driver now and at boot (`mandal ntsync:enable`), for
     * Windows game servers run under Proton or Wine. Shown on the System card.
     */
    public function enableNtsyncAction(): Action
    {
        return Action::make('enableNtsync')
            ->label('Enable')
            ->icon(Heroicon::OutlinedBolt)
            ->size(Size::Small)
            ->color('gray')
            ->disabled(fn (): bool => $this->ntsyncEnabled())
            ->tooltip(fn (): ?string => $this->ntsyncEnabled() ? 'Already active' : null)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBolt)
            ->modalHeading('Enable ntsync')
            ->modalDescription('Loads the kernel\'s ntsync driver now and at every boot. Windows game servers run under Proton or Wine use it for their locks and events, which can cut their tick time. A container only uses it once its compose file passes the device: devices: [/dev/ntsync:/dev/ntsync].')
            ->modalSubmitActionLabel('Enable')
            ->action(function (MandalClient $mandal): void {
                /** @var Server $server */
                $server = $this->getRecord();

                try {
                    $result = $mandal->run($server, 'ntsync:enable', timeoutSeconds: 60);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not enable ntsync')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if ($result->failed()) {
                    Notification::make()->title('Could not enable ntsync')->body(Terminal::plain(trim($result->errorOutput ?: $result->output)))->danger()->send();

                    return;
                }

                Notification::make()->title('ntsync is active')->success()->send();
                $this->loadInventory(fresh: true);
            });
    }

    /**
     * Whether ntsync is loaded and set to load at boot, as the inventory saw it.
     */
    public function ntsyncEnabled(): bool
    {
        $ntsync = $this->section('system')['ntsync'] ?? [];

        return ($ntsync['active'] ?? false) && ($ntsync['persistent'] ?? false);
    }

    /**
     * A link to Settings > Updates when the CLI on this server is older
     * than the one this panel ships; nothing when it is current or the
     * server cannot be reached.
     */
    private function cliUpdateRequiredAction(): Action
    {
        return Action::make('cliUpdateRequired')
            ->label('Mandal CLI update required')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->link()
            ->visible(fn (Server $record): bool => $record->connected_at !== null && $this->cliUpdateState($record) === 'outdated')
            ->url(Updates::getUrl());
    }

    /**
     * Whether the server's CLI matches the panel's build: outdated, current
     * or unreachable. Reading the inventory (cached briefly) records the
     * build the server runs right now; the answer is kept for the request
     * since the link asks several times while rendering.
     */
    private function cliUpdateState(Server $record): string
    {
        return $this->cliState ??= (function () use ($record): string {
            try {
                app(MandalClient::class)->inventory($record);
            } catch (SshException) {
                return 'unreachable';
            }

            return $record->cliOutdated(app(CliArchive::class)->hash()) ? 'outdated' : 'current';
        })();
    }

    /**
     * Whether the CLI setup.sh installed from a release differs from the
     * build this panel ships. A server another panel manages keeps that
     * panel's build, and a server whose CLI does not answer cannot be
     * updated through it anyway.
     */
    private function cliDiffersFromPanel(Server $record, MandalClient $mandal, CliArchive $archive): bool
    {
        if ($record->setup_mode === ServerSetupMode::Existing || $record->hasActiveTask()) {
            return false;
        }

        try {
            return ($mandal->version($record)['build'] ?? null) !== $archive->hash();
        } catch (SshException) {
            return false;
        }
    }

    /**
     * Connects to the server. The first time, the host key fingerprint is
     * shown for confirmation before it is pinned.
     */
    private function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->requiresConfirmation(fn (Server $record): bool => ! $record->hasPinnedHostKey())
            // A custom heading alone makes Filament open the modal, so hide
            // it outright once the key is pinned.
            ->modalHidden(fn (Server $record): bool => $record->hasPinnedHostKey())
            ->modalHeading('Confirm the server\'s host key')
            ->modalDescription(function (Server $record, SshClient $ssh): string {
                try {
                    $fingerprint = KeyPair::fingerprint($ssh->fetchHostKey($record));
                } catch (SshException $exception) {
                    return "Could not read the host key: {$exception->getMessage()}";
                }

                return "The server at {$record->host} presented the host key {$fingerprint}. "
                    .'Check it against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, '
                    .'then confirm to pin it. Mandal will refuse to connect if it ever changes.';
            })
            ->modalSubmitActionLabel('Trust this host key')
            ->action(function (Server $record, SshClient $ssh, MandalClient $mandal, CliArchive $archive, CliUpdates $updates): void {
                try {
                    $connection = $ssh->connect($record, trustUnknownHostKey: true);
                    $result = $connection->run('echo mandal-ok', timeoutSeconds: 15);
                    $connection->disconnect();
                } catch (SshException $exception) {
                    Notification::make()
                        ->title('Connection failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                if (! str_contains($result->output, 'mandal-ok')) {
                    Notification::make()
                        ->title('Connected, but the shell did not respond as expected')
                        ->body(trim($result->errorOutput ?: $result->output))
                        ->warning()
                        ->send();

                    return;
                }

                $firstConnection = $record->connected_at === null;
                $record->forceFill(['connected_at' => now()])->save();
                $mandal->forgetInventory($record);

                $notification = Notification::make()->title("Connected to {$record->name}")->success();

                // setup.sh installs the CLI from a release; from here on this
                // panel's own build is the one the server should run.
                if ($firstConnection && $this->cliDiffersFromPanel($record, $mandal, $archive)) {
                    $updates->queue($record);
                    $notification->body("Installing this panel's build of the CLI (mandal {$archive->version()}). Follow it under Tasks.");
                }

                $notification->send();

                $this->refreshFormData(['connected_at', 'host_key']);
                $this->loadInventory();
            });
    }
}
