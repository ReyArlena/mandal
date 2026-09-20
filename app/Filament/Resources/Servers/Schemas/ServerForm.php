<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Enums\ServerSetupMode;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerForm
{
    public const string HOST_RULE = 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$|^(?:\d{1,3}\.){3}\d{1,3}$|^[0-9a-fA-F:]+$/';

    public const string USER_RULE = 'regex:/^[a-z_][a-z0-9_-]{0,31}$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Server')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->placeholder('web-1'),
                        TextInput::make('host')
                            ->label('Host name or IP address')
                            ->required()
                            ->rule(self::HOST_RULE)
                            ->placeholder('203.0.113.10'),
                        TextInput::make('port')
                            ->label('SSH port')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(22)
                            ->required(),
                        TextInput::make('ssh_user')
                            ->label('SSH user')
                            ->default('mandal')
                            ->required()
                            ->rule(self::USER_RULE)
                            ->helperText('The user Mandal connects as. For a new server this is created for you; for an attached server it must already exist and have sudo.'),
                    ])
                    ->columns(2),
                Section::make('How to connect')
                    ->schema([
                        Radio::make('setup_mode')
                            ->hiddenLabel()
                            ->options(ServerSetupMode::class)
                            ->default(ServerSetupMode::Fresh)
                            ->required()
                            ->disabledOn('edit'),
                    ]),
            ]);
    }
}
