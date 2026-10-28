<?php

namespace App\Filament\Pages;

use App\Backups\RemoteSync;
use App\Enums\SourceProviderType;
use App\Models\BackupDestination;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Sources\SourceProviders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Panel-wide settings: the public address, source provider tokens and
 * the S3-compatible destinations backups can be sent to.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * Reached from the user menu, not the server tabs.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.settings';

    public function publicUrl(): ?string
    {
        return Setting::publicUrl();
    }

    /**
     * @return Collection<int, SourceCredential>
     */
    public function credentials()
    {
        return SourceCredential::query()->orderBy('provider')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, BackupDestination>
     */
    public function destinations()
    {
        return BackupDestination::query()->orderBy('name')->get();
    }

    public function publicUrlAction(): Action
    {
        return Action::make('publicUrl')
            ->label('Public URL')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('gray')
            ->modalHeading('Public URL')
            ->modalDescription('The address other services reach this panel at. Needed for automatic deploys on push, which GitHub delivers to this URL. Leave empty when the panel only runs on your own machine.')
            ->fillForm(fn (): array => ['public_url' => Setting::publicUrl()])
            ->schema([
                TextInput::make('public_url')
                    ->label('URL')
                    ->url()
                    ->placeholder('https://panel.example.com')
                    ->rules(['nullable', 'url:https', 'max:200']),
            ])
            ->action(function (array $data): void {
                Setting::set(Setting::PUBLIC_URL, $data['public_url'] ? rtrim((string) $data['public_url'], '/') : null);
                Notification::make()->title('Public URL saved')->success()->send();
            });
    }

    public function addGitHubAction(): Action
    {
        return Action::make('addGitHub')
            ->label('Add GitHub token')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (): bool => SourceCredential::forProvider(SourceProviderType::GitHub) === null)
            ->modalHeading('Connect GitHub')
            ->modalDescription('Create a fine-grained personal access token in GitHub with repository permissions Administration (read and write), Webhooks (read and write) and Contents (read) for the repositories you deploy. The token is stored encrypted and never leaves this panel.')
            ->modalSubmitActionLabel('Connect')
            ->schema([
                TextInput::make('name')
                    ->default('Personal token')
                    ->required()
                    ->maxLength(64),
                TextInput::make('token')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('off')
                    ->regex('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/')
                    ->helperText('Starts with github_pat_'),
            ])
            ->action(function (array $data, SourceProviders $providers): void {
                try {
                    $info = $providers->make(SourceProviderType::GitHub, (string) $data['token'])->tokenInfo();
                } catch (Throwable $exception) {
                    Notification::make()->title('GitHub rejected the token')->body($exception->getMessage())->danger()->send();

                    return;
                }

                SourceCredential::query()->create([
                    'provider' => SourceProviderType::GitHub,
                    'name' => $data['name'],
                    'token' => $data['token'],
                    'account' => $info['account'],
                    'expires_at' => $info['expires_at'],
                ]);

                Notification::make()->title('GitHub connected as '.($info['account'] ?? 'unknown'))->success()->send();
            });
    }

    public function removeCredentialAction(): Action
    {
        return Action::make('removeCredential')
            ->label('Remove')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Remove this token?')
            ->modalDescription('Deploy keys and webhooks already created stay in place on GitHub; the panel just loses the ability to manage them.')
            ->action(function (array $arguments): void {
                SourceCredential::query()->whereKey((int) ($arguments['id'] ?? 0))->delete();
                Notification::make()->title('Token removed')->success()->send();
            });
    }

    public function addDestinationAction(): Action
    {
        return Action::make('addDestination')
            ->label('Add destination')
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->modalHeading('Add a backup destination')
            ->modalDescription('Any S3-compatible bucket: AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others. The keys are stored encrypted and copied to a server only when a backup there uses this destination.')
            ->modalSubmitActionLabel('Add')
            ->schema($this->destinationSchema())
            ->action(function (array $data): void {
                BackupDestination::query()->create([
                    'name' => trim((string) $data['name']),
                    'endpoint' => $data['endpoint'],
                    'region' => $data['region'] ?: 'auto',
                    'bucket' => $data['bucket'],
                    'path' => trim((string) ($data['path'] ?? ''), '/') ?: null,
                    'access_key' => $data['access_key'],
                    'secret_key' => $data['secret_key'],
                ]);

                Notification::make()->title('Destination added')->body('Choose it in the backup settings of a server or app.')->success()->send();
            });
    }

    public function editDestinationAction(): Action
    {
        return Action::make('editDestination')
            ->label('Edit')
            ->color('gray')
            ->link()
            ->modalHeading(fn (array $arguments): string => 'Edit '.($this->destination($arguments)?->name ?? 'destination'))
            ->modalDescription('Servers already using this destination pick up the change the next time a backup on them is saved.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $destination = $this->destination($arguments);

                return $destination === null ? [] : [
                    'name' => $destination->name,
                    'endpoint' => $destination->endpoint,
                    'region' => $destination->region,
                    'bucket' => $destination->bucket,
                    'path' => $destination->path,
                ];
            })
            ->schema(fn (array $arguments): array => $this->destinationSchema($this->destination($arguments)))
            ->action(function (array $arguments, array $data): void {
                $destination = $this->destination($arguments);

                if ($destination === null) {
                    return;
                }

                $destination->fill([
                    'name' => trim((string) $data['name']),
                    'endpoint' => $data['endpoint'],
                    'region' => $data['region'] ?: 'auto',
                    'bucket' => $data['bucket'],
                    'path' => trim((string) ($data['path'] ?? ''), '/') ?: null,
                ]);

                // Empty key fields keep the stored keys.
                if (filled($data['access_key'] ?? null)) {
                    $destination->access_key = $data['access_key'];
                }

                if (filled($data['secret_key'] ?? null)) {
                    $destination->secret_key = $data['secret_key'];
                }

                $destination->save();

                Notification::make()->title('Destination saved')->success()->send();
            });
    }

    public function removeDestinationAction(): Action
    {
        return Action::make('removeDestination')
            ->label('Remove')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Remove '.($this->destination($arguments)?->name ?? 'this destination').'?')
            ->modalDescription('Every server still using it is switched off this remote, and any backup that had no other destination is disabled. Files already in the bucket stay.')
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $arguments, RemoteSync $sync): void {
                $destination = $this->destination($arguments);

                if ($destination === null) {
                    return;
                }

                $destination->delete();

                $report = $sync->remove($destination);
                $lines = [];

                foreach ($report['servers'] as $server => $changes) {
                    $detached = $changes['detached'] === [] ? 'nothing used it' : 'detached '.implode(', ', $changes['detached']);
                    $disabled = $changes['disabled'] === [] ? '' : '; disabled '.implode(', ', $changes['disabled']);
                    $lines[] = "{$server}: {$detached}{$disabled}";
                }

                Notification::make()
                    ->title("Destination {$destination->name} removed")
                    ->body($lines === [] ? 'No provisioned server to update.' : implode("\n", $lines))
                    ->success()
                    ->send();

                if ($report['unreachable'] !== []) {
                    $failed = [];

                    foreach ($report['unreachable'] as $server => $reason) {
                        $failed[] = "{$server}: {$reason}";
                    }

                    Notification::make()
                        ->title('Some servers still reference the removed destination')
                        ->body("Open their backup settings and save them again once they are reachable.\n".implode("\n", $failed))
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function destination(array $arguments): ?BackupDestination
    {
        return BackupDestination::query()->find((int) ($arguments['id'] ?? 0));
    }

    /**
     * The add and edit forms differ only in whether the keys are required.
     *
     * @return list<TextInput>
     */
    private function destinationSchema(?BackupDestination $existing = null): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(64)
                ->regex('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/')
                ->rules([Rule::unique(BackupDestination::class, 'name')->ignore($existing)])
                ->helperText($existing === null ? 'Shown when choosing a destination. Letters, digits, spaces, dots, underscores and dashes.' : "Known to servers as {$existing->slug}; that name does not change."),
            TextInput::make('endpoint')
                ->label('Endpoint URL')
                ->url()
                ->required()
                ->rules(['url:https', 'max:200'])
                ->placeholder('https://s3.eu-central-1.amazonaws.com'),
            TextInput::make('bucket')
                ->required()
                ->regex('/^[a-z0-9][a-z0-9.-]{1,62}$/')
                ->live(onBlur: true),
            TextInput::make('path')
                ->label('Path prefix')
                ->regex('#^[A-Za-z0-9._/-]{0,200}$#')
                ->placeholder('mandal')
                ->live(onBlur: true)
                ->helperText(function (Get $get): string {
                    $prefix = trim((string) $get('path'), '/');

                    return 's3://'.(($get('bucket') ?: '<bucket>').($prefix === '' ? '' : '/'.$prefix)).'/';
                }),
            TextInput::make('region')
                ->default('auto')
                ->regex('/^[A-Za-z0-9-]{1,32}$/')
                ->helperText('"auto" works for most providers.'),
            TextInput::make('access_key')
                ->password()
                ->revealable()
                ->autocomplete('off')
                ->required($existing === null)
                ->maxLength(200)
                ->helperText($existing === null ? null : 'Leave empty to keep the stored key.'),
            TextInput::make('secret_key')
                ->password()
                ->revealable()
                ->autocomplete('off')
                ->required($existing === null)
                ->maxLength(200)
                ->helperText($existing === null ? null : 'Leave empty to keep the stored key.'),
        ];
    }
}
