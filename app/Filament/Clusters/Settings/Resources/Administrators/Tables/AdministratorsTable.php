<?php

namespace App\Filament\Clusters\Settings\Resources\Administrators\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdministratorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (User $record): ?string => $record->is(Filament::auth()->user()) ? 'You' : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->after(fn (User $record) => self::keepOwnSessionSignedIn($record)),
                DeleteAction::make()
                    ->modalDescription('They can no longer sign in. Tasks they started stay in the history without their name.'),
            ]);
    }

    /**
     * AuthenticateSession signs a session out when the account's password no
     * longer matches the hash stored in it, and Livewire requests do not pass
     * through that middleware to refresh it. Updating the guard's user and the
     * hash keeps you signed in here after changing your own password; your
     * other sessions end.
     */
    private static function keepOwnSessionSignedIn(User $record): void
    {
        if (! $record->is(Filament::auth()->user())) {
            return;
        }

        Filament::auth()->setUser($record);

        if (request()->hasSession()) {
            request()->session()->put('password_hash_'.Filament::getAuthGuard(), $record->getAuthPassword());
        }
    }
}
