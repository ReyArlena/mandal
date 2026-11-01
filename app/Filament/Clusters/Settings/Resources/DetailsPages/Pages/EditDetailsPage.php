<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Pages;

use App\Filament\Clusters\Settings\Resources\DetailsPages\DetailsPageResource;
use App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\Concerns\ChecksStatuses;
use App\Models\DetailsPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditDetailsPage extends EditRecord
{
    use ChecksStatuses;

    protected static string $resource = DetailsPageResource::class;

    protected function afterSave(): void
    {
        $this->checkNewLinks();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open page')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (DetailsPage $record): string => $record->url(), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
