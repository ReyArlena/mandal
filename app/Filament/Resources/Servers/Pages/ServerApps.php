<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WwwMode;
use App\Filament\Resources\Servers\Concerns\ManagesApps;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\CreateApp;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The apps on a server, read from its manifests, with create and delete.
 */
class ServerApps extends Page
{
    use InteractsWithRecord;
    use ManagesApps;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $title = 'Apps';

    protected string $view = 'filament.servers.pages.apps';

    /** @var list<array<string, mixed>> */
    public array $apps = [];

    /** @var list<string> */
    public array $phpVersions = [];

    public ?string $defaultPhp = null;

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->loadApps();
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    protected function appChanged(): void
    {
        $this->loadApps(fresh: true);
    }

    public function loadApps(bool $fresh = false): void
    {
        $this->error = null;

        $server = $this->server();

        if (! $server->isProvisioned()) {
            $this->error = 'Provision this server before creating apps.';

            return;
        }

        try {
            $inventory = app(MandalClient::class)->inventory($server, $fresh);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->apps = array_values($inventory['apps'] ?? []);
        $this->phpVersions = array_values($inventory['php']['versions'] ?? []);
        $this->defaultPhp = $inventory['php']['default'] ?? null;
    }

    public function hasPendingTask(): bool
    {
        return $this->server()->tasks()
            ->whereIn('type', [TaskType::CreateApp, TaskType::DeleteApp, TaskType::Deploy, TaskType::Rollback, TaskType::Certificate])
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::Running])
            ->exists();
    }

    public function appUrl(string $name): string
    {
        return ServerResource::getUrl('app', ['record' => $this->server(), 'app' => $name]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->loadApps(fresh: true)),
            $this->createAppAction(),
        ];
    }

    private function createAppAction(): Action
    {
        return Action::make('createApp')
            ->label('New app')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Create a Laravel app')
            ->modalDescription('Creates the system user, PHP-FPM pool, nginx site, database and a production .env. Deploying the code comes next.')
            ->modalSubmitActionLabel('Create app')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->regex(self::APP_NAME_PATTERN)
                    ->notIn(array_column($this->apps, 'name'))
                    ->helperText('2 to 32 lower-case letters, digits or hyphens. Used for the system user, database and directories.')
                    ->placeholder('shop'),
                TextInput::make('domain')
                    ->required()
                    ->rule(fn (): string => 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/')
                    ->placeholder('shop.example.com')
                    ->helperText('Without www.'),
                Select::make('www')
                    ->label('www handling')
                    ->options(WwwMode::class)
                    ->default(WwwMode::default())
                    ->required()
                    ->selectablePlaceholder(false),
                TagsInput::make('aliases')
                    ->placeholder('Add another domain')
                    ->nestedRecursiveRules(['regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/']),
                Select::make('php')
                    ->label('PHP version')
                    ->options(array_combine($this->phpVersions, $this->phpVersions))
                    ->default($this->defaultPhp)
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('repo')
                    ->label('GitHub repository')
                    ->placeholder('owner/name')
                    ->regex('#^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$#'),
                TextInput::make('branch')
                    ->default('main')
                    ->regex('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#'),
                Checkbox::make('https')
                    ->label('Public repository: clone over HTTPS without a deploy key'),
                Checkbox::make('database')
                    ->label('Create a database and user')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $this->queueTask(TaskType::CreateApp, CreateApp::class, [
                    'name' => $data['name'],
                    'domain' => $data['domain'],
                    'www' => $data['www'],
                    'aliases' => array_values($data['aliases'] ?? []),
                    'php' => $data['php'],
                    'repo' => $data['repo'] ?: null,
                    'branch' => $data['branch'] ?: 'main',
                    'https' => (bool) ($data['https'] ?? false),
                    'database' => (bool) ($data['database'] ?? true),
                ], "Creating {$data['name']}");
            });
    }
}
