<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Backups\RemoteSync;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\BackupDestination;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * The S3-compatible destinations backups can be sent to.
 */
class BackupDestinations extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'backup-destinations';

    protected static ?string $navigationLabel = 'Backup destinations';

    protected static ?string $title = 'Backup destinations';

    protected string $view = 'filament.settings.backup-destinations';

    public static function getPageDescription(): string
    {
        return 'Where backups are sent. Keys are stored encrypted.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addDestinationAction(),
        ];
    }

    /**
     * @return Collection<int, BackupDestination>
     */
    public function destinations()
    {
        return BackupDestination::query()->orderBy('name')->get();
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
