<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ManagePhp;
use App\Mandal\ComposeImport;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Installed and available PHP versions for one server.
 */
class ServerPhp extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?string $title = 'PHP';

    protected string $view = 'filament.servers.pages.php';

    /** @var list<array<string, mixed>> */
    public array $installed = [];

    /** @var list<array<string, mixed>> */
    public array $available = [];

    /** @var array{value?: string, managed?: bool} */
    public array $nginxBodySize = [];

    public ?string $error = null;

    /**
     * The PHP-FPM limits php:limits manages.
     */
    private const array LIMITS = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time', 'max_input_time'];

    private const string SIZE_PATTERN = '/^[1-9][0-9]{0,5}[KMGkmg]?$/';

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

    public function load(): void
    {
        $this->error = null;

        if (! $this->server()->isProvisioned()) {
            $this->error = 'Provision this server first.';

            return;
        }

        $mandal = app(MandalClient::class);

        try {
            $installed = $mandal->run($this->server(), 'php:list', timeoutSeconds: 30);
            $available = $mandal->run($this->server(), 'php:available', timeoutSeconds: 60);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->installed = $installed->successful() ? $installed->json() : [];
        $this->available = $available->successful() ? $available->json() : [];
        $this->nginxBodySize = rescue(fn (): array => (array) ($mandal->inventory($this->server())['nginx']['client_max_body_size'] ?? []), [], report: false);
    }

    /**
     * @return list<string>
     */
    public function installable(): array
    {
        return array_values(array_map(
            fn (array $version): string => (string) $version['version'],
            array_filter($this->available, fn (array $version): bool => ($version['complete'] ?? false) && ! ($version['installed'] ?? false)),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load()),
            Action::make('install')
                ->label('Install a version')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->error === null && $this->installable() !== [])
                ->modalHeading('Install a PHP version')
                ->modalDescription('Installs PHP-FPM, the CLI and every extension Laravel needs. Only versions for which all extensions are available are listed.')
                ->modalSubmitActionLabel('Install')
                ->schema([
                    Select::make('version')
                        ->options(fn (): array => array_combine($this->installable(), $this->installable()))
                        ->required()
                        ->selectablePlaceholder(false),
                ])
                ->action(function (array $data): void {
                    $version = (string) $data['version'];

                    if (! in_array($version, $this->installable(), true)) {
                        return;
                    }

                    $this->queue(['mode' => 'install', 'version' => $version], "Installing PHP {$version}");
                }),
        ];
    }

    public function makeDefaultAction(): Action
    {
        return Action::make('makeDefault')
            ->label('Make CLI default')
            ->link()
            ->action(function (array $arguments): void {
                $version = (string) ($arguments['version'] ?? '');

                if (! preg_match('/^\d+\.\d+$/', $version)) {
                    return;
                }

                try {
                    $result = app(MandalClient::class)->run($this->server(), 'php:default --version='.$version, timeoutSeconds: 60);
                } catch (SshException $exception) {
                    Notification::make()->title('Could not change the default')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if ($result->failed()) {
                    Notification::make()->title('Could not change the default')->body(trim($result->errorOutput ?: $result->output))->danger()->send();

                    return;
                }

                app(MandalClient::class)->forgetInventory($this->server());
                Notification::make()->title("PHP {$version} is now the CLI default")->success()->send();
                $this->load();
            });
    }

    /**
     * PHP-FPM limits for one version: what a request may upload, use and
     * take. The CLI keeps PHP's own.
     */
    public function limitsAction(): Action
    {
        return Action::make('limits')
            ->label('Limits')
            ->link()
            ->modalHeading(fn (array $arguments): string => "PHP {$arguments['version']} limits")
            ->modalDescription('For PHP-FPM, so every app on this version, and the panel\'s own uploads only when the panel runs on this server. The fields show what PHP-FPM uses now; Mandal\'s defaults are 1G, 1G, 256M, 300 and 300. post_max_size must be at least upload_max_filesize. nginx has its own limit on request bodies, below on this page. Saving reloads PHP-FPM, which finishes requests in flight first.')
            ->modalSubmitActionLabel('Save and reload')
            ->fillForm(fn (array $arguments): array => array_intersect_key(
                (array) (collect($this->installed)->firstWhere('version', $arguments['version'] ?? null)['limits'] ?? []),
                array_flip(self::LIMITS),
            ))
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('upload_max_filesize')
                        ->helperText('Largest uploaded file, such as 512M or 1G.')
                        ->required()
                        ->regex(self::SIZE_PATTERN),
                    TextInput::make('post_max_size')
                        ->helperText('Largest request body, uploads included.')
                        ->required()
                        ->regex(self::SIZE_PATTERN)
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (ComposeImport::bytes((string) $value) < ComposeImport::bytes((string) $get('upload_max_filesize'))) {
                                $fail('Must be at least upload_max_filesize, or uploads that size fail.');
                            }
                        }),
                    TextInput::make('memory_limit')
                        ->helperText('Memory one request may use; -1 for no limit.')
                        ->required()
                        ->regex('/^(-1|[1-9][0-9]{0,5}[KMGkmg]?)$/'),
                    TextInput::make('max_execution_time')
                        ->label('max_execution_time (seconds)')
                        ->helperText('How long a script may run; 0 for no limit.')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(999999),
                    TextInput::make('max_input_time')
                        ->label('max_input_time (seconds)')
                        ->helperText('How long reading the request may take.')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(999999),
                ]),
            ])
            ->action(function (array $data, array $arguments): void {
                $version = (string) ($arguments['version'] ?? '');

                if (! preg_match('/^\d+\.\d+$/', $version)) {
                    return;
                }

                $options = collect(self::LIMITS)
                    ->map(fn (string $key): string => '--'.str_replace('_', '-', $key).'='.escapeshellarg(strtoupper(trim((string) $data[$key]))))
                    ->implode(' ');

                $this->runCommand("php:limits --version={$version} {$options}", "PHP {$version} limits saved", 'Could not save the limits');
            });
    }

    /**
     * nginx's request body limit, shared by every site on the server.
     */
    public function nginxBodySizeAction(): Action
    {
        return Action::make('nginxBodySize')
            ->label('Change')
            ->link()
            ->visible(fn (): bool => (bool) ($this->nginxBodySize['managed'] ?? false))
            ->modalHeading('nginx request body limit')
            ->modalDescription('client_max_body_size for every site on this server. A larger request gets "413 Request Entity Too Large" from nginx before PHP sees it, so keep it at least as large as the PHP upload limits.')
            ->modalSubmitActionLabel('Save and reload')
            ->fillForm(fn (): array => ['client_max_body_size' => $this->nginxBodySize['value'] ?? '1g'])
            ->schema([
                TextInput::make('client_max_body_size')
                    ->helperText('Such as 512m or 1g.')
                    ->required()
                    ->regex(self::SIZE_PATTERN),
            ])
            ->action(fn (array $data) => $this->runCommand(
                'nginx:limits --client-max-body-size='.escapeshellarg(strtolower(trim((string) $data['client_max_body_size']))),
                'nginx limit saved',
                'Could not save the nginx limit',
            ));
    }

    private function runCommand(string $command, string $success, string $failure): void
    {
        try {
            $result = app(MandalClient::class)->run($this->server(), $command, timeoutSeconds: 60);
        } catch (SshException $exception) {
            Notification::make()->title($failure)->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($result->failed()) {
            Notification::make()->title($failure)->body(trim($result->errorOutput ?: $result->output))->danger()->send();

            return;
        }

        app(MandalClient::class)->forgetInventory($this->server());
        Notification::make()->title($success)->success()->send();
        $this->load();
    }

    public function removeVersionAction(): Action
    {
        return Action::make('removeVersion')
            ->label('Remove')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Remove PHP {$arguments['version']}?")
            ->modalDescription('Purges the packages for this version. Refused while any app uses it or it is the CLI default.')
            ->action(function (array $arguments): void {
                $version = (string) ($arguments['version'] ?? '');

                if (! preg_match('/^\d+\.\d+$/', $version)) {
                    return;
                }

                $this->queue(['mode' => 'remove', 'version' => $version], "Removing PHP {$version}");
            });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function queue(array $options, string $title): void
    {
        $server = $this->server();

        if ($server->hasActiveTask()) {
            Notification::make()->title('Another task is still running')->warning()->send();

            return;
        }

        /** @var Task $task */
        $task = $server->tasks()->create([
            'type' => TaskType::Php,
            'user_id' => auth()->id(),
            'options' => $options,
        ]);

        ManagePhp::dispatch($task);

        Notification::make()->title($title)->body('Follow the progress on the task page.')->success()->send();

        $this->redirect(ServerResource::getUrl('task', ['record' => $server, 'task' => $task]));
    }
}
