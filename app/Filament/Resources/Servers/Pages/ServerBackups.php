<?php

namespace App\Filament\Resources\Servers\Pages;

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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
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
            ->modalHeading('Backup settings')
            ->modalDescription('Dumps every database on a schedule and keeps a limited number of copies locally and, optionally, in any S3-compatible bucket. Credentials are stored on the server only.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => [
                'schedule' => ($this->status['schedule'] ?? '') ?: '30 2 * * *',
                'keep_local' => $this->status['keep_local'] ?? 7,
                'keep_remote' => $this->status['keep_remote'] ?? 30,
                'remote' => (bool) ($this->status['remote']['enabled'] ?? false),
                's3_bucket' => $this->status['remote']['bucket'] ?? '',
                's3_path' => $this->status['remote']['path'] ?? '',
                's3_endpoint' => $this->status['remote']['endpoint'] ?? '',
            ])
            ->schema([
                TextInput::make('schedule')
                    ->label('Schedule (cron)')
                    ->required()
                    ->regex('/^([0-9*\/,-]+\s+){4}[0-9*\/,A-Za-z-]+$/')
                    ->helperText('Five cron fields, server time is UTC. Default 30 2 * * * runs every night at 02:30.'),
                TextInput::make('keep_local')->label('Local copies to keep')->numeric()->integer()->minValue(0)->maxValue(999)->required(),
                TextInput::make('keep_remote')->label('Remote copies to keep')->numeric()->integer()->minValue(0)->maxValue(999)->required(),
                Section::make('S3-compatible remote')
                    ->description('Works with AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others. Leave the key and secret empty to keep the ones already on the server.')
                    ->schema([
                        Checkbox::make('remote')->label('Upload backups to a bucket')->live(),
                        TextInput::make('s3_endpoint')->label('Endpoint URL')->url()->placeholder('https://s3.eu-central-1.amazonaws.com')->requiredIf('remote', true),
                        TextInput::make('s3_bucket')->label('Bucket')->regex('/^[a-z0-9][a-z0-9.-]{1,62}$/')->requiredIf('remote', true),
                        TextInput::make('s3_path')->label('Path prefix')->regex('#^[A-Za-z0-9._/-]{0,200}$#')->placeholder('server-1'),
                        TextInput::make('s3_region')->label('Region')->default('auto')->regex('/^[A-Za-z0-9-]{1,32}$/'),
                        TextInput::make('s3_key')->label('Access key')->password()->revealable()->autocomplete('off'),
                        TextInput::make('s3_secret')->label('Secret key')->password()->revealable()->autocomplete('off'),
                    ])
                    ->columns(2),
            ])
            ->action(function (array $data): void {
                $lines = [
                    'SCHEDULE='.$data['schedule'],
                    'KEEP_LOCAL='.(int) $data['keep_local'],
                    'KEEP_REMOTE='.(int) $data['keep_remote'],
                    'REMOTE='.(! empty($data['remote']) ? 'true' : 'false'),
                    'S3_BUCKET='.($data['s3_bucket'] ?? ''),
                    'S3_PATH='.($data['s3_path'] ?? ''),
                    'S3_ENDPOINT='.($data['s3_endpoint'] ?? ''),
                    'S3_REGION='.($data['s3_region'] ?: 'auto'),
                ];

                if (! empty($data['s3_key']) && ! empty($data['s3_secret'])) {
                    $lines[] = 'S3_KEY='.$data['s3_key'];
                    $lines[] = 'S3_SECRET='.$data['s3_secret'];
                }

                $mandal = app(MandalClient::class);

                try {
                    $result = $mandal->runWithUpload($this->server(), 'backup:configure', implode("\n", $lines)."\n", '.conf', timeoutSeconds: 120);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not save the backup settings')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if ($result->failed()) {
                    Notification::make()->title('Could not save the backup settings')->body(trim($result->errorOutput ?: $result->output))->danger()->persistent()->send();

                    return;
                }

                $mandal->forgetInventory($this->server());
                Notification::make()->title('Backup settings saved')->success()->send();
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
