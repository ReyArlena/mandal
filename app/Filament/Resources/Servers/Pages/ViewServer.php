<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Enums\ServerSetupMode;
use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\KeyPair;
use App\Ssh\SshClient;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->setupCommandAction(),
            $this->testConnectionAction(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Issues a fresh setup token and shows the one-line command to run on
     * the server as root.
     */
    private function setupCommandAction(): Action
    {
        return Action::make('setupCommand')
            ->label('Setup command')
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->visible(fn (Server $record): bool => $record->connected_at === null && $record->setup_mode !== ServerSetupMode::Existing)
            ->modalHeading('Set up the server')
            ->modalContent(function (Server $record): View {
                $token = $record->issueSetupToken();
                $scriptUrl = route('setup.script', ['server' => $record, 'token' => $token]);

                return view('filament.servers.setup-command', [
                    'server' => $record,
                    'scriptUrl' => $scriptUrl,
                    'command' => "curl -fsSL {$scriptUrl} | bash",
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Connects to the server. The first time, the host key fingerprint is
     * shown for confirmation before it is pinned.
     */
    private function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->requiresConfirmation(fn (Server $record): bool => ! $record->hasPinnedHostKey())
            ->modalHeading('Confirm the server\'s host key')
            ->modalDescription(function (Server $record, SshClient $ssh): string {
                try {
                    $fingerprint = KeyPair::fingerprint($ssh->fetchHostKey($record));
                } catch (SshException $exception) {
                    return "Could not read the host key: {$exception->getMessage()}";
                }

                return "The server at {$record->host} presented the host key {$fingerprint}. "
                    .'Check it against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, '
                    .'then confirm to pin it. Mandal will refuse to connect if it ever changes.';
            })
            ->modalSubmitActionLabel('Trust this host key')
            ->action(function (Server $record, SshClient $ssh, MandalClient $mandal): void {
                try {
                    $connection = $ssh->connect($record, trustUnknownHostKey: true);
                    $result = $connection->run('echo mandal-ok', timeoutSeconds: 15);
                    $connection->disconnect();
                } catch (SshException $exception) {
                    Notification::make()
                        ->title('Connection failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                if (! str_contains($result->output, 'mandal-ok')) {
                    Notification::make()
                        ->title('Connected, but the shell did not respond as expected')
                        ->body(trim($result->errorOutput ?: $result->output))
                        ->warning()
                        ->send();

                    return;
                }

                $record->forceFill(['connected_at' => now()])->save();
                $record->clearSetupToken();
                $mandal->forgetInventory($record);

                Notification::make()
                    ->title("Connected to {$record->name}")
                    ->success()
                    ->send();

                $this->refreshFormData(['connected_at', 'host_key']);
            });
    }
}
