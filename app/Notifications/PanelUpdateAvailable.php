<?php

namespace App\Notifications;

use App\Filament\Clusters\Settings\Pages\Updates;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A persistent notice that a newer Mandal release exists, with the
 * command to run on the hosting server and a link to Settings > Updates,
 * which has the steps. The version is stored alongside so the notice can
 * be replaced when a newer release appears.
 */
class PanelUpdateAvailable extends Notification
{
    public function __construct(
        public readonly string $latest,
        public readonly string $current,
        public readonly ?Server $server,
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
        $where = $this->server === null ? 'the server that hosts the panel' : "{$this->server->name} ({$this->server->host})";

        $message = FilamentNotification::make()
            ->title("Mandal {$this->latest} is available")
            ->body("This panel runs {$this->current}. SSH into {$where} as the mandal user and run: sudo mandal panel:update. Settings > Updates has the steps.")
            ->icon('heroicon-o-arrow-up-circle')
            ->iconColor('info')
            ->actions([
                Action::make('view')
                    ->label('Open Updates')
                    ->button()
                    ->url(Updates::getUrl()),
            ])
            ->getDatabaseMessage();

        $message['version'] = $this->latest;

        return $message;
    }
}
