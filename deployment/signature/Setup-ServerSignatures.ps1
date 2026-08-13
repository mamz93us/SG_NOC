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
    # Subject prefixes that identify a REPLY or FORWARD. Exchange has no "reply" message
    # type (MessageTypeMatches has no such value), so the subject is the ONLY way to tell a
    # reply from new mail. Regex, case-insensitive. Arabic prefixes are appended below.
    [string[]] $ReplyPrefixPatterns = @(
        '^\s*(RE|FW|FWD|AW|SV|VS|RES|ANTW)\s*:'    # English + common European
    ),
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# Arabic reply/forward subject prefixes, built from char codes so THIS FILE STAYS PURE ASCII.
# (Windows PowerShell 5.1 reads a no-BOM UTF-8 .ps1 as ANSI, which corrupts literal Arabic
# and breaks parsing.) 0x0631,0x062F = "Rad" (reply); the longer run = "I'adat tawjih" (forward).
$arReply = -join [char[]] @(0x0631, 0x062F)
$arFwd   = -join [char[]] @(0x0625, 0x0639, 0x0627, 0x062F, 0x0629, 0x0020, 0x062A, 0x0648, 0x062C, 0x064A, 0x0647)
$ReplyPrefixPatterns += ('^\s*(' + $arReply + '|' + $arFwd + ')\s*:')

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
    param([string]$Domain, [string]$Gender, [string]$Type = 'new_email')   # new_email | reply
    $u = "$BaseUrl/api/signature/transport-rule?domain=$([uri]::EscapeDataString($Domain))&type=$Type&format=json&api_key=$([uri]::EscapeDataString($ApiKey))"
    if ($Gender) { $u += '&gender=' + $Gender }
    $h = (Invoke-RestMethod -Uri $u -TimeoutSec 30).html
    if (-not $h) { throw "NOC returned no HTML for $Domain ($Gender, $Type)" }
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
    Write-Host "  TWO rules per scope:" -ForegroundColor DarkCyan
    Write-Host "    NEW MAIL - keeps the $Marker exception, so classic Outlook (whose CLIENT signs" -ForegroundColor DarkCyan
    Write-Host "               new mail) is not double-signed; OWA/new/mobile new mail is signed." -ForegroundColor DarkCyan
    Write-Host "    REPLIES  - matched by subject prefix, NO marker exception, so every reply and" -ForegroundColor DarkCyan
    Write-Host "               forward is signed from every client. Requires the client script to" -ForegroundColor DarkCyan
    Write-Host "               no longer add a reply signature (Deploy-Signature.ps1 does that now)." -ForegroundColor DarkCyan

    # Deploys/updates one rule; returns $true on success. Retries transient EXO failures.
    function Set-SgRule {
        param([string]$Name, [hashtable]$Params)
        $exists = [bool](Get-TransportRule -Identity $Name -ErrorAction SilentlyContinue)
        for ($try = 1; $try -le 3; $try++) {
            try {
                if ($exists) {
                    Set-TransportRule -Identity $Name @Params -ErrorAction Stop
                    Enable-TransportRule -Identity $Name -Confirm:$false -ErrorAction SilentlyContinue
                } else {
                    New-TransportRule -Name $Name -Enabled $true @Params -ErrorAction Stop
                }
                Write-Host ("  {0}: {1}" -f $(if ($exists) { 'updated' } else { 'created' }), $Name) -ForegroundColor Green
                return $true
            } catch {
                if ($try -lt 3) {
                    Write-Host "  retry $try/2 for $Name ($($_.Exception.Message))" -ForegroundColor Yellow
                    Start-Sleep -Seconds 10
                } else {
                    Write-Host "  FAILED: $Name -- $($_.Exception.Message)" -ForegroundColor Red
                }
            }
        }
        return $false
    }

    $failed = @()
    foreach ($p in $Plan) {
        $replyRuleName = "$($p.Rule) (Replies)"

        # Fetch both variants: new-mail HTML and the reply template (falls back server-side
        # to the domain's template when no reply-specific one exists).
        try {
            $htmlNew = Get-NocRuleHtml -Domain $p.Domain -Gender $p.Gender -Type 'new_email'
        } catch {
            Write-Host "  FETCH FAILED: $($p.Rule) -- $($_.Exception.Message)" -ForegroundColor Red
            $failed += $p.Rule; continue
        }
        try   { $htmlReply = Get-NocRuleHtml -Domain $p.Domain -Gender $p.Gender -Type 'reply' }
        catch { $htmlReply = $htmlNew; Write-Host "  (no reply-specific template for $($p.Domain); reusing new-mail HTML)" -ForegroundColor DarkGray }

        # Exchange rejects an oversized disclaimer (a base64-embedded logo bloats it well past
        # the limit). Catch it here with a clear fix instead of a cryptic binding error.
        $oversized = $false
        foreach ($h in @($htmlNew, $htmlReply)) {
            if ($h.Length -gt 15000 -or $h -match 'data:image') {
                Write-Host ("  TOO BIG ({0} chars): {1} -- host the logo on the NOC server: php artisan signatures:host-logos, then re-run." -f $h.Length, $p.Rule) -ForegroundColor Red
                $oversized = $true
            }
        }
        if ($oversized) { $failed += $p.Rule; continue }

        $common = @{
            FromScope                         = 'InOrganization'
            SenderDomainIs                    = $p.Domain
            FromMemberOf                      = $p.Smtp
            ApplyHtmlDisclaimerLocation       = 'Append'
            ApplyHtmlDisclaimerFallbackAction = 'Wrap'
        }

        # NEW MAIL: everything that is NOT a reply/forward. Keeps the marker exception so a
        # classic-Outlook new mail (already signed by the client) is skipped.
        $pNew = $common.Clone()
        $pNew['ApplyHtmlDisclaimerText']            = $htmlNew
        $pNew['ExceptIfSubjectMatchesPatterns']     = $ReplyPrefixPatterns
        $pNew['ExceptIfSubjectOrBodyContainsWords'] = $Marker

        # REPLIES/FORWARDS: no marker exception at all. Safe because no client adds a reply
        # signature any more, so there is nothing to double up. (The marker can never work on
        # replies: the quoted history always carries it, which is what suppressed them.)
        $pReply = $common.Clone()
        $pReply['ApplyHtmlDisclaimerText']            = $htmlReply
        $pReply['SubjectMatchesPatterns']             = $ReplyPrefixPatterns
        # Explicitly clear on an existing rule - omitting would leave a stale exception behind.
        $pReply['ExceptIfSubjectOrBodyContainsWords'] = $null

        if ($WhatIf) {
            Write-Host ("  WHATIF : '{0}' new-mail ({1} chars) + '{2}' replies ({3} chars), scoped to {4}" -f `
                $p.Rule, $htmlNew.Length, $replyRuleName, $htmlReply.Length, $p.Group) -ForegroundColor Yellow
            continue
        }

        if (-not (Set-SgRule -Name $p.Rule        -Params $pNew))   { $failed += $p.Rule }
        if (-not (Set-SgRule -Name $replyRuleName -Params $pReply)) { $failed += $replyRuleName }
    }

    if ($failed.Count) {
        Write-Host "`nRules NOT deployed: $($failed -join ', ')" -ForegroundColor Red
        Write-Host "Fix the cause above and re-run (idempotent)." -ForegroundColor Yellow
    } else {
        Write-Host "`nAll rules deployed (new-mail + replies per scope). Test matrix:" -ForegroundColor Green
        Write-Host "  classic Outlook  NEW   -> client signature only (no double)" -ForegroundColor Green
        Write-Host "  classic Outlook  REPLY -> server signature, appended at the bottom" -ForegroundColor Green
        Write-Host "  OWA/new/mobile   NEW   -> server signature" -ForegroundColor Green
        Write-Host "  OWA/new/mobile   REPLY -> server signature" -ForegroundColor Green
        Write-Host "Re-run any time to refresh (idempotent)." -ForegroundColor Green
    }
}
finally {
    Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
}
