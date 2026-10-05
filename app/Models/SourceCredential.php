<?php

namespace App\Models;

use App\Enums\SourceProviderType;
use Database\Factories\SourceCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A token for a source provider such as GitHub, encrypted at rest.
 */
#[Fillable(['provider', 'name', 'token', 'account', 'expires_at'])]
#[Hidden(['token'])]
class SourceCredential extends Model
{
    /** @use HasFactory<SourceCredentialFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SourceProviderType::class,
            'token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public static function forProvider(SourceProviderType $provider): ?self
    {
        return static::query()->where('provider', $provider)->orderBy('id')->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresSoon(): bool
    {
        return $this->expires_at !== null && ! $this->isExpired() && $this->expires_at->lt(now()->addDays(14));
    }
}
