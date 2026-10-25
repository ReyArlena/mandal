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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

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
            ->modalHeading('Create an app')
            ->modalDescription('A Laravel app gets a system user, PHP-FPM pool, nginx site, database and a production .env; deploying the code comes next. A Docker app runs the compose file you provide, optionally with nginx in front.')
            ->modalSubmitActionLabel('Create app')
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema([
                Select::make('type')
                    ->options(['laravel' => 'Laravel app', 'docker' => 'Docker compose project'])
                    ->default('laravel')
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false),
                TextInput::make('name')
                    ->required()
                    ->regex(self::APP_NAME_PATTERN)
                    ->notIn(array_column($this->apps, 'name'))
                    ->helperText('2 to 32 lower-case letters, digits or hyphens. Used for the system user, database and directories.')
                    ->placeholder('shop'),
                Textarea::make('compose')
                    ->label('Compose file')
                    ->rows(14)
                    ->default(self::COMPOSE_TEMPLATE)
                    ->requiredIf('type', 'docker')
                    ->maxLength(262144)
                    ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false'])
                    ->helperText('Saved as compose.yml in /srv/docker/<name> and run as root through Docker, like any compose project. Ports here bypass ufw; bind to 127.0.0.1 unless a port should be public.')
                    ->visible(fn (callable $get): bool => $get('type') === 'docker'),
                Checkbox::make('proxy')
                    ->label('Serve a domain through nginx')
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
                    ];
                }

                $this->queueTask(TaskType::CreateApp, CreateApp::class, $options, "Creating {$data['name']}");
            });
    }
}
