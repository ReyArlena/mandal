<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Models\Server;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('host')
                    ->searchable()
                    ->formatStateUsing(fn (Server $record): string => $record->port === 22 ? $record->host : "{$record->host}:{$record->port}"),
                TextColumn::make('ssh_user')
                    ->label('User'),
                TextColumn::make('setup_mode')
                    ->label('Mode')
                    ->badge(),
                TextColumn::make('status')
                    ->state(fn (Server $record): string => match (true) {
                        $record->isProvisioned() => 'Provisioned',
                        $record->connected_at !== null => 'Connected',
                        default => 'Not connected',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Provisioned' => 'success',
                        'Connected' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('connected_at')
                    ->label('Last connected')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
