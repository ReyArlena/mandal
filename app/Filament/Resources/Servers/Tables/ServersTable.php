<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Mandal\CliArchive;
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
                    ->state(fn (Server $record): string => $record->status())
                    ->badge()
                    ->color(fn (Server $record): string => $record->statusColor()),
                TextColumn::make('cli_build')
                    ->label('CLI')
                    ->state(fn (Server $record): ?string => match (true) {
                        $record->cli_build === null => null,
                        $record->cliOutdated(app(CliArchive::class)->hash()) => 'Update required',
                        default => 'Current',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Current' ? 'success' : 'warning')
                    ->placeholder(''),
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
