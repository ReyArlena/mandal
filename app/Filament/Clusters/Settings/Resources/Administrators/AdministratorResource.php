<?php

namespace App\Filament\Clusters\Settings\Resources\Administrators;

use App\Filament\Clusters\Settings\Resources\Administrators\Pages\ManageAdministrators;
use App\Filament\Clusters\Settings\Resources\Administrators\Schemas\AdministratorForm;
use App\Filament\Clusters\Settings\Resources\Administrators\Tables\AdministratorsTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * The accounts that can sign in to this panel, managed in modals on one page.
 */
class AdministratorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Administrators';

    protected static ?string $modelLabel = 'administrator';

    protected static ?string $slug = 'administrators';

    public static function form(Schema $schema): Schema
    {
        return AdministratorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdministratorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdministrators::route('/'),
        ];
    }
}
