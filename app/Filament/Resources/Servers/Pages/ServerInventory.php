<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Everything on the server, read live through the mandal CLI.
 */
class ServerInventory extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $title = 'Inventory';

    protected string $view = 'filament.servers.pages.inventory';

    /** @var array<string, mixed>|null */
    public ?array $inventory = null;

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->loadInventory();
    }

    public function loadInventory(bool $fresh = false): void
    {
        $this->error = null;

        /** @var Server $server */
        $server = $this->getRecord();

        if ($server->connected_at === null) {
            $this->inventory = null;
            $this->error = 'This server has not been connected yet. Use "Test connection" on the server page first.';

            return;
        }

        try {
            $this->inventory = app(MandalClient::class)->inventory($server, $fresh);
        } catch (SshException $exception) {
            $this->inventory = null;
            $this->error = $exception->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->loadInventory(fresh: true)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        $section = $this->inventory[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    public function bytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return rtrim(rtrim(number_format($bytes, $unit === 'B' ? 0 : 1, '.', ''), '0'), '.').' '.$unit;
            }

            $bytes /= 1024;
        }

        return '0 B';
    }

    public function duration(?int $seconds): string
    {
        $seconds = (int) ($seconds ?? 0);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }
}
