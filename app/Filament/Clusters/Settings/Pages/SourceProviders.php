<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\SourceProviderType;
use App\Filament\Clusters\Settings\Concerns\HasSettingsHeading;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\SourceCredential;
use App\Sources\SourceProviders as Providers;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Source provider tokens, which let the panel add deploy keys and
 * webhooks to repositories.
 */
class SourceProviders extends Page
{
    use HasSettingsHeading;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'source-providers';

    protected static ?string $navigationLabel = 'Source providers';

    protected static ?string $title = 'Source providers';

    protected string $view = 'filament.settings.source-providers';

    public static function getPageDescription(): string
    {
        return 'Tokens let the panel add deploy keys and webhooks to your repositories.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addGitHubAction(),
        ];
    }

    /**
     * @return Collection<int, SourceCredential>
     */
    public function credentials()
    {
        return SourceCredential::query()->orderBy('provider')->orderBy('name')->get();
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
            ->action(function (array $data, Providers $providers): void {
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
}
