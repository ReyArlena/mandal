<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A public page for players listing game servers: how to connect and,
 * optionally, whether each is running. It is served
 * at /p/{slug} without signing in, so the slug carries a random part
 * unless one is typed by hand.
 */
#[Fillable(['name', 'slug', 'title', 'intro', 'hide_branding', 'hide_credit'])]
class DetailsPage extends Model
{
    /**
     * Lowercase words joined by single dashes.
     */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hide_branding' => 'boolean',
            'hide_credit' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DetailsPageEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(DetailsPageEntry::class)->orderBy('sort');
    }

    /**
     * The slugged name with a random suffix, so the address cannot be guessed.
     */
    public static function generateSlug(?string $name): string
    {
        $base = substr(trim(Str::slug((string) $name), '-'), 0, 60);
        $base = rtrim($base, '-');

        return ($base === '' ? '' : $base.'-').Str::lower(Str::random(10));
    }

    public function url(): string
    {
        return route('details-pages.show', ['slug' => $this->slug]);
    }
}
