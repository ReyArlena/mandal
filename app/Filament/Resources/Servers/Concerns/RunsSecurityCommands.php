<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * What the security pages share: the open server, a Refresh button, and
 * running one write command with a notification either way, reloading the
 * page's data on success.
 */
trait RunsSecurityCommands
{
    public ?string $error = null;

    abstract public function load(): void;

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    protected function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(fn () => $this->load());
    }

    /**
     * Runs a read command and returns its JSON, or sets the page error and
     * returns null.
     *
     * @return array<string, mixed>|null
     */
    protected function read(string $command): ?array
    {
        $this->error = null;

        try {
            $result = app(MandalClient::class)->run($this->server(), $command, timeoutSeconds: 30);

            if ($result->failed()) {
                $this->error = trim($result->errorOutput ?: $result->output);

                return null;
            }

            return $result->json();
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return null;
        }
    }

    protected function runSynchronously(string $command, string $successTitle, string $failureTitle): void
    {
        $mandal = app(MandalClient::class);

        try {
            $result = $mandal->run($this->server(), $command, timeoutSeconds: 60);
        } catch (SshException $exception) {
            Notification::make()->title($failureTitle)->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($result->failed()) {
            Notification::make()->title($failureTitle)->body(trim($result->errorOutput ?: $result->output))->danger()->send();

            return;
        }

        $mandal->forgetInventory($this->server());
        Notification::make()->title($successTitle)->success()->send();
        $this->load();
    }
}
