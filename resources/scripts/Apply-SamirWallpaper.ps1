<#
.SYNOPSIS
    SamirGroup / SSS managed wallpaper + lock-screen agent.

.DESCRIPTION
    Pulls the per-domain wallpaper manifest from the NOC, downloads the desktop
    and lock-screen images for THIS machine's domain, applies and LOCKS them via
    the PersonalizationCSP (users can no longer change either), mirrors the desktop
    into each loaded user hive (so an in-session repaint cannot revert it), and
    installs a daily scheduled task so the policy is re-checked every day. Because
    it always re-reads the manifest and compares sha256 hashes, replacing a
    wallpaper in the NOC automatically rolls out to every device on its next run.

    Runs as SYSTEM (Intune platform script / scheduled task). Edition-agnostic
    (Win10 1703+ / Win11, Pro or Enterprise).

    Deploy via Intune: Devices > Scripts and remediations > Platform scripts >
    add this .ps1, run in 64-bit PowerShell, run as system. It self-registers a
    daily task, so a single Intune run is enough to keep it current.

.NOTES
    Manifest URL is injected by the NOC when the script is downloaded.
    ASCII-only on purpose: a .ps1 with non-ASCII chars and no BOM is misparsed by
    Windows PowerShell 5.1.
#>

# -- Injected by the NOC -----------------------------------------------
$ManifestUrl = '{{MANIFEST_URL}}'
$SelfUrl     = '{{SELF_URL}}'
$CheckinUrl  = '{{CHECKIN_URL}}'

# -- Constants ---------------------------------------------------------
$Root      = Join-Path $env:ProgramData 'SamirGroup\Wallpaper'
$LogFile   = Join-Path $Root 'apply.log'
$LocalCopy = Join-Path $Root 'Apply-SamirWallpaper.ps1'
$TaskName  = 'SamirGroup Managed Wallpaper'
$CspPath   = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\PersonalizationCSP'
$OverrideKey = 'HKLM:\SOFTWARE\SamirGroup\Wallpaper'   # optional manual pin

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# -- Helpers -----------------------------------------------------------
function Write-Log {
    param([string]$Message, [string]$Level = 'INFO')
    $line = "{0} [{1}] {2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    try { Add-Content -Path $LogFile -Value $line -ErrorAction SilentlyContinue } catch {}
    Write-Output $line
}

function Get-PrimaryUpn {
    # The reliable SSS-vs-SamirGroup discriminator on Entra-joined / Intune devices
    # is the user's UPN suffix (the AD domain is "WORKGROUP" there). Read it as
    # SYSTEM from the most dependable sources, in order of preference.

    # 1) Intune enrollment record - the enrolling user's UPN. Best for managed devices.
    try {
        foreach ($k in (Get-ChildItem 'HKLM:\SOFTWARE\Microsoft\Enrollments' -ErrorAction SilentlyContinue)) {
            $upn = (Get-ItemProperty $k.PSPath -Name 'UPN' -ErrorAction SilentlyContinue).UPN
            if ($upn -and $upn -match '@') { return $upn }
        }
    } catch {}

    # 2) Entra IdentityStore cache - the primary signed-in user.
    try {
        $base = 'HKLM:\SOFTWARE\Microsoft\IdentityStore\Cache'
        foreach ($sidk in (Get-ChildItem $base -ErrorAction SilentlyContinue)) {
            $sid = $sidk.PSChildName
            $u = (Get-ItemProperty "$base\$sid\IdentityCache\$sid" -Name 'UserName' -ErrorAction SilentlyContinue).UserName
            if ($u -and $u -match '@') { return $u }
        }
    } catch {}

    # 3) LogonUI's last Entra user (format AzureAD\\user@domain).
    try {
        $last = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Authentication\LogonUI' -Name 'LastLoggedOnUser' -ErrorAction SilentlyContinue).LastLoggedOnUser
        if ($last -and $last -match '@') { return ($last -replace '^.*\\', '') }
    } catch {}

    return $null
}

function Get-DomainInfo {
    # Returns: Haystack (everything we know, for substring matching) and Detected
    # (the single best human-readable domain to display / report). Manual override wins.
    $parts = New-Object System.Collections.Generic.List[string]
    $detected = $null

    try {
        $ov = (Get-ItemProperty -Path $OverrideKey -Name 'DomainOverride' -ErrorAction Stop).DomainOverride
        if ($ov) { return [pscustomobject]@{ Haystack = $ov.ToLower(); Detected = $ov.ToLower() } }
    } catch {}

    # User UPN first - the strongest signal for Entra tenants with multiple domains.
    $upn = Get-PrimaryUpn
    if ($upn) {
        $parts.Add($upn)
        $detected = ($upn -split '@')[-1].ToLower()
    }

    try {
        $cs = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction Stop
        if ($cs.Domain) {
            $parts.Add($cs.Domain)
            if (-not $detected -and $cs.PartOfDomain) { $detected = $cs.Domain.ToLower() }   # on-prem AD
        }
        if ($cs.UserName) { $parts.Add($cs.UserName) }
    } catch {}

    try {
        $dns = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName
        if ($dns) { $parts.Add($dns) }
    } catch {}

    # dsregcmd carries the Entra tenant's verified domain for AAD devices.
    try {
        $ds = & dsregcmd /status 2>$null
        if ($ds) { $parts.Add(($ds -join ' ')) }
    } catch {}

    if (-not $detected) { $detected = 'workgroup' }
    return [pscustomobject]@{ Haystack = (($parts -join ' ').ToLower()); Detected = $detected }
}

function Save-IfChanged {
    # Download $Url to $Dest only when the on-disk file's sha256 differs from
    # $ExpectedHash. Returns $true on success (already-current or freshly written).
    param([string]$Url, [string]$Dest, [string]$ExpectedHash)

    if (-not $Url) { return $false }

    if ((Test-Path $Dest) -and $ExpectedHash) {
        $current = (Get-FileHash -Path $Dest -Algorithm SHA256).Hash
        if ($current -ieq $ExpectedHash) { return $true }   # already current
    }

    try {
        # cache-bust any proxy by appending the hash
        $sep = if ($Url -match '\?') { '&' } else { '?' }
        $bust = if ($ExpectedHash) { $sep + 'v=' + $ExpectedHash } else { '' }
        Invoke-WebRequest -Uri ($Url + $bust) -OutFile $Dest -UseBasicParsing -TimeoutSec 120
        Write-Log "Downloaded $Url -> $Dest"
        return $true
    } catch {
        Write-Log "Download FAILED $Url : $($_.Exception.Message)" 'ERROR'
        return (Test-Path $Dest)   # fall back to whatever we already had
    }
}

function Set-CspImage {
    param([string]$Kind, [string]$Path)   # Kind = 'Desktop' | 'LockScreen'
    if (-not (Test-Path $CspPath)) { New-Item -Path $CspPath -Force | Out-Null }
    New-ItemProperty -Path $CspPath -Name "${Kind}ImageStatus" -Value 1     -PropertyType DWord  -Force | Out-Null
    New-ItemProperty -Path $CspPath -Name "${Kind}ImagePath"   -Value $Path -PropertyType String -Force | Out-Null
    New-ItemProperty -Path $CspPath -Name "${Kind}ImageUrl"    -Value $Path -PropertyType String -Force | Out-Null
    Write-Log "$Kind locked via CSP -> $Path"
}

function Set-DesktopForLoadedUsers {
    # PersonalizationCSP enforces the desktop only at LOGON; the in-session desktop
    # is painted from each user's own HKCU\Control Panel\Desktop. If we set only the
    # CSP, a mid-session repaint (lock/unlock, theme refresh) reverts to the user's
    # OLD wallpaper. So mirror the image into every loaded user hive and lock it
    # there too - this is what stops the desktop from snapping back.
    param([string]$Path)

    $sids = @(Get-ChildItem 'Registry::HKEY_USERS' -ErrorAction SilentlyContinue |
        Where-Object { $_.PSChildName -match '^S-1-5-21-\d' -and $_.PSChildName -notmatch '_Classes$' } |
        Select-Object -ExpandProperty PSChildName)
    $sids += '.DEFAULT'   # logon / welcome screen profile

    foreach ($sid in $sids) {
        try {
            $desk = "Registry::HKEY_USERS\$sid\Control Panel\Desktop"
            if (-not (Test-Path $desk)) { New-Item -Path $desk -Force | Out-Null }
            Set-ItemProperty -Path $desk -Name 'WallPaper'      -Value $Path -Type String
            Set-ItemProperty -Path $desk -Name 'WallpaperStyle' -Value '10'  -Type String   # 10 = Fill
            Set-ItemProperty -Path $desk -Name 'TileWallpaper'  -Value '0'   -Type String

            $lock = "Registry::HKEY_USERS\$sid\Software\Microsoft\Windows\CurrentVersion\Policies\ActiveDesktop"
            if (-not (Test-Path $lock)) { New-Item -Path $lock -Force | Out-Null }
            New-ItemProperty -Path $lock -Name 'NoChangingWallPaper' -Value 1 -PropertyType DWord -Force | Out-Null
        } catch {
            Write-Log "Per-user desktop set failed for $sid : $($_.Exception.Message)" 'WARN'
        }
    }
    Write-Log "Desktop mirrored into $($sids.Count) user hive(s)"
}

function Send-Checkin {
    # Report what we applied so the NOC can list this device. Best-effort: a
    # failure here must never stop the wallpaper from being applied.
    param($Set, [string]$Detected)
    if (-not $CheckinUrl -or $CheckinUrl -match '\{\{') { return }
    try {
        $os = (Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue)
        $body = @{
            hostname        = $env:COMPUTERNAME
            domain_detected = $Detected
            set_label       = $Set.label
            domain_match    = $Set.domain_match
            desktop_hash    = $Set.desktop_hash
            lockscreen_hash = $Set.lockscreen_hash
            os_version      = if ($os) { "$($os.Caption) $($os.Version)" } else { '' }
        } | ConvertTo-Json -Compress
        Invoke-RestMethod -Uri $CheckinUrl -Method Post -Body $body -ContentType 'application/json' -TimeoutSec 30 | Out-Null
        Write-Log 'Check-in sent to NOC'
    } catch {
        Write-Log "Check-in FAILED: $($_.Exception.Message)" 'WARN'
    }
}

function Install-DailyTask {
    # Re-run this agent every day at 09:00 and at every user logon, as SYSTEM.
    try {
        # Keep a local, self-contained copy for the task to run.
        if ($PSCommandPath -and (Test-Path $PSCommandPath) -and ($PSCommandPath -ne $LocalCopy)) {
            Copy-Item -Path $PSCommandPath -Destination $LocalCopy -Force
        } elseif (-not (Test-Path $LocalCopy) -and $SelfUrl -and $SelfUrl -notmatch '\{\{') {
            Invoke-WebRequest -Uri $SelfUrl -OutFile $LocalCopy -UseBasicParsing -TimeoutSec 60
        }
        if (-not (Test-Path $LocalCopy)) { Write-Log 'No local script copy; skipping task install' 'WARN'; return }

        $action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
                    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$LocalCopy`""
        $trigDay = New-ScheduledTaskTrigger -Daily -At ([datetime]'09:00')
        $trigLog = New-ScheduledTaskTrigger -AtLogOn
        $princ   = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
        $set     = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries `
                    -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 15)

        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($trigDay, $trigLog) `
            -Principal $princ -Settings $set -Force | Out-Null
        Write-Log "Scheduled task '$TaskName' registered (daily 09:00 + at logon)"
    } catch {
        Write-Log "Task install FAILED: $($_.Exception.Message)" 'ERROR'
    }
}

# -- Main --------------------------------------------------------------
New-Item -Path $Root -ItemType Directory -Force | Out-Null
Write-Log '==== run start ===='

if ($ManifestUrl -match '\{\{') {
    Write-Log 'Manifest URL was not injected - download the script from the NOC, do not edit it.' 'ERROR'
    exit 1
}

try {
    $manifest = Invoke-RestMethod -Uri $ManifestUrl -UseBasicParsing -TimeoutSec 60
} catch {
    Write-Log "Manifest fetch FAILED: $($_.Exception.Message)" 'ERROR'
    # Still (re)install the task so we retry tomorrow even if the NOC was down.
    Install-DailyTask
    exit 1
}

$dom = Get-DomainInfo
$haystack = $dom.Haystack
$detected = $dom.Detected
Write-Log "Detected domain: $detected"

# Match in order of confidence: the precise detected domain first (the Entra
# tenant domain leaks into the broad haystack on every device, so trust the UPN
# suffix), then the broad haystack, then the default fallback.
$set = $manifest.sets | Where-Object { $_.domain_match -and ($detected -like "*$($_.domain_match.ToLower())*") } | Select-Object -First 1
if (-not $set) {
    $set = $manifest.sets | Where-Object { $_.domain_match -and ($haystack -like "*$($_.domain_match.ToLower())*") } | Select-Object -First 1
}
if (-not $set) {
    $set = $manifest.sets | Where-Object { $_.is_default } | Select-Object -First 1
    if ($set) { Write-Log "No domain match - using default set '$($set.label)'" }
}
if (-not $set) { Write-Log 'No matching or default wallpaper set in manifest.' 'WARN'; Install-DailyTask; exit 0 }

Write-Log "Selected set: $($set.label) [$($set.domain_match)]"

# Desktop - CSP (enforce at logon + lock) AND per-user hive (stop in-session revert)
if ($set.desktop_url) {
    $ext  = [IO.Path]::GetExtension(([Uri]$set.desktop_url).AbsolutePath); if (-not $ext) { $ext = '.jpg' }
    $dest = Join-Path $Root "desktop$ext"
    if (Save-IfChanged -Url $set.desktop_url -Dest $dest -ExpectedHash $set.desktop_hash) {
        Set-CspImage 'Desktop' $dest
        Set-DesktopForLoadedUsers $dest
    }
}

# Lock screen
if ($set.lockscreen_url) {
    $ext  = [IO.Path]::GetExtension(([Uri]$set.lockscreen_url).AbsolutePath); if (-not $ext) { $ext = '.jpg' }
    $dest = Join-Path $Root "lockscreen$ext"
    if (Save-IfChanged -Url $set.lockscreen_url -Dest $dest -ExpectedHash $set.lockscreen_hash) { Set-CspImage 'LockScreen' $dest }
}

# Nudge the current session to repaint (full effect on next sign-in).
try { rundll32.exe user32.dll, UpdatePerUserSystemParameters 1, True } catch {}

Send-Checkin -Set $set -Detected $detected
Install-DailyTask
Write-Log '==== run done ===='
exit 0
