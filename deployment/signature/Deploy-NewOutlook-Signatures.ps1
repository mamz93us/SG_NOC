<#
.SYNOPSIS
    Deploys email signatures to NEW Outlook for Windows / Outlook on the web (OWA) /
    Outlook mobile — i.e. the CLOUD (roaming) signature stored in each Exchange Online
    mailbox. This is the server-side counterpart to Deploy-Signature.ps1 (which handles
    classic Outlook desktop via local files).

.DESCRIPTION
    New Outlook does NOT read %APPDATA%\Microsoft\Signatures or HKCU. It reads the
    roaming signature from the mailbox. This script connects to Exchange Online, and for
    each targeted mailbox pulls the personalised HTML from the NOC signature API and
    writes it with Set-MailboxMessageConfiguration (auto-add on new + reply).

    Run it on the NOC VM or an admin workstation, on a schedule (e.g. daily), so cloud
    signatures refresh whenever a template changes and any user edit is overwritten.

.PREREQUISITES
    - PowerShell module:  Install-Module ExchangeOnlineManagement -Scope AllUsers
    - App-only auth (recommended for automation):
        * Entra app registration with API permission
          "Office 365 Exchange Online > Exchange.ManageAsApp" (Application) + admin consent
        * A certificate uploaded to that app; the .pfx/thumbprint available on the runner
        * The app assigned the **Exchange Administrator** Entra role (grants write to
          Set-MailboxMessageConfiguration)
      You can reuse the existing NOC identity-sync app registration if you add the above.

.NOTES
    - Set-MailboxMessageConfiguration applies ONE signature to a mailbox; -AutoAddSignatureOnReply
      makes replies use it too. A separate reply-specific HTML is not supported by this cmdlet,
      so the reply template is not applied here (classic Outlook gets the separate reply signature).
    - Users can still edit their OWA signature; the scheduled re-run overwrites it. For a
      tamper-proof result use an Exchange transport-rule disclaimer instead.
#>

param(
    [string]   $BaseUrl        = 'https://noc.samirgroup.net',
    [string]   $ApiKey         = 'REPLACE_WITH_SIGNATURE_SCOPED_API_KEY',

    # ─ Exchange Online app-only auth ─
    [Parameter(Mandatory)] [string] $AppId,
    [Parameter(Mandatory)] [string] $Organization,     # e.g. samirgroup.onmicrosoft.com
    [Parameter(Mandatory)] [string] $CertThumbprint,   # cert in the runner's cert store

    # ─ Target selection ─
    [string[]] $Upns    = @(),                          # explicit UPNs; empty = all user mailboxes in $Domains
    [string[]] $Domains = @('samirgroup.com','sssegypt.com'),

    [int]      $ThrottleMs = 400,                       # delay between mailboxes
    [switch]   $WhatIf                                  # preview only; makes no changes
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$LogDir  = Join-Path $env:LOCALAPPDATA 'SamirGroup\SignatureDeploy'
New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
$LogFile = Join-Path $LogDir 'newoutlook-deploy.log'
function Write-Log { param([string]$m,[string]$l='INFO')
    $line = "{0}  [{1}]  {2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $l, $m
    Add-Content -Path $LogFile -Value $line -Encoding UTF8; Write-Host $line
}

function Get-SignatureHtml {
    param([string]$Upn, [string]$Type)
    $uri = "{0}/api/signature?upn={1}&type={2}&format=json&api_key={3}" -f `
        $BaseUrl, [uri]::EscapeDataString($Upn), $Type, [uri]::EscapeDataString($ApiKey)
    (Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 30).html
}
function ConvertTo-PlainText {
    param([string]$Html)
    $t = $Html -replace '(?is)<style.*?</style>',''
    $t = $t -replace '(?i)<br\s*/?>',"`r`n" -replace '(?i)</(p|tr|div|h[1-6])>',"`r`n"
    $t = $t -replace '(?s)<[^>]+>','' -replace '&nbsp;',' ' -replace '&amp;','&' -replace '&#\d+;',''
    ($t -split "`r`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ }) -join "`r`n"
}

try {
    Write-Log "=== New-Outlook (cloud) signature deploy started ==="

    if (-not (Get-Module -ListAvailable -Name ExchangeOnlineManagement)) {
        throw "ExchangeOnlineManagement module not installed. Run: Install-Module ExchangeOnlineManagement -Scope AllUsers"
    }
    Import-Module ExchangeOnlineManagement -ErrorAction Stop

    Connect-ExchangeOnline -AppId $AppId -Organization $Organization `
        -CertificateThumbprint $CertThumbprint -ShowBanner:$false
    Write-Log "Connected to Exchange Online ($Organization)."

    # Build the target UPN list
    if ($Upns.Count -eq 0) {
        Write-Log "Enumerating user mailboxes in: $($Domains -join ', ')"
        $Upns = Get-EXOMailbox -RecipientTypeDetails UserMailbox -ResultSize Unlimited |
                Where-Object { $addr = $_.UserPrincipalName; $Domains | Where-Object { $addr -like "*@$_" } } |
                Select-Object -ExpandProperty UserPrincipalName
    }
    Write-Log "Targets: $($Upns.Count) mailbox(es)."

    $ok = 0; $skip = 0; $fail = 0
    foreach ($upn in $Upns) {
        try {
            $html = Get-SignatureHtml -Upn $upn -Type 'new_email'
            if (-not $html) { Write-Log "SKIP $upn (no template/user match)" 'WARN'; $skip++; continue }
            $text = ConvertTo-PlainText $html

            if ($WhatIf) {
                Write-Log "WHATIF $upn - would set signature ($($html.Length) chars)"
            } else {
                Set-MailboxMessageConfiguration -Identity $upn `
                    -SignatureHtml $html -SignatureText $text `
                    -AutoAddSignature $true -AutoAddSignatureOnReply $true -AutoAddSignatureOnMobile $true
                Write-Log "OK   $upn"
            }
            $ok++
        }
        catch { Write-Log ("FAIL $upn : " + $_.Exception.Message) 'ERROR'; $fail++ }
        Start-Sleep -Milliseconds $ThrottleMs
    }

    Write-Log "=== Done. ok=$ok skipped=$skip failed=$fail ==="
}
catch { Write-Log ("FATAL: " + $_.Exception.Message) 'ERROR'; exit 1 }
finally {
    try { Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue } catch {}
}
