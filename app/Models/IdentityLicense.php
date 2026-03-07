<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityLicense extends Model
{
    protected $fillable = [
        'sku_id',
        'sku_part_number',
        'display_name',
        'total',
        'consumed',
        'available',
        'applies_to',
        'capability_status',
    ];

    protected $casts = [
        'total'    => 'integer',
        'consumed' => 'integer',
        'available'=> 'integer',
    ];

    public function usagePercent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }
        return (int) round(($this->consumed / $this->total) * 100);
    }

    public function usageBarClass(): string
    {
        $pct = $this->usagePercent();
        if ($pct >= 90) return 'bg-danger';
        if ($pct >= 70) return 'bg-warning';
        return 'bg-success';
    }

    /**
     * Friendly display name mapped from Microsoft SKU part numbers.
     * Falls back to display_name (which is the SKU part number on first sync).
     *
     * @see https://learn.microsoft.com/en-us/entra/identity/users/licensing-service-plan-reference
     */
    public function friendlyName(): string
    {
        $map = [
            // ── Microsoft 365 / Office 365 ─────────────────────────
            'ENTERPRISEPACK'                    => 'Office 365 E3',
            'STANDARDPACK'                      => 'Office 365 E1',
            'SPE_E3'                            => 'Microsoft 365 E3',
            'SPE_E5'                            => 'Microsoft 365 E5',
            'SPB'                               => 'Microsoft 365 Business Premium',
            'O365_BUSINESS'                     => 'Microsoft 365 Apps for Business',
            'O365_BUSINESS_ESSENTIALS'          => 'Microsoft 365 Business Basic',
            'O365_BUSINESS_PREMIUM'             => 'Microsoft 365 Business Standard',
            // ── Exchange Online ─────────────────────────────────────
            'EXCHANGESTANDARD'                  => 'Exchange Online (Plan 1)',
            'EXCHANGEENTERPRISE'                => 'Exchange Online (Plan 2)',
            'EXCHANGE_S_DESKLESS'               => 'Exchange Online Kiosk',
            // ── Teams ───────────────────────────────────────────────
            'TEAMS_EXPLORATORY'                 => 'Teams Exploratory',
            'Microsoft_Teams_Exploratory_Dept'  => 'Microsoft Teams Exploratory',
            'Teams_Premium_(for_Departments)'   => 'Microsoft Teams Premium',
            'MCOSTANDARD'                       => 'Skype for Business Online (Plan 2)',
            // ── Enterprise Mobility + Security ──────────────────────
            'EMS'                               => 'Enterprise Mobility + Security E3',
            'EMSPREMIUM'                        => 'Enterprise Mobility + Security E5',
            // ── Power Platform ──────────────────────────────────────
            'FLOW_FREE'                         => 'Power Automate Free',
            'POWER_BI_PRO'                      => 'Power BI Pro',
            'POWER_BI_STANDARD'                 => 'Power BI (Free)',
            'POWERAPPS_DEV'                     => 'Power Apps Developer Plan',
            'POWERAPPS_VIRAL'                   => 'Power Apps Trial',
            // ── Defender / Security ─────────────────────────────────
            'MDATP_XPLAT'                       => 'Microsoft Defender for Endpoint P1',
            'WIN_DEF_ATP'                       => 'Microsoft Defender for Endpoint P2',
            'TVM_Premium_Standalone'            => 'Microsoft Defender Vulnerability Management',
            // ── Copilot ─────────────────────────────────────────────
            'Microsoft_365_Copilot'             => 'Microsoft 365 Copilot',
            // ── Project / Visio ─────────────────────────────────────
            'PROJECTPROFESSIONAL'               => 'Project Plan 3',
            'PROJECTPREMIUM'                    => 'Project Plan 5',
            'VISIOCLIENT'                       => 'Visio Plan 2',
            'VISIOONLINE_PLAN1'                 => 'Visio Plan 1',
            // ── Other ───────────────────────────────────────────────
            'CCIBOTS_PRIVPREV_VIRAL'            => 'CCI Bots Private Preview',
            'WINDOWS_STORE'                     => 'Microsoft Store for Business',
            'MCOMEETADV'                        => 'Microsoft 365 Audio Conferencing',
            'RIGHTSMANAGEMENT'                  => 'Azure Information Protection P1',
            'AAD_PREMIUM'                       => 'Azure AD Premium P1',
            'AAD_PREMIUM_P2'                    => 'Azure AD Premium P2',
            'INTUNE_A'                          => 'Microsoft Intune',
        ];

        return $map[$this->sku_part_number] ?? $this->display_name;
    }
}
