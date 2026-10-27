<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An S3-compatible bucket backups can be uploaded to. The keys are
 * encrypted at rest and only leave the panel inside the file uploaded to
 * a server when a backup there starts using the destination.
 */
#[Fillable(['name', 'slug', 'endpoint', 'region', 'bucket', 'path', 'access_key', 'secret_key'])]
#[Hidden(['access_key', 'secret_key'])]
class BackupDestination extends Model
{
    /**
     * The CLI's remote name pattern; the slug is the `[section]` of the
     * rclone config on every server.
     */
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';

    protected static function booted(): void
    {
        // The slug names the remote on every server, so it is derived once
        // and never follows a rename.
        static::creating(function (BackupDestination $destination): void {
            if ($destination->slug === null || $destination->slug === '') {
                $destination->slug = static::slugFor($destination->name);
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
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
        ];
    }

    /**
     * A slug matching the CLI's pattern that no other destination uses.
     */
    public static function slugFor(string $name): string
    {
        $base = trim(Str::slug($name), '-');

        if (! preg_match('/^[a-z]/', $base)) {
            $base = 's3-'.$base;
        }

        $base = substr(rtrim($base, '-'), 0, 28) ?: 's3';

        if (strlen($base) < 2) {
            $base .= '-s3';
        }

        $slug = $base;

        for ($n = 2; static::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /**
     * Where files end up, for helper text: s3://bucket/prefix/subfolder/leaf/
     * with empty segments dropped.
     */
    public function remotePath(?string $subfolder, string $leaf): string
    {
        $segments = array_filter([
            trim((string) $this->path, '/'),
            trim((string) $subfolder, '/'),
            trim($leaf, '/'),
        ], fn (string $segment): bool => $segment !== '');

        return 's3://'.$this->bucket.'/'.implode('/', $segments).'/';
    }

    /**
     * The file `backup:remote --from=` reads.
     */
    public function remoteFile(): string
    {
        return implode("\n", [
            'LABEL='.$this->name,
            'ENDPOINT='.$this->endpoint,
            'REGION='.($this->region ?: 'auto'),
            'BUCKET='.$this->bucket,
            'PATH='.trim((string) $this->path, '/'),
            'KEY='.$this->access_key,
            'SECRET='.$this->secret_key,
        ])."\n";
    }
}
