<#
.SYNOPSIS
    CLEAN-SLATE TEST for per-account signatures on classic Outlook.

.DESCRIPTION
    Interactive test tool (NOT the Intune deploy script). It:
      1. Closes Outlook.
      2. Deletes EVERY signature in %APPDATA%\Microsoft\Signatures (full wipe).
      3. Clears the per-account signature assignment on every Outlook account.
      4. Clears the global default + policy-forced signature.
      5. Forces roaming/cloud signatures off (local files win).
      6. Installs a fresh per-account signature for each managed account, pulled from
         the NOC API by that mailbox's address (correct domain + gender + contact).
      7. Reads everything back and prints a result table.

    No lock, no daily task, no preview — this is for eyeballing the result. Once you
    confirm each account keeps its own signature after opening Outlook, use the real
    Deploy-Signature.ps1 for rollout.

    Run in the USER context (a normal PowerShell window as the signed-in user).
#>
param(
    [string]   $BaseUrl = 'https://noc.samirgroup.net',
    [string]   $ApiKey  = 'hrk_YJupI2XaM1td7JKKQmrFdeYfqYjzQgsiapZBk8TS',
    [string[]] $Domains = @('samirgroup.com', 'sssegypt.com')
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$acctGuid = '9375CFF0413111d3B88A00104B2A6676'

function Get-Sig {
    param([string]$Upn, [string]$Type)
    $u = '{0}/api/signature?upn={1}&type={2}&format=json&api_key={3}' -f `
        $BaseUrl, [uri]::EscapeDataString($Upn), $Type, [uri]::EscapeDataString($ApiKey)
    return [string](Invoke-RestMethod -Uri $u -Method Get -TimeoutSec 30).html
}

function ConvertTo-PlainText {
    param([string]$Html)
    $t = $Html -replace '(?is)<style.*?</style>', ''
    $t = $t -replace '(?i)<br\s*/?>', "`r`n"
    $t = $t -replace '(?i)</(p|tr|div|h[1-6])>', "`r`n"
    $t = $t -replace '(?s)<[^>]+>', ''
    $t = $t -replace '&nbsp;', ' ' -replace '&amp;', '&' -replace '&bull;', '-' -replace '&#\d+;', ''
    ($t -split "`r`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ }) -join "`r`n"
}

function Write-SigFiles {
    param([string]$Dir, [string]$Name, [string]$Html)
    $enc = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText((Join-Path $Dir "$Name.htm"), "<!DOCTYPE html><html><head><meta charset=""utf-8""></head><body>$Html</body></html>", $enc)
    [System.IO.File]::WriteAllText((Join-Path $Dir "$Name.txt"), (ConvertTo-PlainText $Html), $enc)
}

Write-Host "`n=== Per-account signature CLEAN TEST ===`n" -ForegroundColor Cyan

# 1. Close Outlook -----------------------------------------------------------
Write-Host "1) Closing Outlook..."
Get-Process outlook -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3

# Detect Office version key
$verKey = @('16.0', '15.0') | Where-Object { Test-Path "HKCU:\Software\Microsoft\Office\$_\Outlook" } | Select-Object -First 1
if (-not $verKey) { $verKey = '16.0' }
Write-Host "   Office key: $verKey"

# 2. Wipe the Signatures folder ----------------------------------------------
$sigDir = Join-Path $env:APPDATA 'Microsoft\Signatures'
Write-Host "2) Deleting ALL existing signatures in $sigDir ..."
if (Test-Path $sigDir) {
    Get-ChildItem $sigDir -Force -ErrorAction SilentlyContinue | ForEach-Object {
        try { $_.Attributes = 'Normal' } catch {}
        Remove-Item $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
    }
}
New-Item -ItemType Directory -Path $sigDir -Force | Out-Null

# 3. Enumerate accounts + clear any existing per-account assignment -----------
Write-Host "3) Enumerating Outlook accounts and clearing old assignments..."
$accounts = @()
foreach ($p in Get-ChildItem "HKCU:\Software\Microsoft\Office\$verKey\Outlook\Profiles" -ErrorAction SilentlyContinue) {
    $r = Join-Path $p.PSPath $acctGuid
    if (-not (Test-Path $r)) { continue }
    foreach ($a in Get-ChildItem $r -ErrorAction SilentlyContinue) {
        $pr = Get-ItemProperty $a.PSPath -ErrorAction SilentlyContinue
        # clear whatever signature is on this account
        Remove-ItemProperty -LiteralPath $a.PSPath -Name 'New Signature', 'Reply-Forward Signature' -ErrorAction SilentlyContinue
        $smtp = $null
        foreach ($cand in @($pr.'Email', $pr.'Account Name')) {
            if ($cand -and ($cand -match '^[^@\s]+@[^@\s]+\.[^@\s]+$')) { $smtp = ([string]$cand).Trim(); break }
        }
        if ($smtp) {
            $accounts += [pscustomobject]@{ KeyPath = $a.PSPath; Smtp = $smtp; Domain = ($smtp -split '@')[1].ToLower() }
        }
    }
}
$accounts = @($accounts | Sort-Object Smtp -Unique)
Write-Host ("   Found {0} account(s): {1}" -f $accounts.Count, (($accounts.Smtp) -join ', '))

# 4. Clear global default + policy -------------------------------------------
Write-Host "4) Clearing global default + policy signature..."
Remove-ItemProperty "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings" -Name 'NewSignature', 'ReplySignature' -ErrorAction SilentlyContinue
Remove-Item "HKCU:\Software\Policies\Microsoft\Office\$verKey\Common\MailSettings" -Recurse -Force -ErrorAction SilentlyContinue

# 5. Force roaming/cloud signatures off (local files win) --------------------
Write-Host "5) Forcing roaming signatures off (local wins)..."
New-Item "HKCU:\Software\Microsoft\Office\$verKey\Outlook\Setup" -Force | Out-Null
Set-ItemProperty "HKCU:\Software\Microsoft\Office\$verKey\Outlook\Setup" -Name 'DisableRoamingSignaturesTemporaryToggle' -Value 1 -Type DWord

# 6. Install a per-account signature for each managed account -----------------
Write-Host "6) Installing per-account signatures...`n"
$results = @()
$firstName = $null
foreach ($acct in $accounts) {
    $smtp = [string]$acct.Smtp
    if ($Domains -notcontains $acct.Domain) {
        Write-Host "   - skip $smtp (domain not managed)" -ForegroundColor DarkGray
        continue
    }
    try { $htmlNew = Get-Sig -Upn $smtp -Type 'new_email' }
    catch { Write-Host "   - $smtp : no signature from API ($($_.Exception.Message)) — skipped" -ForegroundColor Yellow; continue }
    try { $htmlReply = Get-Sig -Upn $smtp -Type 'reply' } catch { $htmlReply = $htmlNew }

    $sigNewName   = 'Samir Group ({0})' -f $smtp
    $sigReplyName = 'Samir Group Reply ({0})' -f $smtp
    Write-SigFiles -Dir $sigDir -Name $sigNewName   -Html $htmlNew
    Write-SigFiles -Dir $sigDir -Name $sigReplyName -Html $htmlReply

    Set-ItemProperty -LiteralPath $acct.KeyPath -Name 'New Signature'           -Value $sigNewName   -Type String
    Set-ItemProperty -LiteralPath $acct.KeyPath -Name 'Reply-Forward Signature' -Value $sigReplyName -Type String

    $chk = Get-ItemProperty -LiteralPath $acct.KeyPath -ErrorAction SilentlyContinue
    if (-not $firstName) { $firstName = $sigNewName }
    $results += [pscustomobject]@{
        Account   = $smtp
        Key       = ($acct.KeyPath -replace '.*\\', '')
        Assigned  = $sigNewName
        StoredNew = [string]$chk.'New Signature'
        OK        = ($chk.'New Signature' -eq $sigNewName)
    }
}

# Global default fallback = first managed account's signature
if ($firstName) {
    New-Item "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings" -Force | Out-Null
    Set-ItemProperty "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings" -Name 'NewSignature' -Value $firstName -Type String
}

# 7. Report ------------------------------------------------------------------
Write-Host "=== RESULT (written now, before Outlook opens) ===" -ForegroundColor Cyan
$results | Format-Table Account, Key, StoredNew, OK -AutoSize

Write-Host "Signature files on disk:" -ForegroundColor Cyan
Get-ChildItem $sigDir -Filter *.htm | Select-Object -ExpandProperty Name | ForEach-Object { "   $_" }

Write-Host "`nNEXT:" -ForegroundColor Green
Write-Host "  1. Open Outlook, wait ~1 minute."
Write-Host "  2. New Email -> switch the 'From' between the two accounts -> each should show ITS OWN signature."
Write-Host "  3. Re-run this to re-check the registry (or the diagnostic one-liner). If 'StoredNew' still"
Write-Host "     matches each account after Outlook has been open, per-account is holding.`n"
