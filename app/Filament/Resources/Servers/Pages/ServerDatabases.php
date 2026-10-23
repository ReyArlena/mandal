<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\FormatsBytes;
use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\CommandResult;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The server's databases and database users: create them, choose who can
 * use which database, change passwords and delete what no app uses.
 */
class ServerDatabases extends Page
{
    use FormatsBytes;
    use InteractsWithRecord;

    /** Database names, as the CLI accepts them. */
    public const string DATABASE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    /** Database user names, as the CLI accepts them. */
    public const string USER_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Passwords without quotes, backslashes or whitespace, as the CLI accepts them. */
    public const string PASSWORD_PATTERN = '/^[A-Za-z0-9!#%+,.:=?@^_~-]{12,128}$/';

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $title = 'Databases';

    protected string $view = 'filament.servers.pages.databases';

    /** @var array<string, mixed> */
    public array $overview = [];

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

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

    /**
     * Local users with access to a database.
     *
     * @return list<string>
     */
    public function usersOf(string $database): array
    {
        return collect($this->localUsers())
            ->filter(fn (array $user): bool => in_array($database, $user['databases'] ?? [], true))
            ->pluck('user')
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load()),
            $this->createUserAction(),
            $this->createDatabaseAction(),
        ];
    }

    private function createDatabaseAction(): Action
    {
        return Action::make('createDatabase')
            ->label('New database')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Create a database')
            ->modalDescription('UTF-8 (utf8mb4) with the unicode collation, like the databases Mandal creates for apps.')
            ->modalSubmitActionLabel('Create database')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->regex(self::DATABASE_PATTERN)
                    ->notIn(fn (): array => collect($this->databases())->pluck('name')->all())
                    ->helperText('Up to 64 lower-case letters, digits or underscores.'),
                CheckboxList::make('users')
                    ->label('Users with access')
                    ->options(fn (): array => $this->userOptions()),
            ])
            ->action(function (array $data): void {
                $arguments = 'db:create --name='.$data['name'].$this->repeat('--user', $data['users'] ?? [], self::USER_PATTERN);

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), $arguments, timeoutSeconds: 60),
                    "Database {$data['name']} created",
                    'Could not create the database',
                );
            });
    }

    public function accessAction(): Action
    {
        return Action::make('access')
            ->label('Access')
            ->icon(Heroicon::OutlinedKey)
            ->link()
            ->modalHeading(fn (array $arguments): string => "Who can use {$arguments['name']}")
            ->modalDescription('Checked users get all privileges on this database; unchecked users lose them. An app always keeps access to its own database.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (array $arguments): array => ['users' => $this->usersOf((string) $arguments['name'])])
            ->schema([
                CheckboxList::make('users')
                    ->label('Users')
                    ->options(fn (): array => $this->userOptions()),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), 'db:access --name='.$name.$this->repeat('--user', $data['users'] ?? [], self::USER_PATTERN), timeoutSeconds: 60),
                    "Access to {$name} saved",
                    'Could not change who can use the database',
                );
            });
    }

    public function deleteDatabaseAction(): Action
    {
        return Action::make('deleteDatabase')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete database {$arguments['name']}?")
            ->modalDescription('Every table and row in it is removed, and users lose their access to it. Back it up first if you might need it again.')
            ->modalSubmitActionLabel('Delete database')
            ->action(function (array $arguments): void {
                $name = $this->validName($arguments, self::DATABASE_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), 'db:delete --name='.$name, timeoutSeconds: 120),
                    "Database {$name} deleted",
                    'Could not delete the database',
                );
            });
    }

    private function createUserAction(): Action
    {
        return Action::make('createUser')
            ->label('New user')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Create a database user')
            ->modalDescription('The user signs in from this server only (the database listens on 127.0.0.1). Copy the password before saving; it is not shown again.')
            ->modalSubmitActionLabel('Create user')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->regex(self::USER_PATTERN)
                    ->notIn(fn (): array => collect($this->users())->pluck('user')->all())
                    ->helperText('Up to 32 lower-case letters, digits or underscores, starting with a letter.'),
                $this->passwordField()->required(),
                CheckboxList::make('databases')
                    ->label('Databases')
                    ->options(fn (): array => $this->databaseOptions()),
            ])
            ->action(function (array $data): void {
                $arguments = 'db:user:create --name='.$data['name'].$this->repeat('--database', $data['databases'] ?? [], self::DATABASE_PATTERN);

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->runWithUpload($this->server(), $arguments, 'PASSWORD='.$data['password']."\n", '.conf', timeoutSeconds: 60),
                    "User {$data['name']} created",
                    'Could not create the user',
                );
            });
    }

    public function editUserAction(): Action
    {
        return Action::make('editUser')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->link()
            ->modalHeading(fn (array $arguments): string => "Edit {$arguments['name']}")
            ->modalDescription(fn (array $arguments): string => $this->appOfUser((string) $arguments['name'])
                ? 'This user belongs to app '.$this->appOfUser((string) $arguments['name']).'. It keeps access to the app\'s database, and its password lives in the app\'s .env.'
                : 'Checked databases get all privileges; unchecked ones lose them. Leave the password empty to keep the current one.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (array $arguments): array => [
                'databases' => collect($this->users())->firstWhere('user', $arguments['name'])['databases'] ?? [],
                'password' => null,
            ])
            ->schema(fn (array $arguments): array => [
                CheckboxList::make('databases')
                    ->label('Databases')
                    ->options(fn (): array => $this->databaseOptions()),
                $this->passwordField()
                    ->label('New password')
                    ->default(null)
                    ->helperText('Leave empty to keep the current password. 12 to 128 characters without quotes, backslashes or spaces.')
                    ->hidden(fn (): bool => $this->appOfUser((string) $arguments['name']) !== null),
            ])
            ->action(function (array $arguments, array $data): void {
                $name = $this->validName($arguments, self::USER_PATTERN);

                if ($name === null) {
                    return;
                }

                $command = 'db:user:update --name='.$name.$this->repeat('--database', $data['databases'] ?? [], self::DATABASE_PATTERN);
                $password = $data['password'] ?? null;

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => filled($password)
                        ? $mandal->runWithUpload($this->server(), $command, 'PASSWORD='.$password."\n", '.conf', timeoutSeconds: 60)
                        : $mandal->run($this->server(), $command, timeoutSeconds: 60),
                    "User {$name} saved",
                    'Could not save the user',
                );
            });
    }

    public function deleteUserAction(): Action
    {
        return Action::make('deleteUser')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Delete user {$arguments['name']}?")
            ->modalDescription('Anything signing in as this user stops working. Databases are not touched.')
            ->modalSubmitActionLabel('Delete user')
            ->action(function (array $arguments): void {
                $name = $this->validName($arguments, self::USER_PATTERN);

                if ($name === null) {
                    return;
                }

                $this->runSynchronously(
                    fn (MandalClient $mandal): CommandResult => $mandal->run($this->server(), 'db:user:delete --name='.$name, timeoutSeconds: 60),
                    "User {$name} deleted",
                    'Could not delete the user',
                );
            });
    }

    public function appOfUser(string $user): ?string
    {
        return collect($this->localUsers())->firstWhere('user', $user)['app'] ?? null;
    }

    private function passwordField(): TextInput
    {
        return TextInput::make('password')
            ->password()
            ->revealable()
            ->copyable()
            ->autocomplete('new-password')
            ->default(fn (): string => Str::password(32, symbols: false))
            ->regex(self::PASSWORD_PATTERN)
            ->helperText('12 to 128 characters without quotes, backslashes or spaces. A random one is filled in.');
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
