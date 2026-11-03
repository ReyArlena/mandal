<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\HasSecuritySidebar;
use App\Filament\Resources\Servers\Concerns\RunsSecurityCommands;
use App\Filament\Resources\Servers\ServerResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * fail2ban's jails and what they have banned, with a way to let an address
 * back in (`fail2ban:unban`). The jails themselves are set during
 * provisioning.
 */
class ServerFail2ban extends Page
{
    use HasSecuritySidebar, InteractsWithRecord {
        HasSecuritySidebar::getSubNavigation insteadof InteractsWithRecord;
    }
    use RunsSecurityCommands;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $title = 'fail2ban';

    protected string $view = 'filament.servers.pages.fail2ban';

    /** @var array<string, mixed> */
    public array $fail2ban = [];

    public static function getPageDescription(): string
    {
        return 'fail2ban watches the authentication log and bans an address after repeated failures; provisioning enables its sshd jail. Its log is on the Logs page.';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

    public function load(): void
    {
        if (! $this->server()->isProvisioned()) {
            $this->error = 'Provision this server first; fail2ban is set up during provisioning.';

            return;
        }

        $this->fail2ban = $this->read('fail2ban:status') ?? [];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
        ];
    }

    public function unbanAction(): Action
    {
        return Action::make('unban')
            ->label('Unban')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Unban {$arguments['ip']}?")
            ->modalDescription(fn (array $arguments): string => "The address is let back in by the {$arguments['jail']} jail now. It is banned again after the next run of failures.")
            ->modalSubmitActionLabel('Unban')
            ->action(function (array $arguments): void {
                $ip = (string) ($arguments['ip'] ?? '');
                $jail = (string) ($arguments['jail'] ?? '');

                if (preg_match('#^[0-9a-fA-F.:]+(/\d{1,3})?$#', $ip) !== 1 || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $jail) !== 1) {
                    return;
                }

                $this->runSynchronously("fail2ban:unban --jail={$jail} --ip={$ip}", "Unbanned {$ip}", 'Could not unban the address');
            });
    }
}
