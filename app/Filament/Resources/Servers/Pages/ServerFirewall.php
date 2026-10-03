<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * ufw rules for one server: read live, add a port, delete a rule.
 */
class ServerFirewall extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $title = 'Firewall';

    protected string $view = 'filament.servers.pages.firewall';

    /** @var array<string, mixed> */
    public array $firewall = [];

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
            $this->error = 'Provision this server first; ufw is set up during provisioning.';

            return;
        }

        try {
            $result = app(MandalClient::class)->run($this->server(), 'firewall:list', timeoutSeconds: 30);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        if ($result->failed()) {
            $this->error = trim($result->errorOutput ?: $result->output);

            return;
        }

        $this->firewall = $result->json();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load()),
            Action::make('allow')
                ->label('Allow a port')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->error === null)
                ->modalHeading('Allow incoming traffic')
                ->modalDescription('Adds a ufw rule. Existing rules are never changed. Docker-published ports bypass ufw, so bind containers to 127.0.0.1 and put nginx in front instead.')
                ->modalSubmitActionLabel('Allow')
                ->schema([
                    TextInput::make('port')
                        ->required()
                        ->regex('/^\d{1,5}(:\d{1,5})?$/')
                        ->placeholder('8443 or 6000:6100'),
                    Select::make('proto')
                        ->label('Protocol')
                        ->options(['tcp' => 'TCP', 'udp' => 'UDP'])
                        ->default('tcp')
                        ->required()
                        ->selectablePlaceholder(false),
                    TextInput::make('from')
                        ->label('Only from (optional)')
                        ->regex('#^[0-9a-fA-F.:]+(/\d{1,3})?$#')
                        ->placeholder('203.0.113.0/24'),
                    TextInput::make('comment')
                        ->maxLength(60)
                        ->regex('/^[A-Za-z0-9 _.,:-]*$/'),
                ])
                ->action(function (array $data): void {
                    $arguments = ['firewall:allow', '--port='.$data['port'], '--proto='.$data['proto']];

                    if (! empty($data['from'])) {
                        $arguments[] = '--from='.$data['from'];
                    }

                    if (! empty($data['comment'])) {
                        $arguments[] = '--comment='.escapeshellarg((string) $data['comment']);
                    }

                    $this->runSynchronously(implode(' ', $arguments), "Allowed {$data['port']}/{$data['proto']}", 'Could not add the rule');
                }),
        ];
    }

    public function deleteRuleAction(): Action
    {
        return Action::make('deleteRule')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete rule {$arguments['number']} ({$arguments['to']} {$arguments['action']} {$arguments['from']})?")
            ->action(function (array $arguments): void {
                $number = (int) ($arguments['number'] ?? 0);

                if ($number < 1 || $number > 999) {
                    return;
                }

                $this->runSynchronously("firewall:delete --number={$number}", "Deleted rule {$number}", 'Could not delete the rule');
            });
    }

    private function runSynchronously(string $command, string $successTitle, string $failureTitle): void
    {
        $mandal = app(MandalClient::class);

        try {
            $result = $mandal->run($this->server(), $command, timeoutSeconds: 60);
        } catch (SshException $exception) {
            Notification::make()->title($failureTitle)->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($result->failed()) {
            Notification::make()->title($failureTitle)->body(trim($result->errorOutput ?: $result->output))->danger()->send();

            return;
        }

        $mandal->forgetInventory($this->server());
        Notification::make()->title($successTitle)->success()->send();
        $this->load();
    }
}
