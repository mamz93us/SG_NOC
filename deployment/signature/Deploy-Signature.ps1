<#
.SYNOPSIS
    Deploys the SamirGroup / SSS Egypt Outlook email signature to the signed-in user.

.DESCRIPTION
    Runs in the USER context (Intune platform script or Proactive Remediation).
    Resolves the logged-in user's UPN, pulls their personalised signature HTML from
    the NOC signature API (which auto-selects the template by email domain and fills
    in name / title / branch from the employee profile), writes the classic-Outlook
    signature files, and sets them as the default for New mail and Reply/Forward.

    The signature CONTENT differs per user (domain + branch driven) but the on-disk
    signature NAME is constant, so one script serves everyone.

.NOTES
    - Targets CLASSIC Outlook for Windows (desktop). The "new Outlook" (Monarch) uses
      cloud/roaming signatures and ignores these local files.
    - Must run in user context: Intune > Scripts > "Run this script using the logged-on
      credentials = Yes". Do NOT run as SYSTEM (signatures live in HKCU + %APPDATA%).
    - Re-running is safe/idempotent; it overwrites the files and registry each time.
#>

# ─────────────────────────────────────────────────────────────────────────────
# CONFIG — set these before packaging
# ─────────────────────────────────────────────────────────────────────────────
param(
    [string] $BaseUrl        = 'https://noc.samirgroup.net',
    [string] $ApiKey         = 'REPLACE_WITH_SIGNATURE_SCOPED_API_KEY',   # hrk_... (scope: signature)
    [string] $SignatureName  = 'SamirGroup',                              # main (new-mail) name in Outlook
    [string] $ReplyName      = 'SamirGroup Reply',                        # reply/forward name in Outlook
    [string] $Upn            = '',                                        # optional override; auto-detected if blank
    [switch] $NoLock,                                                     # pass to skip the read-only/policy lock
    [switch] $KeepOtherSignatures                                         # pass to NOT delete pre-existing signatures
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# ─── Logging ─────────────────────────────────────────────────────────────────
$LogDir  = Join-Path $env:LOCALAPPDATA 'SamirGroup\SignatureDeploy'
$LogFile = Join-Path $LogDir 'deploy.log'
New-Item -ItemType Directory -Path $LogDir -Force | Out-Null

function Write-Log {
    param([string]$Message, [string]$Level = 'INFO')
    $line = "{0}  [{1}]  {2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host $line
}

# ─── Resolve the signed-in user's UPN ────────────────────────────────────────
function Resolve-Upn {
    if ($Upn -and $Upn -match '@') { return $Upn.Trim() }

    # 1) whoami /upn — reliable on Azure AD-joined / hybrid-joined machines
    try {
        $u = (& whoami /upn) 2>$null
        if ($LASTEXITCODE -eq 0 -and $u -match '@') { return $u.Trim() }
    } catch {}

    # 2) Office identity cache (HKCU)
    foreach ($ver in @('16.0','15.0')) {
        $idRoot = "HKCU:\Software\Microsoft\Office\$ver\Common\Identity\Identities"
        if (Test-Path $idRoot) {
            foreach ($k in Get-ChildItem $idRoot -ErrorAction SilentlyContinue) {
                $p = Get-ItemProperty $k.PSPath -ErrorAction SilentlyContinue
                if ($p.EmailAddress -match '@') { return $p.EmailAddress.Trim() }
            }
        }
    }

    # 3) AAD device join info
    try {
        $line = (& dsregcmd /status 2>$null) | Select-String 'Executing Account Name|User Name' | Select-Object -First 1
        if ($line -and $line.ToString() -match '([\w\.\-]+@[\w\.\-]+)') { return $Matches[1] }
    } catch {}

    return $null
}

# ─── Fetch rendered signature HTML from the NOC API ──────────────────────────
function Get-SignatureHtml {
    param([string]$UserPrincipalName, [string]$Type)   # Type: new_email | reply
    $uri = "{0}/api/signature?upn={1}&type={2}&format=json&api_key={3}" -f `
        $BaseUrl, [uri]::EscapeDataString($UserPrincipalName), $Type, [uri]::EscapeDataString($ApiKey)

    $resp = Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 30
    if (-not $resp.html) { throw "API returned no HTML for type '$Type'." }
    return [string]$resp.html
}

# ─── Derive a plain-text version for the .txt fallback ───────────────────────
function ConvertTo-PlainText {
    param([string]$Html)
    $t = $Html -replace '(?is)<style.*?</style>', ''
    $t = $t -replace '(?i)<br\s*/?>', "`r`n"
    $t = $t -replace '(?i)</(p|tr|div|h[1-6])>', "`r`n"
    $t = $t -replace '(?s)<[^>]+>', ''
    $t = $t -replace '&nbsp;', ' ' -replace '&amp;', '&' -replace '&bull;', '-' -replace '&#\d+;', ''
    ($t -split "`r`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ }) -join "`r`n"
}

# ─── Write one signature (.htm + .txt), clearing any prior read-only flag ─────
function Write-SignatureFiles {
    param([string]$Dir, [string]$Name, [string]$Html, [bool]$ReadOnly)
    $enc  = [System.Text.UTF8Encoding]::new($false)
    $htm  = Join-Path $Dir "$Name.htm"
    $txt  = Join-Path $Dir "$Name.txt"
    foreach ($f in @($htm, $txt)) {
        if (Test-Path $f) { try { (Get-Item $f).IsReadOnly = $false } catch {} }
    }
    [System.IO.File]::WriteAllText($htm, "<!DOCTYPE html><html><head><meta charset=""utf-8""></head><body>$Html</body></html>", $enc)
    [System.IO.File]::WriteAllText($txt, (ConvertTo-PlainText $Html), $enc)
    if ($ReadOnly) {
        foreach ($f in @($htm, $txt)) { try { (Get-Item $f).IsReadOnly = $true } catch {} }
    }
}

# ─────────────────────────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────────────────────────
try {
    Write-Log "=== Signature deploy started (user: $env:USERNAME) ==="

    $userUpn = Resolve-Upn
    if (-not $userUpn) {
        Write-Log "Could not resolve a UPN for the signed-in user; aborting." 'ERROR'
        exit 1
    }
    Write-Log "Resolved UPN: $userUpn"

    $lock = -not $NoLock.IsPresent

    # Pull both slots. Reply falls back to the 'all' template server-side if none set.
    $newHtml   = Get-SignatureHtml -UserPrincipalName $userUpn -Type 'new_email'
    try   { $replyHtml = Get-SignatureHtml -UserPrincipalName $userUpn -Type 'reply' }
    catch { $replyHtml = $newHtml; Write-Log "No reply-specific template; reusing new-mail signature." 'WARN' }

    # Signatures live in a fixed, locale-independent folder.
    $sigDir = Join-Path $env:APPDATA 'Microsoft\Signatures'
    New-Item -ItemType Directory -Path $sigDir -Force | Out-Null

    # Remove any OTHER (old / personal) signatures so only the corporate ones remain.
    if (-not $KeepOtherSignatures.IsPresent) {
        $keep = @($SignatureName, $ReplyName)
        Get-ChildItem $sigDir -File -ErrorAction SilentlyContinue | Where-Object {
            $keep -notcontains [IO.Path]::GetFileNameWithoutExtension($_.Name)
        } | ForEach-Object { try { $_.IsReadOnly = $false } catch {}; Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue }
        Get-ChildItem $sigDir -Directory -ErrorAction SilentlyContinue | Where-Object {
            $keep -notcontains ($_.Name -replace '_files$','')
        } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        Write-Log "Removed other/old signatures (kept: $($keep -join ', '))."
    }

    # Always install BOTH slots as distinct, named signatures.
    Write-SignatureFiles -Dir $sigDir -Name $SignatureName -Html $newHtml   -ReadOnly:$lock
    Write-SignatureFiles -Dir $sigDir -Name $ReplyName     -Html $replyHtml -ReadOnly:$lock
    Write-Log "Wrote signatures: '$SignatureName' (new) + '$ReplyName' (reply). ReadOnly=$lock"

    # Detect installed Outlook version key (16.0 = 2016/2019/2021/365, 15.0 = 2013)
    $verKey = @('16.0','15.0') | Where-Object { Test-Path "HKCU:\Software\Microsoft\Office\$_\Outlook" } | Select-Object -First 1
    if (-not $verKey) { $verKey = '16.0' }
    Write-Log "Using Office version key: $verKey"

    # Set as the default signature for New + Reply/Forward (user hive).
    $ms = "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings"
    New-Item -Path $ms -Force | Out-Null
    Set-ItemProperty -Path $ms -Name 'NewSignature'   -Value $SignatureName -Type String
    Set-ItemProperty -Path $ms -Name 'ReplySignature' -Value $ReplyName     -Type String

    # Make local files win over M365 cloud/roaming signatures.
    $setup = "HKCU:\Software\Microsoft\Office\$verKey\Outlook\Setup"
    New-Item -Path $setup -Force | Out-Null
    Set-ItemProperty -Path $setup -Name 'DisableRoamingSignaturesTemporaryToggle' -Value 1 -Type DWord

    # ── Lock: force the selection via the POLICY hive (takes precedence over any
    #    change a user makes in the Outlook UI, and reasserts on every Outlook read).
    if ($lock) {
        $pol = "HKCU:\Software\Policies\Microsoft\Office\$verKey\Common\MailSettings"
        New-Item -Path $pol -Force | Out-Null
        Set-ItemProperty -Path $pol -Name 'NewSignature'   -Value $SignatureName -Type String
        Set-ItemProperty -Path $pol -Name 'ReplySignature' -Value $ReplyName     -Type String

        # Best-effort: grey out the "Signature" button in the compose window so users
        # can't add/edit signatures there (control ID 5608). The policy-hive selection
        # above is the real guarantee — even a user-added signature is never applied.
        $dis = "HKCU:\Software\Policies\Microsoft\Office\$verKey\Outlook\DisabledCmdBarItemsList"
        New-Item -Path $dis -Force | Out-Null
        Set-ItemProperty -Path $dis -Name 'TCID1' -Value '5608' -Type String

        Write-Log "Locked: files read-only, selection forced via policy, Signature button disabled."
    }
    Write-Log "Set default signatures (New='$SignatureName', Reply='$ReplyName')."

    # Store a hash so the detection script can tell when the template changed
    $hash = (Get-FileHash -Algorithm SHA256 -InputStream ([IO.MemoryStream]::new([Text.Encoding]::UTF8.GetBytes($newHtml + $replyHtml)))).Hash
    Set-Content -Path (Join-Path $LogDir 'last.hash') -Value $hash -Encoding ASCII

    Write-Log "=== Signature deploy completed OK ==="
    exit 0
}
catch {
    Write-Log ("FAILED: " + $_.Exception.Message) 'ERROR'
    exit 1
}
