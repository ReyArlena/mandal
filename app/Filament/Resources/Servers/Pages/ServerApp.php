<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Backups\BackupTargetForm;
use App\Backups\RemoteSync;
use App\Enums\TaskType;
use App\Filament\Resources\Servers\Concerns\FormatsBytes;
use App\Filament\Resources\Servers\Concerns\ManagesApps;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\BackupApp;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Webhook;
use App\Sources\AutoDeploy;
use App\Ssh\SshException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One app on a server: details, releases, workers and backups, with every
 * action.
 */
class ServerApp extends Page
{
    use FormatsBytes;
    use InteractsWithRecord;
    use ManagesApps;

    public const string BACKUP_FILE_PATTERN = '/^[a-z][a-z0-9-]{1,31}-[0-9]{14}\.tar\.zst$/';

    public const string NOT_DEPLOYED_NOTICE = 'Set up backups once the app has been deployed and is running, so every production file is present.';

    protected static string $resource = ServerResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.servers.pages.app';

    #[Locked]
    public string $appName = '';

    /** @var array<string, mixed> */
    public array $appData = [];

    /** @var list<array<string, mixed>> */
    public array $releases = [];

    /** @var list<array<string, mixed>> */
    public array $workers = [];

    /**
     * What `app:backup-list` reports: config, configured, last_run, backups.
     *
     * @var array<string, mixed>
     */
    public array $backups = [];

    /**
     * The two-level tree from `app:backup-tree`, read when the backup
     * settings open, kept so the checkbox options survive the modal's
     * round trips.
     *
     * @var list<array<string, mixed>>
     */
    public array $backupTree = [];

    public ?string $error = null;

    public function webhook(): ?Webhook
    {
        return app(AutoDeploy::class)->webhook($this->server(), $this->appName);
    }

    public function mount(int|string $record, string $app): void
    {
        $this->record = $this->resolveRecord($record);

        if (! preg_match(self::APP_NAME_PATTERN, $app)) {
            throw new NotFoundHttpException;
        }

        $this->appName = $app;

        $this->loadApp();

        if ($this->appData === [] && $this->error === null) {
            throw new NotFoundHttpException;
        }
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    protected function appChanged(): void
    {
        $this->loadApp(fresh: true);
    }

    public function loadApp(bool $fresh = false): void
    {
        $this->error = null;
        $mandal = app(MandalClient::class);
        $server = $this->server();

        try {
            $apps = $mandal->inventory($server, $fresh)['apps'] ?? [];
            $this->appData = collect($apps)->firstWhere('name', $this->appName) ?? [];

            if ($this->appData === []) {
                return;
            }

            $backups = $mandal->run($server, 'app:backup-list --name='.$this->appName, timeoutSeconds: 60);
            $this->backups = $backups->successful() ? $backups->json() : [];

            if ($this->isDocker()) {
                $this->releases = [];
                $this->workers = [];

                return;
            }

            $releases = $mandal->run($server, 'app:releases --name='.$this->appName, timeoutSeconds: 30);
            $this->releases = $releases->successful() ? array_reverse($releases->json()) : [];

            $workers = $mandal->run($server, 'worker:list --app='.$this->appName, timeoutSeconds: 30);
            $this->workers = $workers->successful() ? $workers->json() : [];
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    /**
     * A backup only has every production file once a Laravel app has a
     * release and a Docker app's containers have run.
     */
    public function isDeployed(): bool
    {
        return $this->isDocker() ? $this->isRunning() : ! empty($this->appData['current_release']);
    }

    public function backupsConfigured(): bool
    {
        return (bool) ($this->backups['configured'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function backupConfig(): array
    {
        return is_array($this->backups['config'] ?? null) ? $this->backups['config'] : [];
    }

    public function backupSummary(): string
    {
        return $this->backupsConfigured() ? BackupTargetForm::summary($this->backupConfig()) : 'Not set up';
    }

    /**
     * The size from the log line `ok app <app> <file> <size>`, if any.
     */
    public function lastBackupSize(): ?string
    {
        $rest = (string) ($this->backups['last_run']['rest'] ?? '');
        $size = $rest === '' ? '' : (string) last(explode(' ', trim($rest)));

        return ctype_digit($size) ? $this->bytes((int) $size) : null;
    }

    public function backupDownloadUrl(string $file): string
    {
        return route('filament.admin.servers.apps.backups.download', [
            'server' => $this->server(),
            'app' => $this->appName,
            'file' => $file,
        ]);
    }

    public function isDocker(): bool
    {
        return ($this->appData['type'] ?? 'laravel') === 'docker';
    }

    /**
     * Whether any of a Docker app's containers is running.
     */
    public function isRunning(): bool
    {
        return collect($this->appData['containers'] ?? [])->contains(fn (array $container): bool => ($container['state'] ?? '') === 'running');
    }

    /**
     * A Docker app without the nginx proxy has no domain, so nothing to
     * request a certificate for.
     */
    public function hasDomain(): bool
    {
        return ! $this->isDocker() || ! empty($this->appData['proxy']);
    }

    public function getTitle(): string
    {
        return $this->appName;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->startAppAction()->arguments(['name' => $this->appName])->visible(fn (): bool => $this->isDocker() && ! $this->isRunning()),
            $this->stopAppAction()->arguments(['name' => $this->appName])->visible(fn (): bool => $this->isDocker() && $this->isRunning()),
            $this->restartAppAction()->arguments(['name' => $this->appName])->visible(fn (): bool => $this->isDocker() && $this->isRunning()),
            $this->deployAppAction()
                ->arguments(['name' => $this->appName, 'type' => $this->isDocker() ? 'docker' : 'laravel'])
                ->label(fn (): string => $this->isDocker() ? 'Pull and restart' : 'Deploy')
                ->visible(fn (): bool => $this->isDocker() || ! empty($this->appData['repo'])),
            $this->enableAutoDeployAction()
                ->arguments(['name' => $this->appName, 'repo' => (string) ($this->appData['repo'] ?? '')])
                ->visible(fn (): bool => ! empty($this->appData['repo']) && $this->webhook() === null),
            $this->disableAutoDeployAction()
                ->arguments(['name' => $this->appName])
                ->visible(fn (): bool => $this->webhook() !== null),
            $this->requestCertificateAction()->arguments(['name' => $this->appName])->visible(fn (): bool => empty($this->appData['ssl']) && $this->hasDomain()),
            Action::make('logs')
                ->label('Logs')
                ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                ->color('gray')
                ->url(fn (): string => ServerApps::logsUrlFor($this->server(), ['name' => $this->appName, 'type' => $this->appData['type'] ?? 'laravel'])),
            $this->editComposeAction()->arguments(['name' => $this->appName])->visible(fn (): bool => $this->isDocker()),
            $this->changeProxyPortAction()->arguments(['name' => $this->appName, 'port' => (string) ($this->appData['port'] ?? '')])->visible(fn (): bool => $this->isDocker() && ! empty($this->appData['proxy'])),
            $this->editEnvAction()->arguments(['name' => $this->appName]),
            $this->editDeployScriptAction()->arguments(['name' => $this->appName])->visible(fn (): bool => ! $this->isDocker()),
            $this->deleteAppAction()->arguments(['name' => $this->appName])->visible(fn (): bool => empty($this->appData['panel'])),
        ];
    }

    public function backupSettingsAction(): Action
    {
        return Action::make('backupSettings')
            ->label('Backup settings')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('gray')
            ->modalHeading(fn (array $arguments): string => "Backup settings for {$arguments['name']}")
            ->modalDescription(fn (): string => $this->isDocker()
                ? 'Archives the project directory with tar and zstd. Each destination has its own schedule and number of copies to keep.'
                : 'Archives shared/ (storage, .env and uploads) with tar and zstd; releases are rebuilt from git. Each destination has its own schedule and number of copies to keep.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return [];
                }

                $this->loadBackupTree($name);

                $config = $this->backupConfig();
                $exclude = array_values(array_filter(is_array($config['exclude'] ?? null) ? $config['exclude'] : [], 'is_string'));

                return [
                    ...BackupTargetForm::fill($config),
                    'exclude' => implode("\n", $exclude),
                    'exclude_tree' => array_values(array_intersect($this->treePaths(), $exclude)),
                    'pause' => (bool) ($config['pause'] ?? $this->isDocker()),
                ];
            })
            ->schema(fn (): array => [
                Callout::make(self::NOT_DEPLOYED_NOTICE)->warning()->visible(fn (): bool => ! $this->isDeployed()),
                BackupTargetForm::enabledToggle(),
                Tabs::make('exclude_tabs')
                    ->tabs([
                        Tab::make('Exclude')
                            ->schema([
                                Text::make('For speed only two levels are shown. Add deeper paths or patterns on the Patterns tab.')
                                    ->color('gray'),
                                CheckboxList::make('exclude_tree')
                                    ->hiddenLabel()
                                    ->options(fn (): array => $this->treeOptions())
                                    ->allowHtml()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?array $state): void {
                                        $this->syncPatternsFromTree($set, $get, $state ?? []);
                                    }),
                            ]),
                        Tab::make('Patterns')
                            ->schema([
                                Textarea::make('exclude')
                                    ->hiddenLabel()
                                    ->rows(8)
                                    ->placeholder("valheim-data/steamcmd\n**/backups/*.zip")
                                    ->helperText('One path or glob per line, relative to the app directory. A directory name excludes that directory; ** matches across folders.')
                                    ->rules([fn (): Closure => $this->excludeLinesRule()])
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $set('exclude_tree', array_values(array_intersect($this->treePaths(), self::excludeLines($state))));
                                    }),
                            ]),
                    ]),
                Toggle::make('pause')
                    ->label('Pause containers while archiving')
                    ->helperText('Keeps files consistent; the app is unreachable for the duration.')
                    ->visible(fn (): bool => $this->isDocker()),
                BackupTargetForm::localSection(),
                BackupTargetForm::remoteSection($this->appName),
            ])
            ->action(function (array $arguments, array $data, RemoteSync $sync): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $lines = [
                    ...BackupTargetForm::lines($data),
                    'PAUSE='.($this->isDocker() && ! empty($data['pause']) ? 'true' : 'false'),
                    ...array_map(fn (string $line): string => 'EXCLUDE='.$line, self::excludeLines($data['exclude'] ?? null)),
                ];

                try {
                    $sync->configure($this->server(), 'app:backup-configure --name='.$name, $lines);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not save the backup settings')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Backup settings saved')->success()->send();
                $this->appChanged();
            });
    }

    public function backupNowAction(): Action
    {
        return Action::make('backupNow')
            ->label('Back up now')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Back up {$arguments['name']} now?")
            ->modalDescription('Runs the backup to every destination it has, whether or not the schedule is enabled.')
            ->modalSubmitActionLabel('Back up')
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);

                if ($name === null) {
                    return;
                }

                $this->queueTask(TaskType::Backup, BackupApp::class, ['name' => $name], "Backing up {$name}");
            });
    }

    public function deleteAppBackupAction(): Action
    {
        return Action::make('deleteAppBackup')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete {$arguments['file']}?")
            ->modalDescription(fn (array $arguments): string => 'The '.($arguments['where'] ?? 'local').' copy is removed. This cannot be undone.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (array $arguments): void {
                $name = $this->appName($arguments);
                $file = (string) ($arguments['file'] ?? '');
                $where = (string) ($arguments['where'] ?? '');

                if ($name === null || ! preg_match(self::BACKUP_FILE_PATTERN, $file) || ! in_array($where, ['local', 'remote'], true)) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal) => $mandal->run($this->server(), "app:backup-delete --name={$name} --file={$file} --where={$where}", timeoutSeconds: 120),
                    "Deleted {$file}",
                    'Could not delete the backup',
                );
            });
    }

    private function loadBackupTree(string $name): void
    {
        $this->backupTree = [];

        try {
            $tree = app(MandalClient::class)->run($this->server(), 'app:backup-tree --name='.$name, timeoutSeconds: 120);
        } catch (SshException $exception) {
            Notification::make()->title('Could not read the app directory')->body($exception->getMessage())->warning()->send();

            return;
        }

        if ($tree->failed()) {
            Notification::make()->title('Could not read the app directory')->body(trim($tree->errorOutput ?: $tree->output))->warning()->send();

            return;
        }

        $this->backupTree = array_values(array_filter($tree->json()['entries'] ?? [], 'is_array'));
    }

    /**
     * Checkbox options: top-level entries and their children, each with
     * its size. Children are indented under their parent.
     *
     * @return array<string, string>
     */
    private function treeOptions(): array
    {
        $options = [];

        foreach ($this->backupTree as $entry) {
            $path = (string) ($entry['path'] ?? '');

            if ($path === '') {
                continue;
            }

            $options[$path] = e($path).' <span class="text-gray-500">('.$this->bytes($entry['size'] ?? 0).')</span>';

            foreach (is_array($entry['children'] ?? null) ? $entry['children'] : [] as $child) {
                $childPath = (string) ($child['path'] ?? '');

                if ($childPath === '') {
                    continue;
                }

                $options[$childPath] = '<span class="ps-5">&#9492; '.e($childPath).' <span class="text-gray-500">('.$this->bytes($child['size'] ?? 0).')</span></span>';
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function treePaths(): array
    {
        return array_keys($this->treeOptions());
    }

    /**
     * Ticking a box appends its path as a line; unticking removes that
     * exact line. Lines that are not tree paths are never touched.
     *
     * @param  list<string>  $ticked
     */
    private function syncPatternsFromTree(Set $set, Get $get, array $ticked): void
    {
        $lines = self::excludeLines($get('exclude'));

        foreach ($this->treePaths() as $path) {
            $isLine = in_array($path, $lines, true);
            $isTicked = in_array($path, $ticked, true);

            if ($isTicked && ! $isLine) {
                $lines[] = $path;
            } elseif (! $isTicked && $isLine) {
                $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== $path));
            }
        }

        $set('exclude', implode("\n", $lines));
    }

    /**
     * The non-empty lines of the patterns text, trimmed, in order.
     *
     * @return list<string>
     */
    private static function excludeLines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $text)), fn (string $line): bool => $line !== ''));
    }

    /**
     * The CLI's exclude line rules, checked here so the message points at
     * the line rather than the whole save failing.
     */
    private function excludeLinesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (self::excludeLines(is_string($value) ? $value : null) as $line) {
                if (strlen($line) > 200 || str_starts_with($line, '/') || in_array('..', explode('/', $line), true)) {
                    $fail("\"{$line}\" must be relative to the app directory, without .. segments, and at most 200 characters.");

                    return;
                }
            }
        };
    }
}
