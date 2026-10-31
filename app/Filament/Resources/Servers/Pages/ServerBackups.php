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
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Backup configuration, history and restores for one server.
 */
class ServerBackups extends Page
{
    use FormatsBytes;
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $title = 'Backups';

    protected string $view = 'filament.servers.pages.backups';

    /** @var array<string, mixed> */
    public array $status = [];

    /** @var list<array<string, mixed>> */
    public array $backups = [];

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

                if (! preg_match('/^[a-z0-9_]{1,64}$/', $database) || ! preg_match('/^[a-z0-9_]+-\d{14}\.sql\.zst$/', $file) || ! in_array($where, ['local', 'remote'], true)) {
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
            ->modalDescription('The backup is loaded into a new database next to the live one, so you can check it before switching the app over. Nothing is overwritten.')
            ->modalSubmitActionLabel('Restore')
            ->schema([
                TextInput::make('into')
                    ->label('Restore into database')
                    ->placeholder('Leave empty for <database>_restore_<timestamp>')
                    ->regex('/^[a-z0-9_]{1,64}$/')
                    ->helperText('A new database is created; the app user is given access to it.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $database = (string) ($arguments['database'] ?? '');
                $file = (string) ($arguments['file'] ?? '');

                if (! preg_match('/^[a-z0-9_]{1,64}$/', $database) || ! preg_match('/^[a-z0-9_]+-\d{14}\.sql\.zst$/', $file)) {
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
