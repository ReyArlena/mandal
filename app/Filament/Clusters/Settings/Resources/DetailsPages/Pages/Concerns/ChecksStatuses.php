<?php

namespace App\Filament\Clusters\Settings\Resources\DetailsPages\Pages\Concerns;

use App\DetailsPages\GameStatuses;
use App\Models\DetailsPage;
use App\Models\DetailsPageEntry;

trait ChecksStatuses
{
    /**
     * Newly chosen containers have no status yet; read it from each
     * server's inventory (usually still cached from the container list)
     * right away.
     */
    protected function checkNewLinks(): void
    {
        /** @var DetailsPage $page */
        $page = $this->getRecord();

        $page->entries()
            ->where('status_source', DetailsPageEntry::SOURCE_CONTAINER)
            ->whereNotNull('server_id')
            ->whereNull('status')
            ->with('server')
            ->get()
            ->map(fn (DetailsPageEntry $entry) => $entry->server)
            ->filter()
            ->unique('id')
            ->each(fn ($server) => app(GameStatuses::class)->check($server));
    }
}
