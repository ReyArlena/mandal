<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\TaskType;
use App\Filament\Resources\Servers\ServerResource;
use App\Jobs\ManagePhp;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Task;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
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
