<?php

namespace App\Models\OraclePortal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Config for the Samir Employee Portal API (Oracle HR).
 *
 * Same singleton + encrypted-key pattern as {@see \App\Models\AiSetting},
 * split into its own table because `settings` has no room left.
 *
 * `enabled` is the master switch; the three `sync_*` flags are per feed, so
 * one misbehaving feed can be stopped without taking the others down.
 */
class PortalSetting extends Model
{
    protected $table = 'oracle_portal_settings';

    protected $fillable = [
        'enabled',
        'base_url',
        'api_key',
        'sync_announcements',
        'sync_employees',
        'sync_vacations',
        'last_announcements_sync_at',
        'last_employees_sync_at',
        'last_vacations_sync_at',
        'last_announcements_count',
        'last_employees_count',
        'last_vacation_balances_count',
        'last_vacation_records_count',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sync_announcements' => 'boolean',
        'sync_employees' => 'boolean',
        'sync_vacations' => 'boolean',
        'last_announcements_sync_at' => 'datetime',
        'last_employees_sync_at' => 'datetime',
        'last_vacations_sync_at' => 'datetime',
        'last_announcements_count' => 'integer',
        'last_employees_count' => 'integer',
        'last_vacation_balances_count' => 'integer',
        'last_vacation_records_count' => 'integer',
    ];

    public static function get(): static
    {
        return static::first() ?? static::create([
            'enabled' => false,
            'base_url' => 'https://sgprd.samirgroup.com/EmployeePortal/api',
        ]);
    }

    // ─── API key — encrypted at rest ──────────────────────────────

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Can we call the API at all right now?
     *
     * Used to hide buttons rather than ship ones that 503, and checked again
     * at the top of every scheduled command.
     */
    public function isConfigured(): bool
    {
        return $this->configurationIssue() === null;
    }

    /** Why we cannot call it, in a sentence, or null when we can. */
    public function configurationIssue(): ?string
    {
        if (! $this->enabled) {
            return 'The Oracle Employee Portal API is switched off in Admin → Settings.';
        }

        if (blank($this->base_url)) {
            return 'No Employee Portal API base URL is configured.';
        }

        if (blank($this->api_key)) {
            return 'No Employee Portal API key is configured.';
        }

        return null;
    }

    /** The base URL with any trailing slash removed, or null. */
    public function baseUrl(): ?string
    {
        $url = rtrim(trim((string) $this->base_url), '/');

        return $url === '' ? null : $url;
    }
}
