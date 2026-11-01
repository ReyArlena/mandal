<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Tables;

use App\Models\DetailsPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DetailsPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Address')
                    ->formatStateUsing(fn (string $state): string => "/p/{$state}")
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyableState(fn (DetailsPage $record): string => $record->url())
                    ->copyMessage('Address copied'),
                TextColumn::make('entries_count')
                    ->label('Servers')
                    ->counts('entries'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No server details pages yet')
            ->emptyStateDescription('Create one to give players the addresses and passwords of your game servers.')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (DetailsPage $record): string => $record->url(), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
