<?php

namespace App\Notifications;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A persistent notice in the panel's notification menu that a server runs
 * an older CLI than the panel ships. Stored as a Filament database
 * notification, with the server id alongside so it can be cleared once
 * the server is updated.
 */
class CliUpdateRequired extends Notification
{
    public function __construct(
        public readonly Server $server,
        public readonly string $version,
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
            ->title("CLI update required on {$this->server->name}")
            ->body("The server runs an older mandal CLI than this panel ships ({$this->version}). Update it from the server page.")
            ->icon('heroicon-o-arrow-down-tray')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label('View servers')
                    ->button()
                    ->url(ServerResource::getUrl()),
            ])
            ->getDatabaseMessage();

        $message['server_id'] = $this->server->getKey();

        return $message;
    }
}
