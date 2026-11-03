<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages;

use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\CreateDetailsPage;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\EditDetailsPage;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\ListDetailsPages;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Schemas\DetailsPageForm;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Tables\DetailsPagesTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\DetailsPage;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Public pages listing game servers for players, at /p/{slug}.
 */
class DetailsPageResource extends Resource
{
    protected static ?string $model = DetailsPage::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Server details pages';

    protected static ?string $modelLabel = 'server details page';

    protected static ?string $slug = 'details-pages';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DetailsPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DetailsPagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDetailsPages::route('/'),
            'create' => CreateDetailsPage::route('/create'),
            'edit' => EditDetailsPage::route('/{record}/edit'),
        ];
    }
}
