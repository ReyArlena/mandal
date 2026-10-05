<?php

namespace App\Models;

use App\Enums\SourceProviderType;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An inbound webhook that deploys one app on push. The token identifies
 * the hook in the URL; the secret signs every delivery.
 */
#[Fillable(['server_id', 'app', 'provider', 'repo', 'token', 'secret', 'remote_id'])]
#[Hidden(['secret', 'token'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Webhook $webhook): void {
            $webhook->token ??= Str::random(48);
            $webhook->secret ??= Str::random(48);
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
            'provider' => SourceProviderType::class,
            'secret' => 'encrypted',
            'last_delivery_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function url(): ?string
    {
        $base = Setting::publicUrl();

        return $base === null ? null : "{$base}/webhooks/{$this->provider->value}/{$this->token}";
    }

    public function recordDelivery(string $status): void
    {
        $this->forceFill(['last_delivery_at' => now(), 'last_delivery_status' => $status])->save();
    }
}
