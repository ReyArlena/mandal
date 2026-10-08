<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ApplyUpdates;
use App\Jobs\UpdateCli;
use App\Mandal\CliArchive;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Pending updates, component versions and reboot state for one server.
 */
class ServerUpdates extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownCircle;

    protected static ?string $title = 'Updates';

    protected string $view = 'filament.servers.pages.updates';

    /** @var array<string, mixed> */
    public array $status = [];

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    public function load(): void
    {
        $this->error = null;
        $server = $this->server();

        if (! $server->isProvisioned()) {
            $this->error = 'Provision this server first.';

            return;
        }

        try {
            $result = app(MandalClient::class)->run($server, 'updates:status', timeoutSeconds: 120);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        if ($result->failed()) {
            $this->error = trim($result->errorOutput ?: $result->output);

            return;
        }

        $this->status = $result->json();
    }

    public function hostsPanel(): bool
    {
        try {
            return (bool) (app(MandalClient::class)->inventory($this->server())['mandal']['hosts_panel'] ?? false);
        } catch (SshException) {
            return false;
        }
    }

    public function cliOutdated(): bool
    {
        $installed = (string) (collect($this->status['components'] ?? [])->firstWhere('id', 'mandal')['installed'] ?? '');

        try {
            $build = app(MandalClient::class)->inventory($this->server())['mandal']['build'] ?? null;
        } catch (SshException) {
            return false;
        }

        return $installed !== '' && $build !== app(CliArchive::class)->hash();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load()),
            Action::make('installSecurity')
                ->label('Install security updates')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn (): bool => $this->error === null && count($this->status['pending']['security'] ?? []) > 0)
                ->requiresConfirmation()
                ->modalDescription('Installs the pending security updates. Existing configuration files are kept.')
                ->action(fn () => $this->queue(['mode' => 'security'], 'Installing security updates')),
            Action::make('installAll')
                ->label('Install all updates')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->error === null && (count($this->status['pending']['security'] ?? []) + count($this->status['pending']['other'] ?? [])) > 0)
                ->requiresConfirmation()
                ->modalDescription('Installs every pending package update and removes packages that are no longer needed. Existing configuration files are kept.')
                ->action(fn () => $this->queue(['mode' => 'all'], 'Installing all updates')),
            Action::make('reboot')
                ->label('Reboot')
                ->icon(Heroicon::OutlinedPower)
                ->color('danger')
                ->visible(fn (): bool => $this->error === null && ($this->status['reboot_required'] ?? false))
                ->requiresConfirmation()
                ->modalHeading('Reboot the server?')
                ->modalDescription(fn (): string => $this->hostsPanel()
                    ? 'This server hosts the panel, so the panel itself goes offline for a minute or so along with every app. Queue workers are restarted by Supervisor afterwards.'
                    : 'Every app on this server will be unavailable for a minute or so. Queue workers are restarted by Supervisor afterwards.')
                ->modalSubmitActionLabel('Reboot now')
                ->action(function (): void {
                    try {
                        $result = app(MandalClient::class)->run($this->server(), 'reboot', timeoutSeconds: 30);
                    } catch (SshException $exception) {
                        Notification::make()->title('Could not reboot')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    if ($result->failed()) {
                        Notification::make()->title('Could not reboot')->body(trim($result->errorOutput ?: $result->output))->danger()->send();

                        return;
                    }

                    app(MandalClient::class)->forgetInventory($this->server());
                    Notification::make()->title('Rebooting')->body('The server restarts in a few seconds.')->warning()->send();
                }),
        ];
    }

    public function updatePackageAction(): Action
    {
        return Action::make('updatePackage')
            ->label('Update')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Update {$arguments['package']}?")
            ->action(function (array $arguments): void {
                $package = (string) ($arguments['package'] ?? '');

                if (! preg_match('/^[a-z0-9][a-z0-9+.-]{0,99}$/', $package)) {
                    return;
                }

                $this->queue(['mode' => 'package', 'package' => $package], "Updating {$package}");
            });
    }

    public function updateComponentAction(): Action
    {
        return Action::make('updateComponent')
            ->label('Update')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Update {$arguments['name']}?")
            ->modalDescription(fn (array $arguments): string => ($arguments['note'] ?? '') ?: 'Downloads and installs the latest release.')
            ->action(function (array $arguments): void {
                $component = (string) ($arguments['id'] ?? '');

                if ($component === 'mandal') {
                    if ($this->server()->hasActiveTask()) {
                        Notification::make()->title('Another task is still running')->warning()->send();

                        return;
                    }

                    $task = $this->server()->tasks()->create(['type' => TaskType::UpdateCli, 'user_id' => auth()->id(), 'options' => []]);
                    UpdateCli::dispatch($task);
                    Notification::make()->title('CLI update started')->success()->send();
                    $this->redirect(ServerResource::getUrl('task', ['record' => $this->server(), 'task' => $task]));

                    return;
                }

                if (! in_array($component, ['cache', 'js', 'composer', 'docker'], true)) {
                    return;
                }

                $this->queue(['mode' => 'component', 'component' => $component], "Updating {$arguments['name']}");
            });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function queue(array $options, string $title): void
    {
        $server = $this->server();

        if ($server->hasActiveTask()) {
            Notification::make()
                ->title('Another task is still running')
                ->body('Wait for it to finish; only one task runs on a server at a time.')
                ->warning()
                ->send();

            return;
        }

        /** @var Task $task */
        $task = $server->tasks()->create([
            'type' => TaskType::Updates,
            'user_id' => auth()->id(),
            'options' => $options,
        ]);

        ApplyUpdates::dispatch($task);

        Notification::make()->title($title)->body('Follow the progress on the task page.')->success()->send();

        $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
    }

    public function duration(?int $seconds): string
    {
        $seconds = (int) ($seconds ?? 0);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h ".intdiv($seconds % 3600, 60).'m';
    }
}
