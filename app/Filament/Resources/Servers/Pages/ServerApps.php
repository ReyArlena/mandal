<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WwwMode;
use App\Filament\Resources\Servers\Concerns\ManagesApps;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\CreateApp;
use App\Mandal\ComposeImport;
use App\Mandal\InvalidImport;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The apps on a server, read from its manifests, with create and delete.
 */
class ServerApps extends Page
{
    use InteractsWithRecord;
    use ManagesApps;

    /**
     * What the compose field starts with: one service, bound to loopback
     * for nginx, unable to gain privileges, with the knobs most apps need.
     */
    public const string COMPOSE_TEMPLATE = <<<'YAML'
    services:
      app:
        image: ghcr.io/org/app:1.2
        restart: unless-stopped
        security_opt:
          - no-new-privileges:true
        env_file:
          - .env
        ports:
          - "127.0.0.1:8080:80"
        volumes:
          - data:/data
        logging:
          driver: json-file
          options:
            max-size: "10m"
            max-file: "3"
        # An image that runs as a non-root user cannot write to a folder
        # mounted from the project (owned by root). Hand it over first with
        # the one-off service below, and start this one after it:
        # depends_on:
        #   fix-permissions:
        #     condition: service_completed_successfully

      # fix-permissions:
      #   image: busybox:1.37
      #   command: chown -R 10000:10000 /data
      #   volumes:
      #     - ./savegame:/data
      #   restart: "no"

    volumes:
      data:
    YAML;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $title = 'Apps';

    protected string $view = 'filament.servers.pages.apps';

    /** @var list<array<string, mixed>> */
    public array $apps = [];

    /** @var list<string> */
    public array $phpVersions = [];

    /**
     * Names and domains already in use on the server, from the inventory:
     * compose projects, databases and the server names of every nginx site.
     *
     * @var array{projects: list<string>, databases: list<string>, domains: list<string>}
     */
    public array $taken = ['projects' => [], 'databases' => [], 'domains' => []];

    /**
     * Names the CLI refuses: system users and services an app user would
     * collide with.
     */
    private const array RESERVED_NAMES = ['mandal', 'root', 'admin', 'www-data', 'nginx', 'mysql', 'mariadb', 'redis', 'dragonfly', 'docker', 'daemon', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp', 'proxy', 'backup', 'list', 'irc', 'nobody', 'ubuntu'];

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
        $this->taken = [
            'projects' => array_values(array_map('strval', array_column($inventory['docker']['projects'] ?? [], 'Name'))),
            'databases' => array_values(array_map('strval', $inventory['database']['databases'] ?? [])),
            'domains' => collect($inventory['nginx']['sites'] ?? [])->pluck('server_names')->flatten()
                ->merge(collect($this->apps)->pluck('domain'))
                ->merge(collect($this->apps)->flatMap(fn (array $app): array => preg_split('/\s+/', (string) ($app['aliases'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []))
                ->filter(fn (mixed $domain): bool => is_string($domain) && $domain !== '')
                ->unique()->values()->all(),
        ];
    }

    /**
     * Why a name cannot be used for a new app of this type, or null.
     */
    public function nameProblem(string $name, string $type): ?string
    {
        return match (true) {
            in_array($name, self::RESERVED_NAMES, true) => "{$name} is reserved for the system.",
            in_array($name, array_column($this->apps, 'name'), true) => "An app called {$name} already exists.",
            $type === 'docker' && in_array($name, $this->taken['projects'], true) => "A Docker compose project called {$name} already runs on this server.",
            $type !== 'docker' && in_array(str_replace('-', '_', $name), $this->taken['databases'], true) => 'A database called '.str_replace('-', '_', $name).' already exists; the app would need it.',
            default => null,
        };
    }

    /**
     * Why a domain cannot be used for a new app, or null. The www. form is
     * checked too, since nginx answers on both.
     */
    public function domainProblem(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $bare = preg_replace('/^www\./', '', $domain) ?? $domain;

        foreach ([$bare, 'www.'.$bare] as $candidate) {
            if (in_array($candidate, $this->taken['domains'], true)) {
                return "{$candidate} is already served by a site on this server.";
            }
        }

        return null;
    }

    /**
     * A compose file must not name its own containers: Mandal names them
     * after the app, so one file can serve several apps.
     */
    public static function composeProblem(string $compose): ?string
    {
        return preg_match('/^[ \t]+container_name[ \t]*:/m', $compose)
            ? 'Remove container_name: Mandal names containers <app>-<service>-1 after the app, so the same file can be used for more than one app.'
            : null;
    }

    /**
     * Re-checks a field as soon as it changes, so its error shows and the
     * Create button is disabled before anything is submitted.
     */
    private function validateLive(Field $component): void
    {
        $this->resetErrorBag($component->getStatePath());
        $this->validateOnly($component->getStatePath());
    }

    public function hasPendingTask(): bool
    {
        return $this->server()->tasks()
            ->whereIn('type', [TaskType::CreateApp, TaskType::DeleteApp, TaskType::Deploy, TaskType::Rollback, TaskType::Control, TaskType::Certificate])
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::Running])
            ->exists();
    }

    public function appUrl(string $name): string
    {
        return ServerResource::getUrl('app', ['record' => $this->server(), 'app' => $name]);
    }

    /**
     * The Logs page opened on the app's most useful source: its containers
     * for a Docker app, the Laravel log otherwise.
     *
     * @param  array<string, mixed>  $app
     */
    public function logsUrl(array $app): string
    {
        return self::logsUrlFor($this->server(), $app);
    }

    /**
     * @param  array<string, mixed>  $app
     */
    public static function logsUrlFor(Server $server, array $app): string
    {
        $prefix = ($app['type'] ?? 'laravel') === 'docker' ? 'docker' : 'laravel';

        return ServerResource::getUrl('logs', ['record' => $server, 'source' => "{$prefix}:{$app['name']}"]);
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
            ->modalHeading('Create an app')
            ->modalDescription('A Laravel app gets a system user, PHP-FPM pool, nginx site, database and a production .env; deploying the code comes next. A Docker app runs the compose file you provide, optionally with nginx in front.')
            ->modalSubmitActionLabel('Create app')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(fn (): bool => $this->getErrorBag()->isNotEmpty()))
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema([
                ViewField::make('type')
                    ->label('App type')
                    ->hiddenLabel()
                    ->view('filament.forms.tab-choice')
                    ->viewData([
                        'options' => ['laravel' => 'Laravel', 'docker' => 'Docker'],
                        'icons' => ['laravel' => 'mandal-laravel', 'docker' => 'mandal-docker'],
                    ])
                    ->default('laravel')
                    ->required()
                    ->in(['laravel', 'docker'])
                    // Errors on fields the other type hides would keep Create disabled.
                    ->afterStateUpdated(fn () => $this->resetErrorBag()),
                TextInput::make('name')
                    ->required()
                    ->regex(self::APP_NAME_PATTERN)
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (is_string($value) && ($problem = $this->nameProblem($value, (string) $get('type'))) !== null) {
                            $fail($problem);
                        }
                    })
                    ->live(debounce: 500)
                    ->afterStateUpdated(fn (TextInput $component) => $this->validateLive($component))
                    ->helperText('2 to 32 lower-case letters, digits or hyphens. Used for the system user, database and directories.')
                    ->placeholder('shop'),
                Section::make('Import backup (optional)')
                    ->description('Start from a .zip or .tar.gz of an existing project folder.')
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->visible(fn (callable $get): bool => $get('type') === 'docker')
                    ->schema([
                        FileUpload::make('import')
                            ->hiddenLabel()
                            ->helperText(fn (): string => 'Its compose file (compose.yaml, compose.yml, docker-compose.yaml or docker-compose.yml) fills the field below and is saved as compose.yml; a .env fills the environment variables, and every other file is copied into /srv/docker/<name> before the containers start. Max upload size to this panel: '.ComposeImport::human(ComposeImport::maxUploadBytes()).' (set by the panel\'s own PHP; on a panel Mandal hosts, raise it on that server\'s PHP page).')
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->storeFileNamesIn('import_name')
                            ->maxSize(intdiv(ComposeImport::maxUploadBytes(), 1024))
                            ->rules(['extensions:zip,gz,tgz', fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if ($value instanceof TemporaryUploadedFile) {
                                    try {
                                        ComposeImport::inspect($value->getRealPath());
                                    } catch (InvalidImport $exception) {
                                        $fail($exception->getMessage());
                                    }
                                }
                            }])
                            ->validationMessages(['extensions' => 'Choose a .zip or .tar.gz file.'])
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Set $set, Get $get, FileUpload $component) => $this->readImport($state, $set, $get, $component)),
                    ]),
                CodeEditor::make('compose')
                    ->wrap()
                    ->label('Compose file')
                    ->language(Language::Yaml)
                    ->default(self::COMPOSE_TEMPLATE)
                    ->requiredIf('type', 'docker')
                    ->rules(['max:262144', fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && ($problem = self::composeProblem($value)) !== null) {
                            $fail($problem);
                        }
                    }])
                    ->live(debounce: 1000)
                    ->afterStateUpdated(fn (CodeEditor $component) => $this->validateLive($component))
                    ->helperText('Saved as compose.yml in /srv/docker/<name> and run as root through Docker, like any compose project. Ports here bypass ufw; bind to 127.0.0.1 unless a port should be public.')
                    ->visible(fn (callable $get): bool => $get('type') === 'docker'),
                Checkbox::make('proxy')
                    ->label('Serve a domain through nginx')
                    ->afterStateUpdated(fn () => $this->resetErrorBag())
                    ->helperText('For web apps: nginx proxies the domain to a port a service publishes on 127.0.0.1. Leave it off for game servers and anything that is not HTTP.')
                    ->live()
                    ->visible(fn (callable $get): bool => $get('type') === 'docker'),
                TextInput::make('port')
                    ->label('Upstream port')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->placeholder('8080')
                    ->helperText('The port a service publishes on 127.0.0.1 (for example "127.0.0.1:8080:80" in its ports).')
                    ->required(fn (callable $get): bool => $get('type') === 'docker' && (bool) $get('proxy'))
                    ->visible(fn (callable $get): bool => $get('type') === 'docker' && (bool) $get('proxy')),
                TextInput::make('domain')
                    ->required(fn (callable $get): bool => $get('type') !== 'docker' || (bool) $get('proxy'))
                    ->rule(fn (): string => 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && ($problem = $this->domainProblem($value)) !== null) {
                            $fail($problem);
                        }
                    })
                    ->live(debounce: 500)
                    ->afterStateUpdated(fn (TextInput $component) => $this->validateLive($component))
                    ->placeholder('shop.example.com')
                    ->helperText('Without www.')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker' || (bool) $get('proxy')),
                Select::make('www')
                    ->label('www handling')
                    ->options(WwwMode::class)
                    ->default(WwwMode::default())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker' || (bool) $get('proxy')),
                TagsInput::make('aliases')
                    ->placeholder('Add another domain')
                    ->nestedRecursiveRules(['regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'])
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        foreach ((array) $value as $alias) {
                            if (is_string($alias) && ($problem = $this->domainProblem($alias)) !== null) {
                                $fail($problem);

                                return;
                            }
                        }
                    })
                    ->live()
                    ->afterStateUpdated(fn (TagsInput $component) => $this->validateLive($component))
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker' || (bool) $get('proxy')),
                Select::make('php')
                    ->label('PHP version')
                    ->options(array_combine($this->phpVersions, $this->phpVersions))
                    ->default($this->defaultPhp)
                    ->requiredIf('type', 'laravel')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker')
                    ->selectablePlaceholder(false),
                TextInput::make('repo')
                    ->label('GitHub repository')
                    ->placeholder('owner/name')
                    ->regex('#^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$#')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker'),
                TextInput::make('branch')
                    ->default('main')
                    ->regex('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$#')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker'),
                Checkbox::make('https')
                    ->label('Public repository: clone over HTTPS without a deploy key')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker'),
                Checkbox::make('database')
                    ->label('Create a database and user')
                    ->default(true)
                    ->helperText('Named after the app, with a generated password. Both are written to the app\'s .env, which you can read from the app page; the panel does not keep the password.')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker'),
                CodeEditor::make('deploy_script')
                    ->wrap()
                    ->label('Deploy script')
                    ->default(fn (): string => self::deployScriptTemplate())
                    ->requiredIf('type', 'laravel')
                    ->rules(['max:262144'])
                    ->helperText('Saved as /home/<name>/deploy.sh and run inside each new release before it goes live. Editable later from the app page.')
                    ->visible(fn (callable $get): bool => $get('type') !== 'docker'),
                Textarea::make('env')
                    ->label('Environment variables')
                    ->rows(5)
                    ->placeholder("KEY=value\nOTHER=value")
                    ->helperText('Written to .env next to the compose file; reference it with env_file or ${VAR}.')
                    ->visible(fn (callable $get): bool => $get('type') === 'docker'),
            ])
            ->action(function (array $data): void {
                $type = ($data['type'] ?? 'laravel') === 'docker' ? 'docker' : 'laravel';
                $proxy = $type === 'docker' && (bool) ($data['proxy'] ?? false);

                $options = [
                    'type' => $type,
                    'name' => $data['name'],
                    'domain' => $type === 'laravel' || $proxy ? $data['domain'] : null,
                    'www' => $data['www'] ?? WwwMode::default()->value,
                    'aliases' => array_values($data['aliases'] ?? []),
                ];

                if ($type === 'docker') {
                    $options += [
                        'import' => is_string($data['import'] ?? null) ? $data['import'] : null,
                        'import_name' => is_string($data['import_name'] ?? null) ? $data['import_name'] : null,
                        'compose' => (string) $data['compose'],
                        'proxy' => $proxy,
                        'port' => $proxy ? (string) $data['port'] : null,
                        'env' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['env'] ?? '')) ?: []))),
                    ];
                } else {
                    $options += [
                        'php' => $data['php'],
                        'repo' => $data['repo'] ?: null,
                        'branch' => $data['branch'] ?: 'main',
                        'https' => (bool) ($data['https'] ?? false),
                        'database' => (bool) ($data['database'] ?? true),
                        'deploy_script' => rtrim((string) ($data['deploy_script'] ?? ''))."\n",
                    ];
                }

                $this->queueTask(TaskType::CreateApp, CreateApp::class, $options, "Creating {$data['name']}");
            });
    }

    /**
     * Checks an uploaded backup as soon as it arrives and fills the compose
     * file, environment and, when still empty, the name from it.
     */
    private function readImport(mixed $state, Set $set, Get $get, FileUpload $component): void
    {
        $file = collect(is_array($state) ? $state : [$state])->first(fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $import = ComposeImport::inspect($file->getRealPath());
        } catch (InvalidImport $exception) {
            throw ValidationException::withMessages([$component->getStatePath() => $exception->getMessage()]);
        }

        $set('compose', $import->compose);
        $compose = (string) preg_replace('/\.import$/', '.compose', $component->getStatePath());
        $this->resetErrorBag($compose);

        if (($problem = self::composeProblem($import->compose)) !== null) {
            $this->addError($compose, $problem);
        }

        if ($import->env !== null && trim((string) $get('env')) === '') {
            $set('env', $import->envLines());
        }

        $name = Str::slug(preg_replace('/\.(zip|tar\.gz|tgz)$/i', '', $file->getClientOriginalName()) ?? '');

        if (trim((string) $get('name')) === '' && preg_match(self::APP_NAME_PATTERN, $name) && $this->nameProblem($name, 'docker') === null) {
            $set('name', $name);
            $this->resetErrorBag((string) preg_replace('/\.import$/', '.name', $component->getStatePath()));
        }
    }
}
