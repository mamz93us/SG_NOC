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
    # App-only auth (for UNATTENDED scheduled runs). Provide all three to skip the
    # interactive sign-in: an Entra app with Exchange.ManageAsApp + a cert in the
    # runner's store + the tenant. See README.
    [string] $AppId          = '',
    [string] $CertThumbprint = '',
    [string] $Organization   = '',
    [switch] $Pilot,        # create groups + rules but SKIP bulk populate (hand-add testers)
    [switch] $RefreshOnly,  # ONLY re-push the rule HTML (fast) - skip groups + populate. Use after a template/logo edit.
    [switch] $PopulateOnly, # ONLY create + sync group membership from NOC - skip the transport rules. Use to add/refresh users.
    # Sign REPLIES + FORWARDS too: deploy the rules WITHOUT the SGSIGMARKER dedup exception.
    # A rule cannot tell a reply from a new mail; the marker exception is what skipped replies
    # (the quoted history contains the marker). Removing it stamps every message - BUT any user
    # who ALSO has a classic-Outlook client signature will then be double-signed; remove their
    # client signature (Deploy-Signature.ps1 -RemoveClientSignature) before/after using this.
    # NOTE: on replies Exchange appends the signature at the BOTTOM of the thread (below the
    # quoted history) - that placement is inherent to transport-rule disclaimers.
    [switch] $SignReplies,
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# domain/gender -> group + rule. Gender $null = gender-neutral (SSS, Oriana).
# NOTE: oriana-sa.com must be an accepted domain in THIS Exchange tenant, and an
# active signature template for domain=oriana-sa.com must exist in NOC (else the
# transport-rule fetch 404s). Split the Oriana row into male/female if it goes gendered.
$Plan = @(
    [pscustomobject]@{ Group='SG-Signature-Male';   Smtp='SG-Signature-Male@samirgroup.com';   Domain='samirgroup.com'; Gender='male';   Rule='SG Signature - samirgroup.com Male'   }
    [pscustomobject]@{ Group='SG-Signature-Female'; Smtp='SG-Signature-Female@samirgroup.com'; Domain='samirgroup.com'; Gender='female'; Rule='SG Signature - samirgroup.com Female' }
    [pscustomobject]@{ Group='SG-Signature-SSS';    Smtp='SG-Signature-SSS@samirgroup.com';    Domain='sssegypt.com';   Gender=$null;    Rule='SG Signature - sssegypt.com'          }
    [pscustomobject]@{ Group='SG-Signature-Oriana'; Smtp='SG-Signature-Oriana@samirgroup.com'; Domain='oriana-sa.com';  Gender=$null;    Rule='SG Signature - oriana-sa.com'         }
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
if ($AppId -and $CertThumbprint -and $Organization) {
    # Unattended app-only auth (scheduled task)
    Connect-ExchangeOnline -AppId $AppId -CertificateThumbprint $CertThumbprint -Organization $Organization -ShowBanner:$false
} elseif ($AdminUpn) {
    Connect-ExchangeOnline -UserPrincipalName $AdminUpn -ShowBanner:$false
} else {
    Connect-ExchangeOnline -ShowBanner:$false
}

try {
    if ($RefreshOnly) {
        Write-Host "`n== RefreshOnly: skipping groups + populate, re-pushing rule HTML only ==" -ForegroundColor Yellow
    } else {
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
    }

    # 3) Transport rules
    if ($PopulateOnly) {
        Write-Host "`n== PopulateOnly: skipping transport rules (group membership synced above) ==" -ForegroundColor Yellow
        return
    }
    Write-Host "`n== 3. Transport rules ==" -ForegroundColor Cyan
    if ($SignReplies) {
        Write-Host "  -SignReplies: dedup exception REMOVED -- replies + forwards WILL be signed." -ForegroundColor Yellow
        Write-Host "  Anyone who still has a classic-Outlook client signature will be DOUBLE-signed;" -ForegroundColor Yellow
        Write-Host "  remove it with: Deploy-Signature.ps1 -RemoveClientSignature" -ForegroundColor Yellow
    }
    $failed = @()
    foreach ($p in $Plan) {
        try {
            $html = Get-NocRuleHtml -Domain $p.Domain -Gender $p.Gender
        } catch {
            Write-Host "  FETCH FAILED: $($p.Rule) -- $($_.Exception.Message)" -ForegroundColor Red
            $failed += $p.Rule; continue
        }

        # Exchange rejects an oversized disclaimer (a base64-embedded logo bloats it well past
        # the limit). Catch it here with a clear fix instead of a cryptic binding error.
        if ($html.Length -gt 15000 -or $html -match 'data:image') {
            Write-Host ("  TOO BIG ({0} chars): {1} -- host the logo on the NOC server: php artisan signatures:host-logos, then re-run." -f $html.Length, $p.Rule) -ForegroundColor Red
            $failed += $p.Rule; continue
        }

        $params = @{
            FromScope                          = 'InOrganization'
            SenderDomainIs                     = $p.Domain
            FromMemberOf                       = $p.Smtp
            ApplyHtmlDisclaimerLocation        = 'Append'
            ApplyHtmlDisclaimerText            = $html
            ApplyHtmlDisclaimerFallbackAction  = 'Wrap'
            ExceptIfSubjectOrBodyContainsWords = $Marker
        }
        if ($SignReplies) {
            # No dedup exception -> replies and forwards get signed too (see -SignReplies note).
            # On an EXISTING rule the property must be explicitly cleared ($null); simply
            # omitting it would leave the old exception in place and replies would stay unsigned.
            $params['ExceptIfSubjectOrBodyContainsWords'] = $null
        }
        if ($WhatIf) {
            Write-Host "  WHATIF : '$($p.Rule)' ($($html.Length) chars) scoped to $($p.Group)" -ForegroundColor Yellow
            continue
        }

        $exists = [bool](Get-TransportRule -Identity $p.Rule -ErrorAction SilentlyContinue)
        $ok = $false
        for ($try = 1; $try -le 3 -and -not $ok; $try++) {
            try {
                if ($exists) {
                    Set-TransportRule -Identity $p.Rule @params -ErrorAction Stop
                    Enable-TransportRule -Identity $p.Rule -Confirm:$false -ErrorAction SilentlyContinue
                } else {
                    New-TransportRule -Name $p.Rule -Enabled $true @params -ErrorAction Stop
                }
                $ok = $true
            } catch {
                if ($try -lt 3) {
                    Write-Host "  retry $try/2 for $($p.Rule) ($($_.Exception.Message))" -ForegroundColor Yellow
                    Start-Sleep -Seconds 10
                } else {
                    Write-Host "  FAILED: $($p.Rule) -- $($_.Exception.Message)" -ForegroundColor Red
                    $failed += $p.Rule
                }
            }
        }
        if ($ok) { Write-Host ("  {0}: {1} ({2} chars)" -f $(if ($exists) { 'updated' } else { 'created' }), $p.Rule, $html.Length) -ForegroundColor Green }
    }

    if ($failed.Count) {
        Write-Host "`nRules NOT deployed: $($failed -join ', ')" -ForegroundColor Red
        Write-Host "Fix the cause above and re-run (idempotent)." -ForegroundColor Yellow
    } else {
        Write-Host "`nAll rules deployed. Test: send from OWA/new Outlook per domain/gender;" -ForegroundColor Green
        Write-Host "then from classic Outlook (must NOT double-sign). Re-run any time to refresh." -ForegroundColor Green
    }
}
finally {
    Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
}
