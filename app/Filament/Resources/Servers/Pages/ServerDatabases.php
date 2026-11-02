<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskType;
use App\Filament\Resources\Servers\Concerns\FormatsBytes;
use App\Filament\Resources\Servers\Concerns\HasDatabaseSidebar;
use App\Filament\Resources\Servers\Concerns\InteractsWithDatabases;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ImportDatabase;
use App\Jobs\RunBackup;
use App\Jobs\RunMandalTask;
use App\Mandal\ComposeImport;
use App\Mandal\MandalClient;
use App\Models\Task;
use App\Ssh\CommandResult;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;

/**
 * The server's databases: create them, choose who can use each, import,
 * export and back them up, and delete what no app uses. Users and Backups
 * sit beside it in the sidebar.
 */
class ServerDatabases extends Page
{
    use FormatsBytes;
    use HasDatabaseSidebar, InteractsWithRecord {
        HasDatabaseSidebar::getSubNavigation insteadof InteractsWithRecord;
    }
    use InteractsWithDatabases;

    /** Database names, as the CLI accepts them. */
    public const string DATABASE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    /** Database user names, as the CLI accepts them. */
    public const string USER_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Passwords without quotes, backslashes or whitespace, as the CLI accepts them. */
    public const string PASSWORD_PATTERN = '/^[A-Za-z0-9!#%+,.:=?@^_~-]{12,128}$/';

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $title = 'Databases';

    protected string $view = 'filament.servers.pages.databases';

    public bool $backupsConfigured = false;

    public static function getPageDescription(): string
    {
        return 'Apps get a database when they are created. Each one lists the users that can use it.';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();

        if ($this->error === null) {
            try {
                $this->backupsConfigured = (bool) (app(MandalClient::class)->inventory($this->server())['backups']['configured'] ?? false);
            } catch (SshException) {
                $this->backupsConfigured = false;
            }
        }
    }

    /**
     * The database server and its version, such as "MariaDB 10.11.14".
     */
    public function engine(): string
    {
        $driver = (string) ($this->overview['driver'] ?? 'mariadb');

        return trim(match ($driver) {
            'mariadb' => 'MariaDB',
            'mysql' => 'MySQL',
            default => $driver,
        }.' '.($this->overview['version'] ?? ''));
    }

    /**
     * Local users with access to a database.
     *
     * @return list<string>
     */
    public function usersOf(string $database): array
    {
        return collect($this->localUsers())
            ->filter(fn (array $user): bool => in_array($database, $user['databases'] ?? [], true))
            ->pluck('user')
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
            $this->createDatabaseAction(),
        ];
    }

    private function createDatabaseAction(): Action
    {
        return Action::make('createDatabase')
            ->label('New database')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Create a database')
            ->modalDescription('UTF-8 (utf8mb4) with the unicode collation, like the databases Mandal creates for apps.')
            ->modalSubmitActionLabel('Create database')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->regex(self::DATABASE_PATTERN)
                    ->notIn(fn (): array => collect($this->databases())->pluck('name')->all())
                    ->helperText('Up to 64 lower-case letters, digits or underscores.'),
                CheckboxList::make('users')
                    ->label('Users with access')
                    ->options(fn (): array => $this->userOptions()),
            ])
            ->action(function (array $data): void {
                $arguments = 'db:create --name='.$data['name'].$this->repeat('--user', $data['users'] ?? [], self::USER_PATTERN);

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), $arguments, timeoutSeconds: 60),
                    "Database {$data['name']} created",
                    'Could not create the database',
                );
            });
    }

    public function accessAction(): Action
    {
        return Action::make('access')
            ->label('Access')
            ->icon(Heroicon::OutlinedKey)
            ->link()
            ->modalHeading(fn (array $arguments): string => "Who can use {$arguments['name']}")
            ->modalDescription('Checked users get all privileges on this database; unchecked users lose them. An app always keeps access to its own database.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (array $arguments): array => ['users' => $this->usersOf((string) $arguments['name'])])
            ->schema([
                CheckboxList::make('users')
                    ->label('Users')
                    ->options(fn (): array => $this->userOptions()),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), 'db:access --name='.$name.$this->repeat('--user', $data['users'] ?? [], self::USER_PATTERN), timeoutSeconds: 60),
                    "Access to {$name} saved",
                    'Could not change who can use the database',
                );
            });
    }

    /**
     * Where a dump of the database downloads from, as plain SQL or
     * zstd-compressed like the backups.
     */
    public function exportUrl(string $database, string $format): string
    {
        return route('filament.admin.servers.databases.export', ['server' => $this->server(), 'database' => $database, 'format' => $format]);
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->link()
            ->modalHeading(fn (array $arguments): string => "Import into {$arguments['name']}")
            ->modalDescription('A .sql dump, or one compressed with gzip (.sql.gz) or zstd (.sql.zst) like the backups and exports here. The import runs as the database administrator, so a dump that switches to or creates a database (USE, CREATE DATABASE) is refused.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                self::overwriteWarning(),
                FileUpload::make('file')
                    ->label('Dump')
                    ->required()
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->storeFileNamesIn('file_name')
                    ->maxSize(intdiv(ComposeImport::maxUploadBytes(), 1024))
                    ->rules(['extensions:sql,gz,zst'])
                    ->validationMessages(['extensions' => 'Choose a .sql, .sql.gz or .sql.zst file.'])
                    ->helperText(fn (): string => 'Max upload size to this panel: '.ComposeImport::human(ComposeImport::maxUploadBytes()).'.'),
                Checkbox::make('replace')
                    ->label('Empty the database first')
                    ->helperText('Drops every table before importing, so nothing from before is left. Without it the dump runs on top of the current tables; most dumps drop and recreate the tables they contain.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null || ! is_string($data['file'] ?? null)) {
                    return;
                }

                $this->queueTask(TaskType::ImportDatabase, ImportDatabase::class, [
                    'database' => $name,
                    'file' => $data['file'],
                    'file_name' => is_string($data['file_name'] ?? null) ? $data['file_name'] : basename($data['file']),
                    'replace' => (bool) ($data['replace'] ?? false),
                ], "Importing into {$name}");
            });
    }

    /**
     * One backup of a database now, to the destinations set on the Backups
     * tab and counted with the scheduled ones it keeps.
     */
    public function backupDatabaseAction(): Action
    {
        return Action::make('backupDatabase')
            ->label('Backup')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->link()
            ->disabled(fn (): bool => ! $this->backupsConfigured)
            ->tooltip(fn (): ?string => $this->backupsConfigured ? null : 'Set up backups on the Backups page first.')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArchiveBox)
            ->modalHeading(fn (array $arguments): string => "Back up {$arguments['name']} now?")
            ->modalDescription('One backup to the destinations set on the Backups page. It counts towards the number of backups kept there, so the oldest may be removed.')
            ->modalSubmitActionLabel('Back up')
            ->action(function (array $arguments): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->queueTask(TaskType::Backup, RunBackup::class, ['database' => $name], "Backing up {$name}");
            });
    }

    /**
     * Shown before an import or a restore replaces a database's data.
     */
    public static function overwriteWarning(): Callout
    {
        return Callout::make('Data in this database will be overwritten')
            ->description('Export or back up the database first if you want to keep it. Stop the app or put it in maintenance mode first, or it may show errors while this runs.')
            ->warning();
    }

    public function deleteDatabaseAction(): Action
    {
        return Action::make('deleteDatabase')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete database {$arguments['name']}?")
            ->modalDescription('Every table and row in it is removed, and users lose their access to it. Back it up first if you might need it again.')
            ->modalSubmitActionLabel('Delete database')
            ->action(function (array $arguments): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), 'db:delete --name='.$name, timeoutSeconds: 120),
                    "Database {$name} deleted",
                    'Could not delete the database',
                );
            });
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
