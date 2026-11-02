<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Pages;

use App\DetailsPages\DetailsPagesTransfer;
use App\Filament\Clusters\Settings\Resources\DetailsPages\DetailsPageResource;
use App\Models\DetailsPage;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $this->importAction(),
            $this->exportAction(),
            CreateAction::make()->label('New page'),
        ];
    }

    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->disabled(fn (): bool => ! DetailsPage::query()->exists())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export every server details page')
            ->modalDescription('A JSON file with each page and its game servers, including their passwords, so keep it private. Which Docker container a status follows is left out, since containers differ between servers; pick it again after importing.')
            ->modalSubmitActionLabel('Download')
            ->action(function (DetailsPagesTransfer $transfer): StreamedResponse {
                $json = json_encode($transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                return response()->streamDownload(fn () => print ($json), 'details-pages-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
            });
    }

    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->modalHeading('Import server details pages')
            ->modalDescription('Adds the pages in an export from Mandal, keeping their addresses. Existing pages are never changed: a page whose address is already in use here is skipped. Statuses that followed a Docker container need the container picked again.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('Export file')
                    ->required()
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(1024),
            ])
            ->action(function (array $data, DetailsPagesTransfer $transfer): void {
                $path = (string) $data['file'];

                try {
                    $result = $transfer->import((string) Storage::disk('local')->get($path));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Could not import the pages')->body(collect($exception->errors())->flatten()->first())->danger()->persistent()->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($path);
                }

                $created = count($result['created']);
                $notification = Notification::make()
                    ->title($created === 1 ? 'Imported 1 page' : "Imported {$created} pages")
                    ->success();

                if ($result['skipped'] !== []) {
                    $notification->body('Skipped, address already in use: '.implode(', ', $result['skipped']).'.')->warning();
                }

                $notification->send();
            });
    }
}
