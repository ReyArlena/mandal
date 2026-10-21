<?php

namespace App\Filament\Pages;

use App\Enums\SourceProviderType;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Sources\SourceProviders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Panel-wide settings: the public address and source provider tokens.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * Reached from the user menu; the sidebar is for servers.
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publicUrl')
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
                }),
            Action::make('addGitHub')
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
                }),
        ];
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
