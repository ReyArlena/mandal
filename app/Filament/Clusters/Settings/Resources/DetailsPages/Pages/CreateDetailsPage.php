<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Pages;

use App\Filament\Clusters\Settings\Resources\DetailsPages\DetailsPageResource;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\Concerns\ChecksStatuses;
use Filament\Resources\Pages\CreateRecord;

class CreateDetailsPage extends CreateRecord
{
    use ChecksStatuses;

    protected static string $resource = DetailsPageResource::class;

    protected function afterCreate(): void
    {
        $this->checkNewLinks();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
