<?php

namespace App\Notifications;

use App\Filament\Clusters\Settings\Pages\Updates;
use App\Mandal\PanelUpdate;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A persistent notice that a newer build of Mandal exists on the panel's
 * release channel, linking to Settings > Updates where it is installed.
 * The build's id is stored alongside so the notice can be replaced when a
 * newer one appears.
 */
class PanelUpdateAvailable extends Notification
{
    public function __construct(
        public readonly PanelUpdate $update,
        public readonly string $running,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $message = FilamentNotification::make()
            ->title($this->update->title())
            ->body("This panel runs {$this->running}. Update it under Settings > Updates.")
            ->icon('heroicon-o-arrow-up-circle')
            ->iconColor('info')
            ->actions([
                Action::make('view')
                    ->label('Open Updates')
                    ->button()
                    ->url(Updates::getUrl()),
            ])
            ->getDatabaseMessage();

        $message['version'] = $this->update->id;

        return $message;
    }
}
