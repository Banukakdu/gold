<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'phone', 'is_main', 'status'];

    protected $casts = ['is_main' => 'boolean'];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_default')->withTimestamps();
    }

    public static function main(): self
    {
        return static::where('is_main', true)->firstOrFail();
    }
}
