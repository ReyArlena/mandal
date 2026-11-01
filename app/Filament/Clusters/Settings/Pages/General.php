<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The panel's public address and version.
 */
class General extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'general';

    protected string $view = 'filament.settings.general';

    protected function getHeaderActions(): array
    {
        return [
            $this->publicUrlAction(),
        ];
    }

    public function publicUrl(): ?string
    {
        return Setting::publicUrl();
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
}
