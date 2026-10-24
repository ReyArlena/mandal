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

#[Fillable(['name', 'host', 'port', 'ssh_user', 'setup_mode'])]
#[Hidden(['private_key'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

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
     * A one-word summary of how far setup has got.
     */
    public function status(): string
    {
        return match (true) {
            $this->isProvisioned() => 'Provisioned',
            $this->connected_at !== null => 'Connected',
            default => 'Not connected',
        };
    }

    /**
     * The Filament color that goes with status().
     */
    public function statusColor(): string
    {
        return match ($this->status()) {
            'Provisioned' => 'success',
            'Connected' => 'info',
            default => 'gray',
        };
    }

    /**
     * The command that sets the server up for this panel: it fetches
     * setup.sh from the latest release and passes it this panel's key.
     */
    public function setupCommand(): string
    {
        $repository = (string) config('mandal.repository');
        $key = escapeshellarg(KeyPair::normalize($this->public_key).' mandal-panel');
        $user = escapeshellarg($this->ssh_user);

        return "curl -fsSL https://github.com/{$repository}/releases/latest/download/setup.sh | sudo bash -s -- --user={$user} --key={$key}";
    }
}
