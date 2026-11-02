<?php

namespace App\Filament\Resources\Servers\Concerns;

use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\CommandResult;
use App\Ssh\SshException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The server's databases and users as `db:list` reports them, shared by the
 * Databases and Users pages, and the way both run a change and reload.
 */
trait InteractsWithDatabases
{
    /** @var array<string, mixed> */
    public array $overview = [];

    public ?string $error = null;

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    public function load(): void
    {
        $this->error = null;
        $server = $this->server();

        if (! $server->isProvisioned()) {
            $this->error = 'Provision this server before managing its databases.';

            return;
        }

        try {
            $result = app(MandalClient::class)->run($server, 'db:list', timeoutSeconds: 60);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        if ($result->failed()) {
            $this->error = trim($result->errorOutput ?: $result->output) ?: 'The server could not list its databases.';

            return;
        }

        $this->overview = $result->json();

        if (! ($this->overview['installed'] ?? false) || ($this->overview['active'] ?? '') !== 'active') {
            $this->error = 'The database server is not running.';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function databases(): array
    {
        return $this->overview['databases'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        return $this->overview['users'] ?? [];
    }

    private function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(fn () => $this->load());
    }

    /**
     * @return array<string, string>
     */
    private function userOptions(): array
    {
        return collect($this->localUsers())
            ->mapWithKeys(fn (array $user): array => [$user['user'] => $user['app'] ? "{$user['user']} (app {$user['app']})" : $user['user']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function databaseOptions(): array
    {
        return collect($this->databases())
            ->mapWithKeys(fn (array $database): array => [$database['name'] => $database['app'] ? "{$database['name']} (app {$database['app']})" : $database['name']])
            ->all();
    }

    /**
     * Users that sign in from this server, the only ones Mandal manages.
     *
     * @return list<array<string, mixed>>
     */
    private function localUsers(): array
    {
        return collect($this->users())->where('host', 'localhost')->values()->all();
    }

    /**
     * @param  array<mixed>  $values
     */
    private function repeat(string $option, array $values, string $pattern): string
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && preg_match($pattern, $value) === 1)
            ->map(fn (string $value): string => " {$option}={$value}")
            ->implode('');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function validName(array $arguments, string $pattern): ?string
    {
        $name = (string) ($arguments['name'] ?? '');

        return preg_match($pattern, $name) === 1 ? $name : null;
    }

    /**
     * @param  callable(MandalClient): CommandResult  $command
     */
    private function runSynchronously(callable $command, string $successTitle, string $failureTitle): void
    {
        try {
            $result = $command(app(MandalClient::class));
        } catch (SshException $exception) {
            Notification::make()->title($failureTitle)->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($result->failed()) {
            Notification::make()->title($failureTitle)->body(trim($result->errorOutput ?: $result->output))->danger()->persistent()->send();

            return;
        }

        app(MandalClient::class)->forgetInventory($this->server());
        Notification::make()->title($successTitle)->success()->send();
        $this->load();
    }
}
