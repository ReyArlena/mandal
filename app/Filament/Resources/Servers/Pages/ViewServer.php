<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\JsDriver;
use App\Enums\ServerSetupMode;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ProvisionServer;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Mandal\PanelReleases;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->setupCommandAction(),
            $this->testConnectionAction(),
            $this->provisionAction(),
            $this->grantAccessAction(),
            $this->updateCliAction(),
            $this->panelUpdateAction(),
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
     * Lets another Mandal panel manage this server by adding its public key.
     */
    private function grantAccessAction(): Action
    {
        return Action::make('grantAccess')
            ->label('Grant access')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Server $record): bool => $record->connected_at !== null)
            ->modalHeading('Allow another panel to manage this server')
            ->modalDescription('Paste the public key shown on the other panel\'s server page. It is added to the mandal user\'s authorized keys with the label, so it can be removed again later.')
            ->modalSubmitActionLabel('Allow')
            ->schema([
                TextInput::make('name')
                    ->label('Label')
                    ->required()
                    ->regex('/^[A-Za-z0-9 _.()-]{1,64}$/')
                    ->placeholder('Laptop panel'),
                Textarea::make('key')
                    ->label('Public key')
                    ->required()
                    ->rows(3)
                    ->regex('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+/=]+( .*)?$#'),
            ])
            ->action(function (Server $record, array $data, MandalClient $mandal): void {
                $key = implode(' ', array_slice(preg_split('/\s+/', trim((string) $data['key'])) ?: [], 0, 2));

                try {
                    $result = $mandal->run($record, 'key:add --name='.escapeshellarg((string) $data['name']).' --key='.escapeshellarg($key), timeoutSeconds: 30);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not add the key')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if ($result->failed()) {
                    Notification::make()->title('Could not add the key')->body(trim($result->errorOutput ?: $result->output))->danger()->send();

                    return;
                }

                Notification::make()->title('Access granted')->body(trim($result->output))->success()->send();
            });
    }

    /**
     * Offered when the CLI on the server differs from the one this panel ships.
     */
    private function updateCliAction(): Action
    {
        return Action::make('updateCli')
            ->label('Update CLI')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('warning')
            ->visible(function (Server $record, MandalClient $mandal, CliArchive $archive): bool {
                if ($record->connected_at === null) {
                    return false;
                }

                // Reading the inventory (cached briefly) records the build
                // the server runs right now.
                try {
                    $mandal->inventory($record);
                } catch (SshException) {
                    return false;
                }

                return $record->cliOutdated($archive->hash());
            })
            ->requiresConfirmation()
            ->modalHeading('Update the mandal CLI on this server')
            ->modalDescription(fn (CliArchive $archive): string => "Uploads and installs mandal {$archive->version()} (build {$archive->hash()}). Nothing else on the server changes.")
            ->action(function (Server $record): void {
                if ($record->hasActiveTask()) {
                    Notification::make()->title('Another task is still running')->warning()->send();

                    return;
                }

                $task = $this->queueCliUpdate($record);

                Notification::make()->title('CLI update started')->success()->send();

                $this->redirect(ServerResource::getUrl('task', ['record' => $record, 'task' => $task]));
            });
    }

    private function queueCliUpdate(Server $record): Task
    {
        $task = $record->tasks()->create([
            'type' => TaskType::UpdateCli,
            'user_id' => auth()->id(),
            'options' => [],
        ]);

        UpdateCli::dispatch($task);

        return $task;
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
     * On the server that hosts the panel, points at the newer release and
     * the command that installs it. The update runs on the server, not
     * from here: deploying the panel restarts the worker that would drive it.
     */
    private function panelUpdateAction(): Action
    {
        return Action::make('panelUpdate')
            ->label(fn (PanelReleases $releases): string => "Mandal {$releases->latest()} available")
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('info')
            ->visible(function (Server $record, MandalClient $mandal, PanelReleases $releases): bool {
                if ($record->connected_at === null || ! $releases->available()) {
                    return false;
                }

                try {
                    return (bool) ($mandal->inventory($record)['mandal']['hosts_panel'] ?? false);
                } catch (SshException) {
                    return false;
                }
            })
            ->modalHeading(fn (PanelReleases $releases): string => "Update Mandal to {$releases->latest()}")
            ->modalContent(fn (Server $record, PanelReleases $releases): View => view('filament.servers.panel-update', [
                'server' => $record,
                'latest' => $releases->latest(),
                'current' => $releases->current(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
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
            ->action(function (Server $record, SshClient $ssh, MandalClient $mandal, CliArchive $archive): void {
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
                    $this->queueCliUpdate($record);
                    $notification->body("Installing this panel's build of the CLI (mandal {$archive->version()}). Follow it under Tasks.");
                }

                $notification->send();

                $this->refreshFormData(['connected_at', 'host_key']);
            });
    }
}
