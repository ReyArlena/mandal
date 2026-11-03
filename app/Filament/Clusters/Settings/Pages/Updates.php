<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Concerns\HasSettingsHeading;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\CliArchive;
use App\Mandal\CliUpdates;
use App\Mandal\MandalClient;
use App\Mandal\PanelReleases;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The panel's release against the latest on GitHub, and the CLI build each
 * server runs against the one this panel ships. The panel is updated on the
 * server that hosts it; the CLI on any server is updated from here.
 */
class Updates extends Page
{
    use HasSettingsHeading;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'updates';

    protected string $view = 'filament.settings.updates';

    public static function getPageDescription(): string
    {
        return 'The panel itself is updated on the server that hosts it; the mandal CLI on each server is updated from here. Both are checked every hour.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->checkNowAction(),
            $this->updateAllAction(),
        ];
    }

    public function releases(): PanelReleases
    {
        return app(PanelReleases::class);
    }

    public function archive(): CliArchive
    {
        return app(CliArchive::class);
    }

    /**
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        return Server::query()->orderBy('name')->get();
    }

    /**
     * The connected servers whose recorded build differs from the panel's.
     *
     * @return Collection<int, Server>
     */
    public function outdatedServers(): Collection
    {
        return $this->servers()
            ->filter(fn (Server $server): bool => $this->isOutdated($server))
            ->values();
    }

    /**
     * What the server's last recorded build says, with a badge colour. No
     * server is contacted here: builds are recorded on every connection
     * and by the hourly check, and Check now reads them again.
     *
     * @return array{0: string, 1: string}
     */
    public function cliState(Server $server): array
    {
        return match (true) {
            $server->connected_at === null => ['Not connected', 'gray'],
            $server->cli_build === null => ['Unknown', 'gray'],
            $this->isOutdated($server) => ['Update required', 'warning'],
            default => ['Current', 'success'],
        };
    }

    public function updateCliAction(): Action
    {
        return Action::make('updateCli')
            ->label('Update')
            ->link()
            ->visible(fn (array $arguments): bool => ($server = $this->serverFrom($arguments)) !== null && $this->isOutdated($server))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Update the mandal CLI on '.($this->serverFrom($arguments)?->name ?? 'this server').'?')
            ->modalDescription(fn (): string => "Uploads and installs mandal {$this->archive()->version()} (build {$this->archive()->hash()}). Nothing else on the server changes.")
            ->action(function (array $arguments): void {
                $server = $this->serverFrom($arguments);

                if ($server === null || ! $this->isOutdated($server)) {
                    return;
                }

                if ($server->hasActiveTask()) {
                    Notification::make()
                        ->title('Another task is still running')
                        ->body("Wait for it to finish; only one task runs on {$server->name} at a time.")
                        ->warning()
                        ->send();

                    return;
                }

                $task = app(CliUpdates::class)->queue($server);

                Notification::make()->title('CLI update started')->body('Follow the progress on the task page.')->success()->send();

                $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
            });
    }

    /**
     * Reads every connected server's build and GitHub's latest release now,
     * as the hourly check does, and says what it found.
     */
    private function checkNowAction(): Action
    {
        return Action::make('checkNow')
            ->label('Check now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (CliUpdates $updates, MandalClient $mandal, PanelReleases $releases): void {
                $outdated = $updates->checkAll($mandal);
                $latest = $releases->check();

                $cli = match (count($outdated)) {
                    0 => 'Every reachable server runs the current CLI.',
                    1 => "{$outdated[0]->name} needs a CLI update.",
                    default => count($outdated).' servers need a CLI update.',
                };

                $panel = match (true) {
                    $latest === null => 'The latest release could not be read from GitHub.',
                    $releases->available() => "Mandal {$latest} is available.",
                    default => 'This panel runs the latest release.',
                };

                Notification::make()->title('Checked')->body("{$cli} {$panel}")->success()->send();
            });
    }

    /**
     * Queues the CLI update on every outdated server, except those with a
     * task already running. Offered once there is more than one.
     */
    private function updateAllAction(): Action
    {
        return Action::make('updateAll')
            ->label('Update all outdated')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('warning')
            ->visible(fn (): bool => $this->outdatedServers()->count() > 1)
            ->requiresConfirmation()
            ->modalHeading('Update the mandal CLI on every outdated server?')
            ->modalDescription(fn (): string => "Uploads and installs mandal {$this->archive()->version()} (build {$this->archive()->hash()}) on the {$this->outdatedServers()->count()} servers that run another build, one task per server. A server with a task still running is skipped. Nothing else on the servers changes.")
            ->modalSubmitActionLabel('Update all')
            ->action(function (CliUpdates $updates): void {
                $started = [];
                $skipped = [];

                foreach ($this->outdatedServers() as $server) {
                    if ($server->hasActiveTask()) {
                        $skipped[] = $server->name;

                        continue;
                    }

                    $updates->queue($server);
                    $started[] = $server->name;
                }

                $notification = Notification::make()
                    ->title(count($started) === 1 ? 'CLI update started on 1 server' : 'CLI updates started on '.count($started).' servers')
                    ->body('Follow the progress under each server\'s Tasks.'.($skipped === [] ? '' : ' Skipped because another task is running: '.implode(', ', $skipped).'.'));

                ($started === [] ? $notification->warning() : $notification->success())->send();
            });
    }

    private function isOutdated(Server $server): bool
    {
        return $server->connected_at !== null && $server->cliOutdated($this->archive()->hash());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function serverFrom(array $arguments): ?Server
    {
        $id = $arguments['server'] ?? null;

        return filled($id) ? Server::query()->find($id) : null;
    }
}
