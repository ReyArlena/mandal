<?php

namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One game server on a details page. Its status is hidden, set by hand,
 * or follows a Docker container on one of the panel's servers; for a
 * container the panel stores its state whenever it reads that server's
 * inventory, and visitors only ever see the stored value.
 */
#[Fillable(['name', 'version', 'address', 'password', 'status_source', 'status', 'server_id', 'container', 'sort'])]
class DetailsPageEntry extends Model
{
    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_CONTAINER = 'container';

    protected static function booted(): void
    {
        // A container that has just been chosen has not been checked yet.
        static::saving(function (DetailsPageEntry $entry): void {
            if ($entry->followsContainer() && $entry->isDirty(['status_source', 'server_id', 'container'])) {
                $entry->status = null;
                $entry->status_checked_at = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'status_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DetailsPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(DetailsPage::class, 'details_page_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function showsStatus(): bool
    {
        return $this->status_source === self::SOURCE_MANUAL || $this->followsContainer();
    }

    public function followsContainer(): bool
    {
        return $this->status_source === self::SOURCE_CONTAINER && $this->server_id !== null && filled($this->container);
    }

    /**
     * The stored status, or unknown until the panel has checked it.
     */
    public function currentStatus(): GameStatus
    {
        return $this->status ?? GameStatus::Unknown;
    }
}
