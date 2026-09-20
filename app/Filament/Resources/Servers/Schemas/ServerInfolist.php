<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ServerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->schema([
                        TextEntry::make('host')
                            ->formatStateUsing(fn (Server $record): string => "{$record->ssh_user}@{$record->host}:{$record->port}")
                            ->label('SSH')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('setup_mode')
                            ->label('Mode')
                            ->badge(),
                        TextEntry::make('host_key_fingerprint')
                            ->label('Host key')
                            ->state(fn (Server $record): ?string => $record->hostKeyFingerprint())
                            ->placeholder('Not confirmed yet')
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('connected_at')
                            ->label('Last connected')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('provisioned_at')
                            ->label('Provisioned')
                            ->dateTime()
                            ->placeholder('Not provisioned'),
                    ])
                    ->columns(2),
                Section::make('Panel public key')
                    ->description('Add this key to the server so Mandal can sign in. A new server set up with the setup command gets it automatically.')
                    ->schema([
                        TextEntry::make('public_key')
                            ->hiddenLabel()
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
