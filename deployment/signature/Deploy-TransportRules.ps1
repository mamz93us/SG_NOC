<#
.SYNOPSIS
    Deploys per-domain Exchange transport rules that stamp the NOC email signature onto
    outbound mail for NEW Outlook / OWA / mobile (the clients that ignore local files).

.DESCRIPTION
    Pulls the transport-rule HTML from the NOC endpoint (/api/signature/transport-rule)
    for each domain and creates/updates a mail-flow rule, scoped to a pilot group, with a
    dedup-marker exception so classic-Outlook mail (already client-signed by
    Deploy-Signature.ps1) is NOT double-stamped.

    The signature is DESIGNED in NOC; this pushes NOC's generated HTML into Exchange.
    Exchange fills the %%AD-attribute%% tokens per sender from Azure AD, which NOC
    already populates (AzureContactSyncService). Re-run whenever the NOC template changes.

.NOTES
    - Run by an admin; interactive Exchange Online sign-in (no app registration needed).
    - Scope is a mail-enabled group -> add users to pilot, remove to exclude. No org-wide switch.
    - Keep the logo hosted by URL (not embedded base64) so the disclaimer stays small.
#>
param(
    [string]   $BaseUrl    = 'https://noc.samirgroup.net',
    [Parameter(Mandatory)] [string] $ApiKey,                 # hrk_... (scope: signature)
    [Parameter(Mandatory)] [string] $PilotGroup,             # mail-enabled group (email or name) that scopes the rule
    [string[]] $Domains    = @('sssegypt.com','samirgroup.com'),
    [string]   $Marker     = 'SGSIGMARKER',                  # must match SignatureRenderService::SIG_MARKER
    [string]   $AdminUpn   = '',                             # optional: pre-fill the EXO sign-in
    [switch]   $WhatIf
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Get-RuleHtml {
    param([string]$Domain)
    $uri = "{0}/api/signature/transport-rule?domain={1}&type=new_email&format=json&api_key={2}" -f `
        $BaseUrl, [uri]::EscapeDataString($Domain), [uri]::EscapeDataString($ApiKey)
    $resp = Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 30
    if (-not $resp.html) { throw "NOC returned no HTML for $Domain" }
    return [string]$resp.html
}

# Module + connect
if (-not (Get-Module -ListAvailable -Name ExchangeOnlineManagement)) {
    Write-Host "Installing ExchangeOnlineManagement (CurrentUser)..." -ForegroundColor Yellow
    Install-Module ExchangeOnlineManagement -Scope CurrentUser -Force -AllowClobber
}
Import-Module ExchangeOnlineManagement
if ($AdminUpn) { Connect-ExchangeOnline -UserPrincipalName $AdminUpn -ShowBanner:$false }
else           { Connect-ExchangeOnline -ShowBanner:$false }

try {
    foreach ($domain in $Domains) {
        $ruleName = "SG Signature - $domain"
        Write-Host ""
        Write-Host "=== $domain -> '$ruleName' ===" -ForegroundColor Cyan

        $html = Get-RuleHtml -Domain $domain
        Write-Host "Fetched HTML from NOC ($($html.Length) chars)." -ForegroundColor Green

        # Conditions (AND): internal sender, in this domain, member of the pilot group.
        # Action: append the disclaimer. Exception: body already contains the dedup marker
        # (i.e. classic Outlook already signed it) -> skip, so no double signature.
        $params = @{
            FromScope                          = 'InOrganization'
            SenderDomainIs                     = $domain
            FromMemberOf                       = $PilotGroup
            ApplyHtmlDisclaimerLocation        = 'Append'
            ApplyHtmlDisclaimerText            = $html
            ApplyHtmlDisclaimerFallbackAction  = 'Wrap'
            ExceptIfSubjectOrBodyContainsWords = $Marker
        }

        if ($WhatIf) {
            Write-Host "WHATIF: would set rule '$ruleName' scoped to group '$PilotGroup'." -ForegroundColor Yellow
            continue
        }

        $existing = Get-TransportRule -Identity $ruleName -ErrorAction SilentlyContinue
        if ($existing) {
            Set-TransportRule -Identity $ruleName @params
            Enable-TransportRule -Identity $ruleName -Confirm:$false
            Write-Host "Updated rule '$ruleName'." -ForegroundColor Green
        } else {
            New-TransportRule -Name $ruleName -Enabled $true @params
            Write-Host "Created rule '$ruleName'." -ForegroundColor Green
        }
    }

    Write-Host ""
    Write-Host "Done. Test as a pilot user: send from OWA/new Outlook (signature appears)," -ForegroundColor Green
    Write-Host "then from classic Outlook (should NOT double-sign - the marker skips it)."   -ForegroundColor Green
}
finally {
    Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
}
