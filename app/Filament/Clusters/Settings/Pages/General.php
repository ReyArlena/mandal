<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\TaskType;
use App\Filament\Clusters\Settings\Concerns\HasSettingsHeading;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\RunBenchmark;
use App\Mandal\PanelReleases;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The panel's public address and version, and a benchmark of what the
 * panel spends on a server.
 */
class General extends Page
{
    use HasSettingsHeading;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'general';

    protected string $view = 'filament.settings.general';

    protected function getHeaderActions(): array
    {
        return [
            $this->publicUrlAction(),
            $this->benchmarkAction(),
        ];
    }

    public function publicUrl(): ?string
    {
        return Setting::publicUrl();
    }

    public function publicUrlAction(): Action
    {
        return Action::make('publicUrl')
            ->label('Public URL')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('gray')
            ->modalHeading('Public URL')
            ->modalDescription('The address other services reach this panel at. Needed for automatic deploys on push, which GitHub delivers to this URL. Leave empty when the panel only runs on your own machine.')
            ->fillForm(fn (): array => ['public_url' => Setting::publicUrl()])
            ->schema([
                TextInput::make('public_url')
                    ->label('URL')
                    ->url()
                    ->placeholder('https://panel.example.com')
                    ->rules(['nullable', 'url:https', 'max:200']),
            ])
            ->action(function (array $data): void {
                Setting::set(Setting::PUBLIC_URL, $data['public_url'] ? rtrim((string) $data['public_url'], '/') : null);
                Notification::make()->title('Public URL saved')->success()->send();
            });
    }

    /**
     * Queues a benchmark of the panel and the CLI on a chosen server (the
     * one hosting the panel by default) and opens the task to follow it.
     * The panel's own setup is captured here, as the web server runs it.
     */
    public function benchmarkAction(): Action
    {
        return Action::make('benchmark')
            ->label('Benchmark')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (): bool => $this->connectedServers()->exists())
            ->modalIcon(Heroicon::OutlinedClock)
            ->modalHeading('Benchmark the panel and CLI')
            ->modalDescription('Times the SSH connection, the inventory and every read command the panel runs on the chosen server, and lists the panel\'s own setup. Nothing is changed. It takes about a minute, and other tasks on that server wait until it finishes.')
            ->modalSubmitActionLabel('Run benchmark')
            ->schema([
                Select::make('server')
                    ->options(fn (): array => $this->connectedServers()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (PanelReleases $releases): ?int => $releases->hostingServer()?->getKey() ?? $this->connectedServers()->orderBy('name')->value('id'))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('The server that hosts the panel is the usual choice; its timings are what a slow panel feels like.'),
            ])
            ->action(function (array $data): void {
                /** @var Server|null $server */
                $server = $this->connectedServers()->find($data['server'] ?? null);

                if ($server === null) {
                    return;
                }

                if ($server->hasActiveTask()) {
                    Notification::make()->title('Another task is still running')->body("Wait for it to finish; only one task runs on {$server->name} at a time.")->warning()->send();

                    return;
                }

                /** @var Task $task */
                $task = $server->tasks()->create([
                    'type' => TaskType::Benchmark,
                    'user_id' => auth()->id(),
                    'options' => ['panel' => RunBenchmark::panelEnvironment()],
                ]);

                RunBenchmark::dispatch($task);

                Notification::make()->title('Benchmark started')->success()->send();

                $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
            });
    }

    /**
     * @return Builder<Server>
     */
    private function connectedServers(): Builder
    {
        return Server::query()->whereNotNull('connected_at');
    }
}
