<?php

namespace App\Models;

use App\Enums\ServerSetupMode;
use App\Enums\TaskStatus;
use App\Ssh\KeyPair;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Fillable(['name', 'host', 'port', 'ssh_user', 'setup_mode'])]
#[Hidden(['private_key', 'setup_token'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * How long a freshly issued setup token stays valid.
     */
    public const int SETUP_TOKEN_HOURS = 24;

    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            if (blank($server->private_key)) {
                $pair = KeyPair::generate("mandal panel ({$server->name})");

                $server->private_key = $pair->privateKey;
                $server->public_key = $pair->publicKey;
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
            'port' => 'integer',
            'setup_mode' => ServerSetupMode::class,
            'private_key' => 'encrypted',
            'setup_token' => 'hashed',
            'setup_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Whether a task is queued or running on this server. Only one mandal
     * command runs per server at a time, so a second one must wait.
     */
    public function hasActiveTask(): bool
    {
        return $this->tasks()
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::Running])
            ->exists();
    }

    /**
     * The SHA-256 fingerprint of the server's pinned host key, if one is stored.
     */
    public function hostKeyFingerprint(): ?string
    {
        return $this->host_key === null ? null : KeyPair::fingerprint($this->host_key);
    }

    public function hasPinnedHostKey(): bool
    {
        return $this->host_key !== null;
    }

    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null;
    }

    /**
     * Issue a new single-use setup token and return its plain value.
     *
     * Only the hash is stored, so the plain token must be shown to the
     * user straight away.
     */
    public function issueSetupToken(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'setup_token' => $token,
            'setup_token_expires_at' => now()->addHours(self::SETUP_TOKEN_HOURS),
        ])->save();

        return $token;
    }

    public function setupTokenMatches(string $token): bool
    {
        if ($this->setup_token === null || $this->setup_token_expires_at === null) {
            return false;
        }

        if ($this->setup_token_expires_at->isPast()) {
            return false;
        }

        return Hash::check($token, $this->setup_token);
    }

    public function clearSetupToken(): void
    {
        $this->forceFill([
            'setup_token' => null,
            'setup_token_expires_at' => null,
        ])->save();
    }
}
