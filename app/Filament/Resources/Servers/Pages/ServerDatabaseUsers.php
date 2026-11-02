<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\HasDatabaseSidebar;
use App\Filament\Resources\Servers\Concerns\InteractsWithDatabases;
use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Ssh\CommandResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The server's database users: create them, choose their databases, change
 * passwords and delete what no app uses.
 */
class ServerDatabaseUsers extends Page
{
    use HasDatabaseSidebar, InteractsWithRecord {
        HasDatabaseSidebar::getSubNavigation insteadof InteractsWithRecord;
    }
    use InteractsWithDatabases;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $title = 'Users';

    protected string $view = 'filament.servers.pages.database-users';

    public static function getPageDescription(): string
    {
        return 'Users sign in from this server only and get all privileges on their databases.';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->load();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
            $this->createUserAction(),
        ];
    }

    private function createUserAction(): Action
    {
        return Action::make('createUser')
            ->label('New user')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn (): bool => $this->error === null)
            ->modalHeading('Create a database user')
            ->modalDescription('The user signs in from this server only (the database listens on 127.0.0.1). Copy the password before saving; it is not shown again.')
            ->modalSubmitActionLabel('Create user')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->regex(ServerDatabases::USER_PATTERN)
                    ->notIn(fn (): array => collect($this->users())->pluck('user')->all())
                    ->helperText('Up to 32 lower-case letters, digits or underscores, starting with a letter.'),
                $this->passwordField()->required(),
                CheckboxList::make('databases')
                    ->label('Databases')
                    ->options(fn (): array => $this->databaseOptions()),
            ])
            ->action(function (array $data): void {
                $arguments = 'db:user:create --name='.$data['name'].$this->repeat('--database', $data['databases'] ?? [], ServerDatabases::DATABASE_PATTERN);

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
                $name = $this->validName($arguments, ServerDatabases::USER_PATTERN);

                if ($name === null) {
                    return;
                }

                $command = 'db:user:update --name='.$name.$this->repeat('--database', $data['databases'] ?? [], ServerDatabases::DATABASE_PATTERN);
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
                $name = $this->validName($arguments, ServerDatabases::USER_PATTERN);

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
            ->regex(ServerDatabases::PASSWORD_PATTERN)
            ->helperText('12 to 128 characters without quotes, backslashes or spaces. A random one is filled in.');
    }
}
