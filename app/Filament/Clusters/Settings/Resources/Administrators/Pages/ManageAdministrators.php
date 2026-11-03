<?php

namespace App\Filament\Clusters\Settings\Resources\Administrators\Pages;

use App\Filament\Clusters\Settings\Concerns\HasSettingsHeading;
use App\Filament\Clusters\Settings\Resources\Administrators\AdministratorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAdministrators extends ManageRecords
{
    use HasSettingsHeading;

    protected static string $resource = AdministratorResource::class;

    public static function getPageDescription(): string
    {
        return 'Everyone here has full access to this panel and its servers, and sets up two-factor authentication the first time they sign in. You cannot delete your own account.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New administrator'),
        ];
    }
}
