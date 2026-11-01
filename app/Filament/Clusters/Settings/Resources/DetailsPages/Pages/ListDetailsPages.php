<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Pages;

use App\Filament\Clusters\Settings\Resources\DetailsPages\DetailsPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDetailsPages extends ListRecords
{
    protected static string $resource = DetailsPageResource::class;

    public static function getPageDescription(): string
    {
        return 'Public pages for players with how to connect to your game servers and whether they are running. They need no sign-in, so share the address only with your players.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New page'),
        ];
    }
}
