<?php

namespace App\Filament\Resources\Servers;

use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ServerApps;
use App\Filament\Resources\Servers\Pages\ServerInventory;
use App\Filament\Resources\Servers\Pages\ServerTasks;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\Pages\ViewTask;
use App\Filament\Resources\Servers\Schemas\ServerForm;
use App\Filament\Resources\Servers\Schemas\ServerInfolist;
use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Models\Server;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return ServerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServersTable::configure($table);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewServer::class,
            ServerApps::class,
            ServerInventory::class,
            ServerTasks::class,
            EditServer::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'view' => ViewServer::route('/{record}'),
            'apps' => ServerApps::route('/{record}/apps'),
            'inventory' => ServerInventory::route('/{record}/inventory'),
            'tasks' => ServerTasks::route('/{record}/tasks'),
            'task' => ViewTask::route('/{record}/tasks/{task}'),
            'edit' => EditServer::route('/{record}/edit'),
        ];
    }
}
