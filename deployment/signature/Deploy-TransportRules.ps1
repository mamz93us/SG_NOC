<#
.SYNOPSIS
    Deploys Exchange transport rules that stamp the NOC email signature onto outbound mail
    for NEW Outlook / OWA / mobile (the clients that ignore local files), per domain and
    per gender.

.DESCRIPTION
    A transport rule is static per audience — it can't read a sender's gender per message —
    so gender-specific templates need one rule per (domain, gender), each scoped to a
    mail-enabled group whose membership IS the audience:

        SG Signature - sssegypt.com          -> PilotGroup   (SSS is gender-neutral)
        SG Signature - samirgroup.com Male    -> MaleGroup
        SG Signature - samirgroup.com Female  -> FemaleGroup

    Each rule fetches its HTML from the NOC endpoint (with &gender=), appends it as a
    disclaimer, and skips messages already carrying the dedup marker (classic-Outlook mail
    that Deploy-Signature.ps1 already signed) so nobody is double-signed. Exchange fills the
    %%AD-attribute%% tokens per sender from Azure AD, which NOC populates.

    GROUP MEMBERSHIP is the scope: put males in MaleGroup and females in FemaleGroup (a
    female in MaleGroup would get the male signature). Populate from NOC gender — see the
    README for the one-liner that lists male/female UPNs.

.NOTES
    - Run by an admin; interactive Exchange Online sign-in.
    - Re-run whenever the NOC template changes.
    - Keep logos hosted by URL (not embedded) so the disclaimer stays within the size limit.
#>
param(
    [string] $BaseUrl = 'https://noc.samirgroup.net',
    [Parameter(Mandatory)] [string] $ApiKey,               # hrk_... (scope: signature)
    [Parameter(Mandatory)] [string] $MaleGroup,            # mail-enabled group: samirgroup MALE users
    [Parameter(Mandatory)] [string] $FemaleGroup,          # mail-enabled group: samirgroup FEMALE users
    [Parameter(Mandatory)] [string] $PilotGroup,           # mail-enabled group: SSS (gender-neutral) users
    [string] $Marker   = 'SGSIGMARKER',                    # must match SignatureRenderService::SIG_MARKER
    [string] $AdminUpn = '',                               # optional: pre-fill the EXO sign-in
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# Rule set: one entry per (domain, gender). Gender $null = gender-neutral (no &gender=).
$Rules = @(
    [pscustomobject]@{ Name = 'SG Signature - sssegypt.com';         Domain = 'sssegypt.com';   Gender = $null;     Group = $PilotGroup  }
    [pscustomobject]@{ Name = 'SG Signature - samirgroup.com Male';   Domain = 'samirgroup.com'; Gender = 'male';    Group = $MaleGroup   }
    [pscustomobject]@{ Name = 'SG Signature - samirgroup.com Female'; Domain = 'samirgroup.com'; Gender = 'female';  Group = $FemaleGroup }
)

function Get-RuleHtml {
    param([string]$Domain, [string]$Gender)
    $uri = "{0}/api/signature/transport-rule?domain={1}&type=new_email&format=json&api_key={2}" -f `
        $BaseUrl, [uri]::EscapeDataString($Domain), [uri]::EscapeDataString($ApiKey)
    if ($Gender) { $uri += '&gender=' + [uri]::EscapeDataString($Gender) }
    $resp = Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 30
    if (-not $resp.html) { throw "NOC returned no HTML for $Domain ($Gender)" }
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
    foreach ($rule in $Rules) {
        $g = if ($rule.Gender) { $rule.Gender } else { 'neutral' }
        Write-Host ""
        Write-Host "=== $($rule.Domain) [$g] -> '$($rule.Name)'  (group: $($rule.Group)) ===" -ForegroundColor Cyan

        $html = Get-RuleHtml -Domain $rule.Domain -Gender $rule.Gender
        Write-Host "Fetched HTML from NOC ($($html.Length) chars)." -ForegroundColor Green

        # Conditions (AND): internal sender, in this domain, member of the audience group.
        # Exception: body already carries the dedup marker (classic Outlook already signed).
        $params = @{
            FromScope                          = 'InOrganization'
            SenderDomainIs                     = $rule.Domain
            FromMemberOf                       = $rule.Group
            ApplyHtmlDisclaimerLocation        = 'Append'
            ApplyHtmlDisclaimerText            = $html
            ApplyHtmlDisclaimerFallbackAction  = 'Wrap'
            ExceptIfSubjectOrBodyContainsWords = $Marker
        }

        if ($WhatIf) {
            Write-Host "WHATIF: would set '$($rule.Name)' scoped to '$($rule.Group)'." -ForegroundColor Yellow
            continue
        }

        $existing = Get-TransportRule -Identity $rule.Name -ErrorAction SilentlyContinue
        if ($existing) {
            Set-TransportRule -Identity $rule.Name @params
            Enable-TransportRule -Identity $rule.Name -Confirm:$false
            Write-Host "Updated rule '$($rule.Name)'." -ForegroundColor Green
        } else {
            New-TransportRule -Name $rule.Name -Enabled $true @params
            Write-Host "Created rule '$($rule.Name)'." -ForegroundColor Green
        }
    }

    Write-Host ""
    Write-Host "Done. Test per group: send from OWA/new Outlook as a male, a female, and an SSS" -ForegroundColor Green
    Write-Host "pilot user; then from classic Outlook (must NOT double-sign - the marker skips it)." -ForegroundColor Green
}
finally {
    Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
}
