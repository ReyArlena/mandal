<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Backups\BackupTargetForm;
use App\Backups\RemoteSync;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Concerns\FormatsBytes;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\RestoreBackup;
use App\Jobs\RunBackup;
use App\Jobs\RunMandalTask;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Backup configuration, history and restores for one server.
 */
class ServerBackups extends Page
{
    use FormatsBytes;
    use InteractsWithRecord;

    private const DATABASE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $title = 'Backups';

    protected string $view = 'filament.servers.pages.backups';

    /** @var array<string, mixed> */
    public array $status = [];

    /** @var list<array<string, mixed>> */
    public array $backups = [];

    public ?string $error = null;

    /** @var list<string>|null */
    private ?array $databases = null;

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

    public function load(bool $fresh = false): void
    {
        $this->error = null;
        $server = $this->server();

        if (! $server->isProvisioned()) {
            $this->error = 'Provision this server before configuring backups.';

            return;
        }

        $mandal = app(MandalClient::class);

        try {
            $this->status = $mandal->inventory($server, $fresh)['backups'] ?? [];

            if ($this->status['configured'] ?? false) {
                $list = $mandal->run($server, 'backup:list', timeoutSeconds: 60);
                $this->backups = $list->successful() ? $list->json() : [];
            }
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load(fresh: true)),
            $this->configureAction(),
            $this->runAction(),
        ];
    }

    private function configureAction(): Action
    {
        return Action::make('configure')
            ->label(fn (): string => ($this->status['configured'] ?? false) ? 'Settings' : 'Set up backups')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Database backup settings')
            ->modalDescription('Every database on the server is dumped with mariadb-dump and compressed. Each destination has its own schedule and number of copies to keep.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => BackupTargetForm::fill($this->status))
            ->schema([
                BackupTargetForm::enabledToggle(),
                BackupTargetForm::localSection(),
                BackupTargetForm::remoteSection('db/<database>'),
            ])
            ->action(function (array $data, RemoteSync $sync): void {
                try {
                    $sync->configure($this->server(), 'backup:configure', BackupTargetForm::lines($data));
                } catch (SshException $exception) {
                    Notification::make()->title('Could not save the backup settings')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Backup settings saved')->success()->send();
                $this->load(fresh: true);
            });
    }

    public function deleteBackupAction(): Action
    {
        return Action::make('deleteBackup')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete {$arguments['file']}?")
            ->modalDescription(fn (array $arguments): string => 'The '.($arguments['where'] ?? 'local').' copy is removed. This cannot be undone.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (array $arguments): void {
                $database = (string) ($arguments['database'] ?? '');
                $file = (string) ($arguments['file'] ?? '');
                $where = (string) ($arguments['where'] ?? '');

                if (! preg_match(self::DATABASE_PATTERN, $database) || ! preg_match('/^[a-z0-9_]+-\d{14}\.sql\.zst$/', $file) || ! in_array($where, ['local', 'remote'], true)) {
                    return;
                }

                $mandal = app(MandalClient::class);

                try {
                    $result = $mandal->run($this->server(), "backup:delete --database={$database} --file={$file} --where={$where}", timeoutSeconds: 120);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not delete the backup')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if ($result->failed()) {
                    Notification::make()->title('Could not delete the backup')->body(trim($result->errorOutput ?: $result->output))->danger()->send();

                    return;
                }

                Notification::make()->title("Deleted {$file}")->success()->send();
                $this->load(fresh: true);
            });
    }

    private function runAction(): Action
    {
        return Action::make('runBackup')
            ->label('Back up now')
            ->icon(Heroicon::OutlinedPlay)
            ->visible(fn (): bool => $this->error === null && ($this->status['configured'] ?? false))
            ->requiresConfirmation()
            ->modalHeading('Back up every database now?')
            ->action(fn () => $this->queueTask(TaskType::Backup, RunBackup::class, [], 'Backup started'));
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->link()
            ->modalHeading(fn (array $arguments): string => "Restore {$arguments['file']}")
            ->modalDescription('Left empty, the backup is loaded into a new database next to the live one, so you can check it before switching the app over.')
            ->modalSubmitActionLabel('Restore')
            ->schema([
                ServerDatabases::overwriteWarning()
                    ->visible(fn (Get $get): bool => in_array($get('into'), $this->databases(), true)),
                Select::make('into')
                    ->label('Restore into database')
                    ->placeholder('Leave empty for <database>_restore_<timestamp>')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->options(fn (): array => $this->databaseOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->databaseOptions($search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value)
                    ->rule('regex:'.self::DATABASE_PATTERN)
                    ->helperText(fn (?string $state): string => $state !== null && in_array($state, $this->databases(), true)
                        ? "{$state} is emptied and replaced by this backup; its users keep their access."
                        : 'Choose a database to replace, or type a new name. A new database has no users; give one access on the Databases page.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $database = (string) ($arguments['database'] ?? '');
                $file = (string) ($arguments['file'] ?? '');

                if (! preg_match(self::DATABASE_PATTERN, $database) || ! preg_match('/^[a-z0-9_]+-\d{14}\.sql\.zst$/', $file)) {
                    return;
                }

                $this->queueTask(TaskType::Restore, RestoreBackup::class, [
                    'database' => $database,
                    'file' => $file,
                    'into' => $data['into'] ?: null,
                ], "Restoring {$file}");
            });
    }

    /**
     * A server timestamp as "3 hours ago" with the exact time for a tooltip,
     * or null when there is none.
     *
     * @return array{ago: string, exact: string}|null
     */
    public function moment(?string $at): ?array
    {
        if (blank($at)) {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($at)->setTimezone(config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }

        return ['ago' => $time->diffForHumans(), 'exact' => $time->format('j M Y, H:i T')];
    }

    /**
     * What the last run did: "shop, 2 KB" for a backup, the log text as it is
     * for anything else.
     */
    public function lastRunDetail(string $rest): string
    {
        if (preg_match('/^(\S+) \S+ (\d+)$/', $rest, $match)) {
            return "{$match[1]}, {$this->bytes((int) $match[2])}";
        }

        return $rest;
    }

    /**
     * Databases on the server matching the search; a search that is itself
     * a valid name and not one of them comes first, as a new database.
     *
     * @return array<string, string>
     */
    public function databaseOptions(string $search = ''): array
    {
        $search = trim($search);
        $databases = $this->databases();

        if ($search !== '') {
            $databases = array_values(array_filter($databases, fn (string $name): bool => Str::contains($name, $search, ignoreCase: true)));

            if (preg_match(self::DATABASE_PATTERN, $search) && ! in_array($search, $databases, true)) {
                return [$search => "{$search} (new database)"] + array_combine($databases, $databases);
            }
        }

        return array_combine($databases, $databases);
    }

    /**
     * The server's databases, read once per request; empty when they cannot
     * be read, so a name can still be typed.
     *
     * @return list<string>
     */
    private function databases(): array
    {
        if ($this->databases !== null) {
            return $this->databases;
        }

        try {
            $result = app(MandalClient::class)->run($this->server(), 'db:list', timeoutSeconds: 60);
            $names = $result->successful() ? array_column($result->json()['databases'] ?? [], 'name') : [];
        } catch (SshException) {
            $names = [];
        }

        return $this->databases = array_values(array_filter($names, 'is_string'));
    }

    /**
     * @param  class-string<RunMandalTask>  $job
     * @param  array<string, mixed>  $options
     */
    private function queueTask(TaskType $type, string $job, array $options, string $title): void
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
            'type' => $type,
            'user_id' => auth()->id(),
            'options' => $options,
        ]);

        $job::dispatch($task);

        Notification::make()->title($title)->body('Follow the progress on the task page.')->success()->send();

        $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
    }
}
