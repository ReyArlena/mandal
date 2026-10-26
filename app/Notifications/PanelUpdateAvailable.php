<?php

namespace App\Notifications;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A persistent notice that a newer Mandal release exists, with the
 * command to run on the hosting server. The version is stored alongside
 * so the notice can be replaced when a newer release appears.
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
            ->body("This panel runs {$this->current}. SSH into {$where} as the mandal user (allow your own public key with Grant access first) and run: sudo mandal panel:update")
            ->icon('heroicon-o-arrow-up-circle')
            ->iconColor('info')
            ->actions([
                Action::make('view')
                    ->label($this->server === null ? 'View servers' : "View {$this->server->name}")
                    ->button()
                    ->url($this->server === null ? ServerResource::getUrl() : ServerResource::getUrl('view', ['record' => $this->server])),
            ])
            ->getDatabaseMessage();

        $message['version'] = $this->latest;

        return $message;
    }
}
