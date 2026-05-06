<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BrowserPortalSettings extends Model
{
    protected $table = 'browser_portal_settings';

    protected $fillable = [
        'idle_minutes',
        'max_concurrent_sessions',
        'udp_port_range_start',
        'udp_port_range_end',
        'ports_per_session',
        'neko_image',
        'desktop_resolution',
        'auto_request_control',
        'hide_neko_branding',
    ];

    protected $casts = [
        'idle_minutes'             => 'int',
        'max_concurrent_sessions'  => 'int',
        'udp_port_range_start'     => 'int',
        'udp_port_range_end'       => 'int',
        'ports_per_session'        => 'int',
        'auto_request_control'     => 'bool',
        'hide_neko_branding'       => 'bool',
    ];

    public static function current(): self
    {
        return Cache::remember('browser-portal:settings', 60, function () {
            return static::firstOrCreate([], [
                'idle_minutes'            => (int) env('BROWSER_PORTAL_IDLE_MINUTES', 240),
                'max_concurrent_sessions' => 10,
                'udp_port_range_start'    => 52000,
                'udp_port_range_end'      => 52100,
                'ports_per_session'       => 10,
                'neko_image'              => 'ghcr.io/m1k1o/neko/chromium:latest',
                'desktop_resolution'      => '1920x1080@30',
                'auto_request_control'    => true,
                'hide_neko_branding'      => true,
            ]);
        });
    }

    public static function invalidateCache(): void
    {
        Cache::forget('browser-portal:settings');
    }

    protected static function booted(): void
    {
        static::saved(fn() => static::invalidateCache());
        static::deleted(fn() => static::invalidateCache());
    }
}
