<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** AG-092 R1: the typed reader (App\Support\Settings) memoises per request; any write flushes it. */
    protected static function booted(): void
    {
        static::saved(fn () => app(Settings::class)->flush());
        static::deleted(fn () => app(Settings::class)->flush());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
