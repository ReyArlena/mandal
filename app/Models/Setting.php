<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Panel-wide settings stored as key/value pairs.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const string PUBLIC_URL = 'public_url';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The address GitHub and other services reach this panel at, if any.
     */
    public static function publicUrl(): ?string
    {
        $url = static::get(self::PUBLIC_URL);

        return $url === null ? null : rtrim($url, '/');
    }
}
