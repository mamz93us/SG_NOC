<#
.SYNOPSIS
    Deploys the SamirGroup / SSS Egypt Outlook email signature to EVERY account the
    signed-in user has configured in classic Outlook (per-account signatures).

.DESCRIPTION
    Runs in the USER context (Intune platform script or Proactive Remediation).
    Enumerates every mail account in the Outlook profile, and for EACH account pulls
    that mailbox's personalised signature HTML from the NOC signature API (which selects
    the template by the mailbox's email domain + the employee's gender, and fills in
    name / title / branch / email from that mailbox's profile). It then assigns each
    account its own New-mail and Reply/Forward signature.

    This is what makes multi-account / cross-domain users correct: a user with both a
    @samirgroup.com and a @sssegypt.com mailbox gets the Samir Group signature when
    sending from the Samir account and the SSS signature when sending from the SSS
    account -- automatically, per account.

    If no Outlook accounts are found yet (profile not built), it falls back to a single
    global default resolved from the signed-in UPN.

.NOTES
    - Targets CLASSIC Outlook for Windows (desktop). The "new Outlook" (Monarch) uses
      cloud/roaming signatures and ignores these local files -- those clients are covered
      by the server-side transport rules instead.
    - Must run in user context: Intune > Scripts > "Run this script using the logged-on
      credentials = Yes". Do NOT run as SYSTEM (signatures live in HKCU + %APPDATA%).
    - Re-running is safe/idempotent; it overwrites the files and registry each time.
    - Per-account assignment writes 'New Signature' / 'Reply-Forward Signature' in each
      account key under ...\Outlook\Profiles\<p>\9375CFF0413111d3B88A00104B2A6676\<acct>.
      Modern Outlook (16.0) stores these as REG_SZ; the script matches an existing value's
      type when present, else writes REG_SZ.
#>

# -----------------------------------------------------------------------------
# CONFIG -- set these before packaging
# -----------------------------------------------------------------------------
param(
    [string]   $BaseUrl        = 'https://noc.samirgroup.net',
    [string]   $ApiKey         = 'hrk_YJupI2XaM1td7JKKQmrFdeYfqYjzQgsiapZBk8TS',   # hrk_... (scope: signature)
    [string]   $SignatureName  = 'Samir Group',                          # base label; account SMTP is appended per account
    [string]   $ReplyName      = 'Samir Group Reply',                    # base label for the reply slot
    [string[]] $Domains        = @('samirgroup.com', 'sssegypt.com', 'oriana-sa.com'),   # only these mail domains get a managed signature; others left untouched
    [string]   $Upn            = '',                                     # optional override for the single-account fallback; auto-detected if blank
    [switch]   $NoLock,                                                  # pass to skip the read-only/policy lock
    [switch]   $KeepOtherSignatures,                                     # pass to NOT delete pre-existing (non-managed) signatures
    [switch]   $NoPreview,                                               # pass to suppress the branded preview window
    [switch]   $NoDailyTask,                                            # pass to SKIP the daily self-refresh Scheduled Task (registered by default)
    [switch]   $RemoveClientSignature                                   # UNINSTALL: remove our local signatures + per-account assignments + lock + daily task, then exit. Use for cross-domain users handled by the server-side transport rule.
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# --- Logging -----------------------------------------------------------------
$LogDir  = Join-Path $env:LOCALAPPDATA 'SamirGroup\SignatureDeploy'
$LogFile = Join-Path $LogDir 'deploy.log'
New-Item -ItemType Directory -Path $LogDir -Force | Out-Null

function Write-Log {
    param([string]$Message, [string]$Level = 'INFO')
    $line = "{0}  [{1}]  {2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host $line
}

# --- Resolve the signed-in user's UPN (single-account fallback only) ----------
function Resolve-Upn {
    if ($Upn -and $Upn -match '@') { return $Upn.Trim() }

    # 1) whoami /upn -- reliable on Azure AD-joined / hybrid-joined machines
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

# --- Enumerate every mail account in the Outlook profile(s) -------------------
# Returns objects: KeyPath (registry path of the account), Smtp, Domain.
function Get-OutlookAccounts {
    param([string]$VerKey)
    $found = @()
    $profilesRoot = "HKCU:\Software\Microsoft\Office\$VerKey\Outlook\Profiles"
    if (-not (Test-Path $profilesRoot)) { return $found }
    $acctGuid = '9375CFF0413111d3B88A00104B2A6676'   # Outlook account container

    foreach ($profile in Get-ChildItem $profilesRoot -ErrorAction SilentlyContinue) {
        $acctRoot = Join-Path $profile.PSPath $acctGuid
        if (-not (Test-Path $acctRoot)) { continue }
        foreach ($acct in Get-ChildItem $acctRoot -ErrorAction SilentlyContinue) {
            $p = Get-ItemProperty $acct.PSPath -ErrorAction SilentlyContinue
            if (-not $p) { continue }
            # SMTP address: prefer the 'Email' value, fall back to 'Account Name'.
            $smtp = $null
            foreach ($cand in @($p.'Email', $p.'Account Name')) {
                if ($cand -and ($cand -match '^[^@\s]+@[^@\s]+\.[^@\s]+$')) { $smtp = ([string]$cand).Trim(); break }
            }
            if (-not $smtp) { continue }
            $found += [pscustomobject]@{
                KeyPath = $acct.PSPath
                Smtp    = $smtp
                Domain  = ($smtp -split '@')[1].ToLower()
            }
        }
    }
    # De-dupe: the same account can appear under multiple profiles.
    $found | Sort-Object -Property @{Expression={$_.Smtp}}, @{Expression={$_.KeyPath}} -Unique
}

# --- Assign a signature to a single account key ------------------------------
# Modern Outlook (16.0) stores these as REG_SZ. We drop any existing value first so
# a stale type can't linger, write REG_SZ, then read back and return the stored values
# so the caller can verify exactly what landed in THIS key.
function Set-AccountSignature {
    param([string]$KeyPath, [string]$NewSigName, [string]$ReplySigName)
    Remove-ItemProperty -LiteralPath $KeyPath -Name 'New Signature', 'Reply-Forward Signature' -ErrorAction SilentlyContinue
    Set-ItemProperty -LiteralPath $KeyPath -Name 'New Signature'           -Value $NewSigName   -Type String
    Set-ItemProperty -LiteralPath $KeyPath -Name 'Reply-Forward Signature' -Value $ReplySigName -Type String
    $chk = Get-ItemProperty -LiteralPath $KeyPath -ErrorAction SilentlyContinue
    return @([string]$chk.'New Signature', [string]$chk.'Reply-Forward Signature')
}

# --- Fetch rendered signature HTML from the NOC API --------------------------
function Get-SignatureHtml {
    param([string]$UserPrincipalName, [string]$Type)   # Type: new_email | reply
    $uri = "{0}/api/signature?upn={1}&type={2}&format=json&api_key={3}" -f `
        $BaseUrl, [uri]::EscapeDataString($UserPrincipalName), $Type, [uri]::EscapeDataString($ApiKey)
    $resp = Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 30
    if (-not $resp.html) { throw "API returned no HTML for type '$Type'." }
    return [string]$resp.html
}

# --- Derive a plain-text version for the .txt fallback -----------------------
function ConvertTo-PlainText {
    param([string]$Html)
    $t = $Html -replace '(?is)<style.*?</style>', ''
    $t = $t -replace '(?i)<br\s*/?>', "`r`n"
    $t = $t -replace '(?i)</(p|tr|div|h[1-6])>', "`r`n"
    $t = $t -replace '(?s)<[^>]+>', ''
    $t = $t -replace '&nbsp;', ' ' -replace '&amp;', '&' -replace '&bull;', '-' -replace '&#\d+;', ''
    ($t -split "`r`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ }) -join "`r`n"
}

# --- Write one signature (.htm + .txt), clearing any prior read-only flag -----
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

# --- Branded preview page shown to the user after install --------------------
function Show-SignaturePreview {
    param([array]$Cards, [string]$Dir)   # each Card: @{ Smtp; New; Reply }
    $body = ''
    foreach ($c in $Cards) {
        $body += @"
  <div class="acct">$($c.Smtp)</div>
  <div class="card"><h2>New email</h2><div class="sig">$($c.New)</div></div>
  <div class="card"><h2>Reply &amp; forward</h2><div class="sig">$($c.Reply)</div></div>
"@
    }
    $page = @"
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Your Email Signatures</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;background:#f3f4f6;margin:0;padding:32px;color:#1f2937;}
  .wrap{max-width:720px;margin:0 auto;}
  .banner{background:#d81f2a;color:#fff;border-radius:12px;padding:20px 24px;display:flex;align-items:center;gap:16px;}
  .banner .tick{font-size:30px;line-height:1;}
  .banner h1{font-size:18px;margin:0;font-weight:600;}
  .banner p{margin:3px 0 0;font-size:13px;opacity:.92;}
  .acct{margin:22px 0 6px;font-size:12px;font-weight:600;color:#d81f2a;letter-spacing:.3px;}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-top:8px;}
  .card h2{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin:0;padding:13px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
  .card .sig{padding:22px 24px;}
  .note{margin-top:22px;font-size:12px;color:#6b7280;text-align:center;line-height:1.7;}
</style></head><body><div class="wrap">
  <div class="banner"><div class="tick">&#10003;</div>
    <div><h1>Your Samir Group email signatures are ready</h1>
    <p>Each of your Outlook accounts has been set up automatically and is managed by IT.</p></div></div>
$body
  <p class="note">These signatures are set and maintained by Samir Group IT.<br>Please do not edit or delete them &mdash; any changes are reset automatically. Questions? Contact the IT Service Desk.</p>
</div></body></html>
"@
    $path = Join-Path $Dir 'preview.html'
    [System.IO.File]::WriteAllText($path, $page, [System.Text.UTF8Encoding]::new($false))
    Start-Process $path | Out-Null
    return $path
}

# -----------------------------------------------------------------------------
# MAIN
# -----------------------------------------------------------------------------
try {
    Write-Log "=== Signature deploy started (user: $env:USERNAME) ==="
    $lock = -not $NoLock.IsPresent

    # Signatures live in a fixed, locale-independent folder.
    $sigDir = Join-Path $env:APPDATA 'Microsoft\Signatures'
    New-Item -ItemType Directory -Path $sigDir -Force | Out-Null

    # Detect installed Outlook version key (16.0 = 2016/2019/2021/365, 15.0 = 2013)
    $verKey = @('16.0','15.0') | Where-Object { Test-Path "HKCU:\Software\Microsoft\Office\$_\Outlook" } | Select-Object -First 1
    if (-not $verKey) { $verKey = '16.0' }
    Write-Log "Using Office version key: $verKey"

    # ---- UNINSTALL MODE: strip our client footprint and hand the user to the server rule ----
    # For cross-domain / multi-account users whose signatures are stamped server-side by the
    # Exchange transport rule. Removes local files, per-account assignments, the global default,
    # the policy lock, and the daily task. Does NOT touch the cloud/roaming signature (no admin
    # API) -- the user clears that once in OWA.
    if ($RemoveClientSignature.IsPresent) {
        # Delete our managed signature files (both new "Samir Group*" and legacy "SamirGroup*").
        Get-ChildItem $sigDir -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like 'Samir Group*' -or $_.Name -like 'SamirGroup*' } |
            ForEach-Object { try { $_.IsReadOnly = $false } catch {}; Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue }

        # Clear the per-account signature assignments on every account.
        foreach ($acct in @(Get-OutlookAccounts -VerKey $verKey)) {
            Remove-ItemProperty -LiteralPath $acct.KeyPath -Name 'New Signature', 'Reply-Forward Signature' -ErrorAction SilentlyContinue
        }

        # Clear the global default + the policy-forced lock + the disabled compose button.
        Remove-ItemProperty -Path "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings" -Name 'NewSignature', 'ReplySignature' -ErrorAction SilentlyContinue
        Remove-Item -Path "HKCU:\Software\Policies\Microsoft\Office\$verKey\Common\MailSettings" -Recurse -Force -ErrorAction SilentlyContinue
        Remove-Item -Path "HKCU:\Software\Policies\Microsoft\Office\$verKey\Outlook\DisabledCmdBarItemsList" -Recurse -Force -ErrorAction SilentlyContinue

        # Remove the daily self-refresh task.
        Unregister-ScheduledTask -TaskName 'SG-Signature-Refresh' -Confirm:$false -ErrorAction SilentlyContinue

        Write-Log "=== RemoveClientSignature done: local signatures, per-account assignments, lock and daily task removed. ==="
        Write-Log "Reminder: the user must clear their cloud/roaming signatures once in OWA; the transport rule then stamps by sending domain."
        exit 0
    }

    $accounts = @(Get-OutlookAccounts -VerKey $verKey)
    Write-Log ("Outlook accounts found: {0}{1}" -f $accounts.Count, $(if ($accounts.Count) { ' -> ' + (($accounts.Smtp) -join ', ') } else { '' }))

    $keepNames   = @()   # signature names we manage (never delete these)
    $cards       = @()   # for the preview page (one per assigned account)
    $hashParts   = @()   # for change detection
    $assigned    = 0
    $primarySig  = $null # @{ NewName; ReplyName } used for the global default fallback
    $primaryUpn  = (Resolve-Upn)

    if ($accounts.Count -gt 0) {
        # ---- PER-ACCOUNT: assign each account its own signature -------------
        # NOTE: PowerShell variable names are CASE-INSENSITIVE, so local names here must
        # not collide with the $SignatureName / $ReplyName params (a $replyName local
        # would silently mutate the $ReplyName param across iterations). Hence $sig*.
        $sigBySmtp = @{}   # smtp -> @{ NewHtml; ReplyHtml; NewName; ReplyName }
        foreach ($acct in $accounts) {
            $smtp = [string]$acct.Smtp
            if ($Domains -and ($Domains -notcontains $acct.Domain)) {
                Write-Log "Skipping $smtp (domain '$($acct.Domain)' not in managed list)."
                continue
            }
            if (-not $sigBySmtp.ContainsKey($smtp)) {
                try {
                    $htmlNew = Get-SignatureHtml -UserPrincipalName $smtp -Type 'new_email'
                } catch {
                    Write-Log ("No signature for $smtp : " + $_.Exception.Message + " -- leaving this account untouched.") 'WARN'
                    continue
                }
                try   { $htmlReply = Get-SignatureHtml -UserPrincipalName $smtp -Type 'reply' }
                catch { $htmlReply = $htmlNew; Write-Log "No reply-specific template for $smtp; reusing new-mail signature." 'WARN' }

                $sigNewName   = ('{0} ({1})' -f $SignatureName, $smtp)
                $sigReplyName = ('{0} ({1})' -f $ReplyName, $smtp)
                Write-SignatureFiles -Dir $sigDir -Name $sigNewName   -Html $htmlNew   -ReadOnly:$lock
                Write-SignatureFiles -Dir $sigDir -Name $sigReplyName -Html $htmlReply -ReadOnly:$lock
                $sigBySmtp[$smtp] = @{ NewHtml = $htmlNew; ReplyHtml = $htmlReply; NewName = $sigNewName; ReplyName = $sigReplyName }

                $keepNames += $sigNewName; $keepNames += $sigReplyName
                $hashParts += ($smtp + '|' + $htmlNew + '|' + $htmlReply)
                $cards     += @{ Smtp = $smtp; New = $htmlNew; Reply = $htmlReply }
            }
            $sig    = $sigBySmtp[$smtp]
            $stored = Set-AccountSignature -KeyPath $acct.KeyPath -NewSigName $sig.NewName -ReplySigName $sig.ReplyName
            $assigned++
            Write-Log ("Assigned {0}  [{1}]  want New='{2}'  stored New='{3}'" -f $smtp, ($acct.KeyPath -replace '.*\\', ''), $sig.NewName, $stored[0])

            # Pick the primary (matches the signed-in UPN) for the global default fallback.
            if (-not $primarySig -or ($primaryUpn -and ($smtp -ieq $primaryUpn))) {
                $primarySig = @{ NewName = $sig.NewName; ReplyName = $sig.ReplyName }
            }
        }
        if ($assigned -eq 0) {
            Write-Log "No managed accounts matched; nothing assigned." 'WARN'
        }
    }

    # ---- FALLBACK: no accounts in the profile yet -> single global default ----
    if ($accounts.Count -eq 0 -or $assigned -eq 0) {
        $userUpn = $primaryUpn
        if (-not $userUpn) {
            Write-Log "No Outlook accounts and could not resolve a UPN; aborting." 'ERROR'
            exit 1
        }
        Write-Log "Fallback single-account mode for UPN: $userUpn"
        $new = Get-SignatureHtml -UserPrincipalName $userUpn -Type 'new_email'
        try   { $reply = Get-SignatureHtml -UserPrincipalName $userUpn -Type 'reply' }
        catch { $reply = $new; Write-Log "No reply-specific template; reusing new-mail signature." 'WARN' }
        Write-SignatureFiles -Dir $sigDir -Name $SignatureName -Html $new   -ReadOnly:$lock
        Write-SignatureFiles -Dir $sigDir -Name $ReplyName     -Html $reply -ReadOnly:$lock
        $keepNames += $SignatureName; $keepNames += $ReplyName
        $hashParts += ($userUpn + '|' + $new + '|' + $reply)
        $cards     += @{ Smtp = $userUpn; New = $new; Reply = $reply }
        $primarySig = @{ NewName = $SignatureName; ReplyName = $ReplyName }
    }

    # ---- Remove any OTHER (old / personal) signatures --------------------------
    if (-not $KeepOtherSignatures.IsPresent) {
        Get-ChildItem $sigDir -File -ErrorAction SilentlyContinue | Where-Object {
            $keepNames -notcontains [IO.Path]::GetFileNameWithoutExtension($_.Name)
        } | ForEach-Object { try { $_.IsReadOnly = $false } catch {}; Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue }
        Get-ChildItem $sigDir -Directory -ErrorAction SilentlyContinue | Where-Object {
            $keepNames -notcontains ($_.Name -replace '_files$','')
        } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        Write-Log "Removed other/old signatures (kept: $($keepNames -join ', '))."
    }

    # ---- Default signature slot ----------------------------------------------
    # A global Common\MailSettings default OVERRIDES each account's per-account New/Reply
    # signature for that slot (so every account would show the one global signature on new
    # mail). When we've set per-account assignments we must CLEAR the global default and let
    # each account use its own. Only the no-accounts fallback sets a global.
    $ms = "HKCU:\Software\Microsoft\Office\$verKey\Common\MailSettings"
    New-Item -Path $ms -Force | Out-Null
    if ($assigned -gt 0) {
        Remove-ItemProperty -Path $ms -Name 'NewSignature', 'ReplySignature' -ErrorAction SilentlyContinue
    } else {
        Set-ItemProperty -Path $ms -Name 'NewSignature'   -Value $primarySig.NewName   -Type String
        Set-ItemProperty -Path $ms -Name 'ReplySignature' -Value $primarySig.ReplyName -Type String
    }

    # Make local files win over M365 cloud/roaming signatures.
    $setup = "HKCU:\Software\Microsoft\Office\$verKey\Outlook\Setup"
    New-Item -Path $setup -Force | Out-Null
    Set-ItemProperty -Path $setup -Name 'DisableRoamingSignaturesTemporaryToggle' -Value 1 -Type DWord

    # ---- Lock ----------------------------------------------------------------
    # Multi-account: a single policy-forced signature can't express per-account, so we
    #   enforce via read-only files + a disabled compose button + the daily re-assert task.
    # Single account: also pin the selection through the POLICY hive (strongest lock).
    if ($lock) {
        $dis = "HKCU:\Software\Policies\Microsoft\Office\$verKey\Outlook\DisabledCmdBarItemsList"
        New-Item -Path $dis -Force | Out-Null
        Set-ItemProperty -Path $dis -Name 'TCID1' -Value '5608' -Type String   # compose-window Signature button

        if ($assigned -gt 0) {
            # Per-account is in force. A policy-forced global default ALSO overrides per-account,
            # so remove it; enforcement is read-only files + disabled button + daily re-assert.
            Remove-ItemProperty -Path "HKCU:\Software\Policies\Microsoft\Office\$verKey\Common\MailSettings" `
                -Name 'NewSignature', 'ReplySignature' -ErrorAction SilentlyContinue
            Write-Log "Locked (per-account): files read-only, Signature button disabled, per-account re-asserted daily."
        } else {
            # No per-account assignments (single global fallback) -> pin the selection via policy.
            $pol = "HKCU:\Software\Policies\Microsoft\Office\$verKey\Common\MailSettings"
            New-Item -Path $pol -Force | Out-Null
            Set-ItemProperty -Path $pol -Name 'NewSignature'   -Value $primarySig.NewName   -Type String
            Set-ItemProperty -Path $pol -Name 'ReplySignature' -Value $primarySig.ReplyName -Type String
            Write-Log "Locked (single global): files read-only, selection forced via policy, Signature button disabled."
        }
    }

    # ---- Change detection (for the preview pop) ------------------------------
    $hashFile = Join-Path $LogDir 'last.hash'
    $oldHash  = (Get-Content -Path $hashFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    $blob     = ($hashParts -join "`n")
    $hash     = (Get-FileHash -Algorithm SHA256 -InputStream ([IO.MemoryStream]::new([Text.Encoding]::UTF8.GetBytes($blob)))).Hash
    Set-Content -Path $hashFile -Value $hash -Encoding ASCII
    $changed  = ($oldHash -ne $hash)

    # Show the branded preview only on first install / when a signature changed.
    if (-not $NoPreview.IsPresent -and $changed -and $cards.Count -gt 0) {
        try {
            $p = Show-SignaturePreview -Cards $cards -Dir $LogDir
            Write-Log "Opened signature preview: $p"
        } catch {
            Write-Log ("Preview failed (non-fatal): " + $_.Exception.Message) 'WARN'
        }
    }

    # ---- Daily self-refresh Scheduled Task -----------------------------------
    # Re-runs this script so template/account edits apply without Proactive Remediations.
    # On by default (Intune platform scripts pass no args); -NoDailyTask opts out.
    if (-not $NoDailyTask.IsPresent) {
        try {
            $stableScript = Join-Path $LogDir 'Deploy-Signature.ps1'
            if ($PSCommandPath -and ($PSCommandPath -ne $stableScript)) {
                Copy-Item -Path $PSCommandPath -Destination $stableScript -Force
            }
            $taskName = 'SG-Signature-Refresh'
            $arg = "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$stableScript`""
            $action   = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arg
            $triggers = @(
                (New-ScheduledTaskTrigger -Daily -At 9am),
                (New-ScheduledTaskTrigger -AtLogOn)
            )
            $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
            Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers `
                -Settings $settings -Force -ErrorAction Stop | Out-Null
            Write-Log "Registered daily refresh task '$taskName' -> $stableScript"
        } catch {
            Write-Log ("Could not register daily task (non-fatal): " + $_.Exception.Message) 'WARN'
        }
    }

    Write-Log "=== Signature deploy completed OK (accounts assigned: $assigned) ==="
    exit 0
}
catch {
    Write-Log ("FAILED: " + $_.Exception.Message) 'ERROR'
    exit 1
}
