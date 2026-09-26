<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WwwMode;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\CreateApp;
use App\Jobs\DeleteApp;
use App\Jobs\DeployApp;
use App\Jobs\RollbackApp;
use App\Jobs\RunMandalTask;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The apps on a server, read from its manifests, with create and delete.
 */
class ServerApps extends Page
{
    use InteractsWithRecord;

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

    public function loadApps(bool $fresh = false): void
    {
        $this->error = null;

        /** @var Server $server */
        $server = $this->getRecord();

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
        /** @var Server $server */
        $server = $this->getRecord();

        return $server->tasks()
            ->whereIn('type', [TaskType::CreateApp, TaskType::DeleteApp, TaskType::Deploy, TaskType::Rollback])
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::Running])
            ->exists();
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
                    ->regex('/^[a-z][a-z0-9-]{1,31}$/')
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
                    ->regex('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'),
                TextInput::make('branch')
                    ->default('main')
                    ->regex('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#'),
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
                    'database' => (bool) ($data['database'] ?? true),
                ], "Creating {$data['name']}");
            });
    }

    public function deployAppAction(): Action
    {
        return Action::make('deployApp')
            ->label('Deploy')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Deploy {$arguments['name']}?")
            ->modalDescription('Clones the configured branch into a new release, runs the app\'s deploy script and switches over when it succeeds. The live release is untouched if anything fails.')
            ->modalSubmitActionLabel('Deploy')
            ->action(function (array $arguments): void {
                $name = (string) ($arguments['name'] ?? '');

                if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
                    return;
                }

                $this->queueTask(TaskType::Deploy, DeployApp::class, ['name' => $name], "Deploying {$name}");
            });
    }

    public function rollbackAppAction(): Action
    {
        return Action::make('rollbackApp')
            ->label('Roll back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Roll back {$arguments['name']}?")
            ->modalDescription('Switches to the previous release and restarts the workers. Database migrations are not reversed.')
            ->modalSubmitActionLabel('Roll back')
            ->action(function (array $arguments): void {
                $name = (string) ($arguments['name'] ?? '');

                if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
                    return;
                }

                $this->queueTask(TaskType::Rollback, RollbackApp::class, ['name' => $name], "Rolling back {$name}");
            });
    }

    public function deleteAppAction(): Action
    {
        return Action::make('deleteApp')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete {$arguments['name']}?")
            ->modalDescription('Removes the site, pool, worker programs, crontab and system user. Choose what to keep below.')
            ->schema([
                Checkbox::make('keep_database')->label('Keep the database and its user'),
                Checkbox::make('keep_files')->label('Keep the home directory and files'),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = (string) ($arguments['name'] ?? '');

                if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name)) {
                    return;
                }

                $this->queueTask(TaskType::DeleteApp, DeleteApp::class, [
                    'name' => $name,
                    'keep_database' => (bool) ($data['keep_database'] ?? false),
                    'keep_files' => (bool) ($data['keep_files'] ?? false),
                ], "Deleting {$name}");
            });
    }

    /**
     * @param  class-string<RunMandalTask>  $job
     * @param  array<string, mixed>  $options
     */
    private function queueTask(TaskType $type, string $job, array $options, string $title): void
    {
        /** @var Server $server */
        $server = $this->getRecord();

        /** @var Task $task */
        $task = $server->tasks()->create([
            'type' => $type,
            'user_id' => auth()->id(),
            'options' => $options,
        ]);

        $job::dispatch($task);

        Notification::make()->title($title)->body('Follow the progress on the task page.')->success()->send();

        $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
    }
}
