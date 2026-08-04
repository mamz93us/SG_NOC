<?php

namespace App\Support;

/**
 * Friendly product names for Microsoft 365 / Entra licence SKUs.
 *
 * Graph only returns `skuPartNumber` — strings like SPE_E3, EMS,
 * O365_BUSINESS_PREMIUM — which are meaningless to anyone who does not already
 * know them. Microsoft publishes the mapping to the names shown in the admin
 * centre; the ones this tenant is likely to hold are inlined below.
 *
 * Unknown SKUs fall back to a tidied version of the part number rather than
 * being dropped, so a new subscription still reads sensibly before anyone
 * updates this list.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity/users/licensing-service-plan-reference
 */
class MicrosoftSkuNames
{
    /**
     * skuPartNumber => product name as shown in the Microsoft 365 admin centre.
     *
     * @var array<string, string>
     */
    public const NAMES = [
        // ── Microsoft 365 / Office 365 suites ──────────────────────
        'SPE_E3' => 'Microsoft 365 E3',
        'SPE_E5' => 'Microsoft 365 E5',
        'SPE_F1' => 'Microsoft 365 F3',
        'SPE_E3_RPA1' => 'Microsoft 365 E3 (with Power Automate)',
        'M365_F1' => 'Microsoft 365 F1',
        'M365_F1_COMM' => 'Microsoft 365 F1',
        'O365_BUSINESS' => 'Microsoft 365 Apps for Business',
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'SMB_BUSINESS' => 'Microsoft 365 Apps for Business',
        'SMB_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'SMB_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'SPB' => 'Microsoft 365 Business Premium',
        'STANDARDPACK' => 'Office 365 E1',
        'ENTERPRISEPACK' => 'Office 365 E3',
        'ENTERPRISEPREMIUM' => 'Office 365 E5',
        'ENTERPRISEPREMIUM_NOPSTNCONF' => 'Office 365 E5 (without Audio Conferencing)',
        'DESKLESSPACK' => 'Office 365 F3',
        'OFFICESUBSCRIPTION' => 'Microsoft 365 Apps for Enterprise',
        'OFFICE365_MULTIGEO' => 'Microsoft 365 Multi-Geo',

        // ── Exchange / Teams / SharePoint standalone ───────────────
        'EXCHANGESTANDARD' => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE' => 'Exchange Online (Plan 2)',
        'EXCHANGEDESKLESS' => 'Exchange Online Kiosk',
        'EXCHANGEESSENTIALS' => 'Exchange Online Essentials',
        'EXCHANGE_S_ESSENTIALS' => 'Exchange Online Essentials',
        'EXCHANGEARCHIVE' => 'Exchange Online Archiving',
        'EXCHANGEARCHIVE_ADDON' => 'Exchange Online Archiving for Exchange Online',
        'SHAREPOINTSTANDARD' => 'SharePoint Online (Plan 1)',
        'SHAREPOINTENTERPRISE' => 'SharePoint Online (Plan 2)',
        'SHAREPOINTSTORAGE' => 'Office 365 Extra File Storage',
        'MCOSTANDARD' => 'Skype for Business Online (Plan 2)',
        'MCOMEETADV' => 'Microsoft 365 Audio Conferencing',
        'MCOEV' => 'Microsoft Teams Phone Standard',
        'MCOPSTN1' => 'Microsoft 365 Domestic Calling Plan',
        'MCOPSTN2' => 'Microsoft 365 Domestic and International Calling Plan',
        'MCOPSTNC' => 'Communications Credits',
        'PHONESYSTEM_VIRTUALUSER' => 'Microsoft Teams Phone Resource Account',
        'Microsoft_Teams_Rooms_Basic' => 'Microsoft Teams Rooms Basic',
        'Microsoft_Teams_Rooms_Pro' => 'Microsoft Teams Rooms Pro',
        'TEAMS_EXPLORATORY' => 'Microsoft Teams Exploratory',
        'Teams_Premium_(for_Departments)' => 'Microsoft Teams Premium',
        'MEETING_ROOM' => 'Microsoft Teams Rooms Standard',

        // ── Entra ID / security / compliance ───────────────────────
        'AAD_PREMIUM' => 'Microsoft Entra ID P1',
        'AAD_PREMIUM_P2' => 'Microsoft Entra ID P2',
        'AAD_BASIC' => 'Microsoft Entra ID Basic',
        'EMS' => 'Enterprise Mobility + Security E3',
        'EMSPREMIUM' => 'Enterprise Mobility + Security E5',
        'INTUNE_A' => 'Microsoft Intune',
        'INTUNE_A_D' => 'Microsoft Intune Plan 1 (Device)',
        'INTUNE_SMB' => 'Microsoft Intune SMB',
        'Microsoft_Intune_Suite' => 'Microsoft Intune Suite',
        'ATP_ENTERPRISE' => 'Microsoft Defender for Office 365 (Plan 1)',
        'THREAT_INTELLIGENCE' => 'Microsoft Defender for Office 365 (Plan 2)',
        'ATA' => 'Microsoft Defender for Identity',
        'IDENTITY_THREAT_PROTECTION' => 'Microsoft 365 E5 Security',
        'INFORMATION_PROTECTION_COMPLIANCE' => 'Microsoft 365 E5 Compliance',
        'RIGHTSMANAGEMENT' => 'Azure Information Protection Plan 1',
        'MDATP_XPLAT' => 'Microsoft Defender for Endpoint',
        'WIN_DEF_ATP' => 'Microsoft Defender for Endpoint',
        'DEFENDER_ENDPOINT_P1' => 'Microsoft Defender for Endpoint Plan 1',

        // ── Power Platform / Dynamics / other ──────────────────────
        'POWER_BI_STANDARD' => 'Power BI (free)',
        'POWER_BI_PRO' => 'Power BI Pro',
        'PBI_PREMIUM_PER_USER' => 'Power BI Premium Per User',
        'FLOW_FREE' => 'Microsoft Power Automate Free',
        'POWERAPPS_VIRAL' => 'Microsoft Power Apps Plan 2 Trial',
        'POWERAPPS_PER_USER' => 'Power Apps Premium',
        'PROJECTPROFESSIONAL' => 'Project Plan 3',
        'PROJECTPREMIUM' => 'Project Plan 5',
        'PROJECTESSENTIALS' => 'Project Online Essentials',
        'PROJECT_P1' => 'Project Plan 1',
        'VISIOCLIENT' => 'Visio Plan 2',
        'VISIO_PLAN1_DEPT' => 'Visio Plan 1',
        'VISIOONLINE_PLAN1' => 'Visio Plan 1',
        'DYN365_ENTERPRISE_SALES' => 'Dynamics 365 Sales Enterprise',
        'DYN365_ENTERPRISE_CUSTOMER_SERVICE' => 'Dynamics 365 Customer Service Enterprise',
        'DYN365_BUSCENTRAL_ESSENTIAL' => 'Dynamics 365 Business Central Essentials',
        'WINDOWS_STORE' => 'Windows Store for Business',
        'WIN10_PRO_ENT_SUB' => 'Windows 10/11 Enterprise E3',
        'WIN10_VDA_E3' => 'Windows 10/11 Enterprise E3',
        'WIN10_VDA_E5' => 'Windows 10/11 Enterprise E5',
        'CPC_B_2C_4RAM_64GB' => 'Windows 365 Business 2 vCPU, 4 GB, 64 GB',
        'STREAM' => 'Microsoft Stream',
        'MCOCAP' => 'Common Area Phone',
        'CDSAICAPACITY' => 'AI Builder Capacity Add-on',
        'MICROSOFT_BUSINESS_CENTER' => 'Microsoft Business Center',
        'Microsoft_365_Copilot' => 'Microsoft 365 Copilot',

        // ── SKUs seen in this tenant that the general list above misses ──
        // "_DEPT" variants are the departmental purchase channel for the same
        // product, so they carry the same product name.
        'TVM_Premium_Standalone' => 'Microsoft Defender Vulnerability Management',
        'CCIBOTS_PRIVPREV_VIRAL' => 'Power Virtual Agents Viral Trial',
        'Microsoft_Teams_Exploratory_Dept' => 'Microsoft Teams Exploratory',
        'POWERAPPS_DEV' => 'Microsoft Power Apps for Developer',
        'PROJECT_PLAN3_DEPT' => 'Project Plan 3',
    ];

    /**
     * Friendly name for a SKU part number.
     *
     * Falls back to a readable form of the part number itself — underscores to
     * spaces, title-cased — so an unmapped SKU reads as "Power Bi Pro" rather
     * than being blank or cryptic.
     */
    public static function forPartNumber(?string $partNumber): string
    {
        $partNumber = trim((string) $partNumber);

        if ($partNumber === '') {
            return 'Unknown licence';
        }

        if (isset(self::NAMES[$partNumber])) {
            return self::NAMES[$partNumber];
        }

        // Case-insensitive second pass — Graph is not consistent about casing.
        foreach (self::NAMES as $key => $name) {
            if (strcasecmp($key, $partNumber) === 0) {
                return $name;
            }
        }

        return ucwords(strtolower(str_replace(['_', '-'], ' ', $partNumber)));
    }

    /**
     * True when we have a real mapping, rather than a tidied part number.
     * Lets the UI flag SKUs worth adding to the list above.
     */
    public static function isKnown(?string $partNumber): bool
    {
        $partNumber = trim((string) $partNumber);

        if ($partNumber === '') {
            return false;
        }

        foreach (array_keys(self::NAMES) as $key) {
            if (strcasecmp($key, $partNumber) === 0) {
                return true;
            }
        }

        return false;
    }
}
