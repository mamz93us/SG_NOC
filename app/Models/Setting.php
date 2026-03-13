<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'company_name',
        'company_logo',
        'sso_enabled',
        'sso_tenant_id',
        'sso_client_id',
        'sso_client_secret',
        'sso_default_role',
        // Meraki Network
        'meraki_enabled',
        'meraki_api_key',
        'meraki_org_id',
        'meraki_polling_interval',
        // Sophos Firewall
        'sophos_sync_enabled',
        'sophos_sync_interval',
        // Microsoft Graph / Identity
        'graph_tenant_id',
        'graph_client_id',
        'graph_client_secret',
        'graph_default_password',
        'graph_default_license_sku',
        'graph_default_license_skus',
        'identity_sync_enabled',
        'identity_sync_interval',
        'gdms_sync_interval',
        // SMTP / Notifications
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
        'snmp_alert_email',
        // Provisioning / UCM
        'upn_domain',
        'default_ucm_id',
        'ext_range_start',
        'ext_range_end',
        'ext_default_secret',
        'ext_default_permission',
        'profile_office_template',
        'profile_phone_template',
        // GDMS API
        'gdms_base_url',
        'gdms_client_id',
        'gdms_client_secret',
        'gdms_org_id',
        'gdms_username',
        'gdms_password_hash',
        // ITAM
        'itam_asset_prefix',
        'itam_code_padding',
        'itam_company_url',
    ];

    protected $casts = [
        'sso_enabled'             => 'boolean',
        'meraki_enabled'          => 'boolean',
        'meraki_polling_interval' => 'integer',
        'sophos_sync_enabled'     => 'boolean',
        'sophos_sync_interval'    => 'integer',
        'identity_sync_enabled'   => 'boolean',
        'identity_sync_interval'  => 'integer',
        'gdms_sync_interval'      => 'integer',
        'default_ucm_id'          => 'integer',
        'ext_range_start'              => 'integer',
        'ext_range_end'                => 'integer',
        'graph_default_license_skus'   => 'array',
        'itam_code_padding'            => 'integer',
    ];

    /**
     * Get the singleton settings record
     */
    public static function get(): static
    {
        return static::first() ?? static::create([
            'company_name'     => 'Company Name',
            'company_logo'     => null,
            'sso_enabled'      => false,
            'sso_default_role' => 'viewer',
        ]);
    }

    // ─── SSO client secret — encrypted at rest ────────────────────

    public function setSsoClientSecretAttribute(?string $value): void
    {
        $this->attributes['sso_client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getSsoClientSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ─── Meraki API key — encrypted at rest ──────────────────────

    public function setMerakiApiKeyAttribute(?string $value): void
    {
        $this->attributes['meraki_api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getMerakiApiKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ─── Graph client secret — encrypted at rest ─────────────────

    public function setGraphClientSecretAttribute(?string $value): void
    {
        $this->attributes['graph_client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getGraphClientSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ─── SMTP password — encrypted at rest ────────────────────────

    public function setSmtpPasswordAttribute(?string $value): void
    {
        $this->attributes['smtp_password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getSmtpPasswordAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ─── GDMS client secret — encrypted at rest ───────────────────

    public function setGdmsClientSecretAttribute(?string $value): void
    {
        $this->attributes['gdms_client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getGdmsClientSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }
}
