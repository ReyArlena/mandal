<?php

namespace App\Notifications;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A persistent notice that a scheduled backup on a server failed. Backups
 * run from cron on the server, so the panel only learns about it from
 * the hourly check.
 */
class BackupFailed extends Notification
{
    /**
     * @param  string  $target  `databases` or `app:<name>`
     */
    public function __construct(
        public readonly Server $server,
        public readonly string $target,
        public readonly string $status,
        public readonly string $detail,
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
        $app = str_starts_with($this->target, 'app:') ? substr($this->target, 4) : null;
        $what = $app ?? 'databases';

        $message = FilamentNotification::make()
            ->title("Backup failed on {$this->server->name}: {$what}")
            ->body(trim("{$this->status} {$this->detail}"))
            ->icon('heroicon-o-archive-box-x-mark')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label($app === null ? 'View backups' : "View {$app}")
                    ->button()
                    ->url($app === null
                        ? ServerResource::getUrl('backups', ['record' => $this->server])
                        : ServerResource::getUrl('app', ['record' => $this->server, 'app' => $app])),
            ])
            ->getDatabaseMessage();

        $message['server_id'] = $this->server->getKey();
        $message['target'] = $this->target;

        return $message;
    }
}
