<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AllowedDomain extends Model
{
    protected $fillable = ['domain', 'description', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Get flat list of allowed domain strings (cached 5 min).
     */
    public static function getList(): array
    {
        return Cache::remember('allowed_domains_list', 300, function () {
            return static::pluck('domain')->all();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('allowed_domains_list');
    }
}
