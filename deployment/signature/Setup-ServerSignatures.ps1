<#
.SYNOPSIS
    One-shot server-side signature setup: connects to Exchange Online, creates the three
    scope groups, populates them from NOC, and deploys the three transport rules.

.DESCRIPTION
    Does everything for the New Outlook / OWA / mobile path in one connected session:
      1. Create mail-enabled security groups (skips any that already exist).
      2. Populate them from NOC gender data (male / female / all-SSS).
      3. Create/update the per-(domain,gender) transport rules, scoped to those groups,
         with the SGSIGMARKER dedup exception so classic-Outlook mail isn't double-signed.

    Classic Outlook is deployed separately via Intune (Deploy-Signature.ps1).

.EXAMPLE
    # Live pilot: create groups + rules, but DON'T bulk-populate (you hand-add a few testers)
    .\Setup-ServerSignatures.ps1 -ApiKey hrk_... -Pilot

.EXAMPLE
    # Preview the whole thing, change nothing
    .\Setup-ServerSignatures.ps1 -ApiKey hrk_... -WhatIf

.EXAMPLE
    # Go company-wide (create, populate all ~557, deploy rules)
    .\Setup-ServerSignatures.ps1 -ApiKey hrk_...

.NOTES
    Run as an Exchange admin. Never touch PostponeRoamingSignaturesUntilLater.
#>
param(
    [string] $BaseUrl  = 'https://noc.samirgroup.net',
    [Parameter(Mandatory)] [string] $ApiKey,
    [string] $Marker   = 'SGSIGMARKER',
    [string] $AdminUpn = '',
    [switch] $Pilot,        # create groups + rules but SKIP bulk populate (hand-add testers)
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# domain/gender -> group + rule. Gender $null = gender-neutral (SSS).
$Plan = @(
    [pscustomobject]@{ Group='SG-Signature-Male';   Smtp='SG-Signature-Male@samirgroup.com';   Domain='samirgroup.com'; Gender='male';   Rule='SG Signature - samirgroup.com Male'   }
    [pscustomobject]@{ Group='SG-Signature-Female'; Smtp='SG-Signature-Female@samirgroup.com'; Domain='samirgroup.com'; Gender='female'; Rule='SG Signature - samirgroup.com Female' }
    [pscustomobject]@{ Group='SG-Signature-SSS';    Smtp='SG-Signature-SSS@samirgroup.com';    Domain='sssegypt.com';   Gender=$null;    Rule='SG Signature - sssegypt.com'          }
)

function Get-NocUpns {
    param([string]$Domain, [string]$Gender)
    $u = "$BaseUrl/api/signature/gender-members?domain=$([uri]::EscapeDataString($Domain))&api_key=$([uri]::EscapeDataString($ApiKey))"
    if ($Gender) { $u += '&gender=' + $Gender }
    ,([array]((Invoke-RestMethod -Uri $u -TimeoutSec 60).upns))
}
function Get-NocRuleHtml {
    param([string]$Domain, [string]$Gender)
    $u = "$BaseUrl/api/signature/transport-rule?domain=$([uri]::EscapeDataString($Domain))&type=new_email&format=json&api_key=$([uri]::EscapeDataString($ApiKey))"
    if ($Gender) { $u += '&gender=' + $Gender }
    $h = (Invoke-RestMethod -Uri $u -TimeoutSec 30).html
    if (-not $h) { throw "NOC returned no HTML for $Domain ($Gender)" }
    [string]$h
}

# Connect
if (-not (Get-Module -ListAvailable -Name ExchangeOnlineManagement)) {
    Write-Host "Installing ExchangeOnlineManagement (CurrentUser)..." -ForegroundColor Yellow
    Install-Module ExchangeOnlineManagement -Scope CurrentUser -Force -AllowClobber
}
Import-Module ExchangeOnlineManagement
if ($AdminUpn) { Connect-ExchangeOnline -UserPrincipalName $AdminUpn -ShowBanner:$false }
else           { Connect-ExchangeOnline -ShowBanner:$false }

try {
    # 1) Groups
    Write-Host "`n== 1. Groups ==" -ForegroundColor Cyan
    $created = $false
    foreach ($p in $Plan) {
        if (Get-DistributionGroup -Identity $p.Smtp -ErrorAction SilentlyContinue) {
            Write-Host "  exists : $($p.Group)"
        } elseif ($WhatIf) {
            Write-Host "  WHATIF : would create $($p.Group)" -ForegroundColor Yellow
        } else {
            New-DistributionGroup -Name $p.Group -Type Security -PrimarySmtpAddress $p.Smtp | Out-Null
            Write-Host "  created: $($p.Group)" -ForegroundColor Green
            $created = $true
        }
    }
    if ($created) { Write-Host "  (waiting 20s for new groups to provision...)"; Start-Sleep -Seconds 20 }

    # 2) Populate
    Write-Host "`n== 2. Populate ==" -ForegroundColor Cyan
    if ($Pilot) {
        Write-Host "  SKIPPED (-Pilot): hand-add a few testers to the three groups." -ForegroundColor Yellow
    } else {
        foreach ($p in $Plan) {
            $upns = Get-NocUpns -Domain $p.Domain -Gender $p.Gender
            Write-Host "  $($p.Group): $($upns.Count) users from NOC"
            if (-not $WhatIf) {
                foreach ($upn in $upns) { Add-DistributionGroupMember -Identity $p.Smtp -Member $upn -ErrorAction SilentlyContinue }
            }
        }
    }

    # 3) Transport rules
    Write-Host "`n== 3. Transport rules ==" -ForegroundColor Cyan
    foreach ($p in $Plan) {
        $html = Get-NocRuleHtml -Domain $p.Domain -Gender $p.Gender
        $params = @{
            FromScope                          = 'InOrganization'
            SenderDomainIs                     = $p.Domain
            FromMemberOf                       = $p.Smtp
            ApplyHtmlDisclaimerLocation        = 'Append'
            ApplyHtmlDisclaimerText            = $html
            ApplyHtmlDisclaimerFallbackAction  = 'Wrap'
            ExceptIfSubjectOrBodyContainsWords = $Marker
        }
        if ($WhatIf) {
            Write-Host "  WHATIF : '$($p.Rule)' ($($html.Length) chars) scoped to $($p.Group)" -ForegroundColor Yellow
            continue
        }
        if (Get-TransportRule -Identity $p.Rule -ErrorAction SilentlyContinue) {
            Set-TransportRule -Identity $p.Rule @params
            Enable-TransportRule -Identity $p.Rule -Confirm:$false
            Write-Host "  updated: $($p.Rule)" -ForegroundColor Green
        } else {
            New-TransportRule -Name $p.Rule -Enabled $true @params
            Write-Host "  created: $($p.Rule)" -ForegroundColor Green
        }
    }

    Write-Host "`nDone. Test: send from OWA/new Outlook as a male, a female, and an SSS user;" -ForegroundColor Green
    Write-Host "then from classic Outlook (must NOT double-sign). Re-run any time to refresh." -ForegroundColor Green
}
finally {
    Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
}
