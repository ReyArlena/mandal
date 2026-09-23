<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A long-running job the panel started on a server, with its live output.
 */
#[Fillable(['type', 'status', 'options', 'output', 'user_id'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Output is capped so a runaway command cannot grow the row without bound.
     */
    public const int MAX_OUTPUT_BYTES = 2_000_000;

    protected $attributes = [
        'output' => '',
        'status' => TaskStatus::Pending,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'options' => 'array',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => TaskStatus::Running, 'started_at' => now()])->save();
    }

    public function markFinished(int $exitCode): void
    {
        $this->forceFill([
            'status' => $exitCode === 0 ? TaskStatus::Succeeded : TaskStatus::Failed,
            'exit_code' => $exitCode,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Append a chunk of output without re-saving the rest of the row.
     */
    public function appendOutput(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->output = mb_strcut($this->output.$chunk, 0, self::MAX_OUTPUT_BYTES);

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update(['output' => $this->output, 'updated_at' => now()]);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }
}
