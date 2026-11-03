<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\HasSecuritySidebar;
use App\Filament\Resources\Servers\Concerns\RunsSecurityCommands;
use App\Filament\Resources\Servers\ServerResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * ufw rules for one server: read live, add a port, delete a rule. The first
 * page of the Security section; SSH and fail2ban sit beside it.
 */
class ServerFirewall extends Page
{
    use HasSecuritySidebar, InteractsWithRecord {
        HasSecuritySidebar::getSubNavigation insteadof InteractsWithRecord;
    }
    use RunsSecurityCommands;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $title = 'Firewall';

    protected string $view = 'filament.servers.pages.firewall';

    /** @var array<string, mixed> */
    public array $firewall = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

    public function load(): void
    {
        if (! $this->server()->isProvisioned()) {
            $this->error = 'Provision this server first; ufw is set up during provisioning.';

            return;
        }

        $this->firewall = $this->read('firewall:list') ?? [];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
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
}
