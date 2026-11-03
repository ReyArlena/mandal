<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\HasSecuritySidebar;
use App\Filament\Resources\Servers\Concerns\RunsSecurityCommands;
use App\Filament\Resources\Servers\ServerResource;
use App\Ssh\KeyPair;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * What sshd allows on the server and the keys that may run mandal: this
 * panel's, other panels' and anyone granted a shell. Keys are added and
 * removed here (`key:add`, `key:remove`); sshd itself is read-only.
 */
class ServerSsh extends Page
{
    use HasSecuritySidebar, InteractsWithRecord {
        HasSecuritySidebar::getSubNavigation insteadof InteractsWithRecord;
    }
    use RunsSecurityCommands;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $title = 'SSH';

    protected string $view = 'filament.servers.pages.ssh';

    /** @var array<string, mixed> */
    public array $sshd = [];

    /** @var list<array{fingerprint: string, type: string, comment: string}> */
    public array $keys = [];

    public static function getPageDescription(): string
    {
        return 'Keys in the mandal user\'s authorized_keys may run the CLI through sudo and, with a shell, sign in as that user. This panel\'s own key cannot be removed from here.';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

    public function load(): void
    {
        if ($this->server()->connected_at === null) {
            $this->error = 'Connect this server first; its keys are read over SSH.';

            return;
        }

        $status = $this->read('ssh:status');

        if ($status === null) {
            return;
        }

        $this->sshd = $status['sshd'] ?? [];
        $this->keys = array_values($status['keys'] ?? []);
    }

    /**
     * The fingerprint of this panel's own key, which the list marks and
     * never offers to remove.
     */
    public function ownFingerprint(): ?string
    {
        $key = $this->server()->public_key;

        return filled($key) ? KeyPair::fingerprint($key) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
            $this->addKeyAction(),
        ];
    }

    /**
     * Lets another panel, or a person, in by adding their public key.
     */
    private function addKeyAction(): Action
    {
        return Action::make('addKey')
            ->label('Add key')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Allow another key')
            ->modalDescription('Paste a public key: another panel\'s, from its server page, or your own to get a shell as the mandal user. It is added to the mandal user\'s authorized keys with the label, so it can be removed again later.')
            ->modalSubmitActionLabel('Allow')
            ->schema([
                TextInput::make('name')
                    ->label('Label')
                    ->required()
                    ->regex('/^[A-Za-z0-9 _.()-]{1,64}$/')
                    ->placeholder('Laptop panel'),
                Textarea::make('key')
                    ->label('Public key')
                    ->required()
                    ->rows(3)
                    ->regex('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+/=]+( .*)?$#'),
            ])
            ->action(function (array $data): void {
                $key = implode(' ', array_slice(preg_split('/\s+/', trim((string) $data['key'])) ?: [], 0, 2));

                $this->runSynchronously('key:add --name='.escapeshellarg((string) $data['name']).' --key='.escapeshellarg($key), 'Key added', 'Could not add the key');
            });
    }

    public function removeKeyAction(): Action
    {
        return Action::make('removeKey')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Remove the key "'.($arguments['comment'] ?: $arguments['fingerprint']).'"?')
            ->modalDescription(fn (array $arguments): string => 'Whoever holds '.$arguments['fingerprint'].' can no longer run mandal or sign in as the mandal user. The last key can never be removed.')
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $arguments): void {
                $fingerprint = (string) ($arguments['fingerprint'] ?? '');

                if (preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $fingerprint) !== 1 || $fingerprint === $this->ownFingerprint()) {
                    return;
                }

                $this->runSynchronously("key:remove --fingerprint={$fingerprint}", 'Key removed', 'Could not remove the key');
            });
    }
}
