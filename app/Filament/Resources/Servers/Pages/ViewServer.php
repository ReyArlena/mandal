<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\JsDriver;
use App\Enums\ServerSetupMode;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ProvisionServer;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
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
            $this->updateCliAction(),
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
            ->disabled(fn (Server $record): bool => $record->tasks()->where('status', '!=', TaskStatus::Succeeded)->where('status', '!=', TaskStatus::Failed)->exists())
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
     * Issues a fresh setup token and shows the one-line command to run on
     * the server as root.
     */
    private function setupCommandAction(): Action
    {
        return Action::make('setupCommand')
            ->label('Setup command')
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->visible(fn (Server $record): bool => $record->connected_at === null && $record->setup_mode !== ServerSetupMode::Existing)
            ->modalHeading('Set up the server')
            ->modalContent(function (Server $record): View {
                $token = $record->issueSetupToken();
                $scriptUrl = route('setup.script', ['server' => $record, 'token' => $token]);

                return view('filament.servers.setup-command', [
                    'server' => $record,
                    'scriptUrl' => $scriptUrl,
                    'command' => "curl -fsSL {$scriptUrl} | bash",
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
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

                try {
                    $build = $mandal->inventory($record)['mandal']['build'] ?? null;
                } catch (SshException) {
                    return false;
                }

                return $build !== $archive->hash();
            })
            ->requiresConfirmation()
            ->modalHeading('Update the mandal CLI on this server')
            ->modalDescription(fn (CliArchive $archive): string => "Uploads and installs mandal {$archive->version()} (build {$archive->hash()}). Nothing else on the server changes.")
            ->action(function (Server $record): void {
                $task = $record->tasks()->create([
                    'type' => TaskType::UpdateCli,
                    'user_id' => auth()->id(),
                    'options' => [],
                ]);

                UpdateCli::dispatch($task);

                Notification::make()->title('CLI update started')->success()->send();

                $this->redirect(ServerResource::getUrl('task', ['record' => $record, 'task' => $task]));
            });
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
            ->action(function (Server $record, SshClient $ssh, MandalClient $mandal): void {
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

                $record->forceFill(['connected_at' => now()])->save();
                $record->clearSetupToken();
                $mandal->forgetInventory($record);

                Notification::make()
                    ->title("Connected to {$record->name}")
                    ->success()
                    ->send();

                $this->refreshFormData(['connected_at', 'host_key']);
            });
    }
}
