<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AssetType extends Model
{
    protected $fillable = [
        'slug', 'label', 'icon', 'badge_class', 'category_code',
        'is_user_equipment', 'group', 'sort_order',
    ];

    protected $casts = [
        'is_user_equipment' => 'boolean',
        'sort_order'        => 'integer',
    ];

    // ─── Cached Helpers ──────────────────────────────────────────

    /** Get all types, cached for 1 hour */
    public static function cached(): \Illuminate\Support\Collection
    {
        return Cache::remember('asset_types_all', 3600, function () {
            return self::orderBy('sort_order')->get();
        });
    }

    /** Clear cache (call after any CRUD) */
    public static function clearCache(): void
    {
        Cache::forget('asset_types_all');
    }

    /** Get slugs of user equipment types */
    public static function userEquipmentSlugs(): array
    {
        return self::cached()->where('is_user_equipment', true)->pluck('slug')->toArray();
    }

    /** Get all slugs for validation */
    public static function allSlugs(): array
    {
        return self::cached()->pluck('slug')->toArray();
    }

    /** Find by slug from cache */
    public static function findBySlug(string $slug): ?self
    {
        return self::cached()->firstWhere('slug', $slug);
    }

    /** Get types grouped by group field */
    public static function grouped(): \Illuminate\Support\Collection
    {
        return self::cached()->groupBy('group');
    }

    /** Get options for filter dropdown: slug => label */
    public static function dropdownOptions(): array
    {
        return self::cached()->pluck('label', 'slug')->toArray();
    }
}
