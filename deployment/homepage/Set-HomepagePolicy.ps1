<#
.SYNOPSIS
    Points Edge and Chrome at the Samir Group employee home portal on startup.

.DESCRIPTION
    Fallback for machines Intune does not manage — the Settings Catalog profile
    described in README.md is the preferred route.

    Writes machine-wide policy keys under HKLM, so the setting applies to every
    user on the box and the browser reports itself as managed.

    The new-tab page is deliberately NOT touched: people open tabs to search,
    and putting the portal there makes it an obstacle rather than a front door.

    For Chrome it also sets CloudAPAuthEnabled. Without that, Chrome cannot use
    the device's Primary Refresh Token, the portal's silent sign-in fails, and
    every Chrome user sees a sign-in button while Edge users see their portal.

.PARAMETER Url
    Portal URL. Defaults to https://home.samirgroup.net

.PARAMETER Browsers
    Which browsers to configure: Edge, Chrome, or Both (default).

.PARAMETER Remove
    Remove the policies this script sets, restoring browser defaults.

.EXAMPLE
    .\Set-HomepagePolicy.ps1

.EXAMPLE
    .\Set-HomepagePolicy.ps1 -Url "https://start.samirgroup.com" -Browsers Edge

.EXAMPLE
    .\Set-HomepagePolicy.ps1 -Remove
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [ValidatePattern('^https?://')]
    [string]$Url = 'https://home.samirgroup.net',

    [ValidateSet('Edge', 'Chrome', 'Both')]
    [string]$Browsers = 'Both',

    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

$EdgeKey   = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge'
$ChromeKey = 'HKLM:\SOFTWARE\Policies\Google\Chrome'

function Test-Elevated {
    $identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Elevated)) {
    throw 'This script writes to HKLM and must be run from an elevated PowerShell session.'
}

function Set-PolicyValue {
    param(
        [string]$Key,
        [string]$Name,
        $Value,
        [Microsoft.Win32.RegistryValueKind]$Kind
    )

    if (-not (Test-Path $Key)) {
        if ($PSCmdlet.ShouldProcess($Key, 'Create registry key')) {
            New-Item -Path $Key -Force | Out-Null
        }
    }

    if ($PSCmdlet.ShouldProcess("$Key\$Name", "Set to '$Value'")) {
        New-ItemProperty -Path $Key -Name $Name -Value $Value -PropertyType $Kind -Force | Out-Null
        Write-Host ("  {0,-24} = {1}" -f $Name, $Value)
    }
}

function Remove-PolicyValue {
    param([string]$Key, [string]$Name)

    if (-not (Test-Path $Key)) { return }

    # -ErrorAction SilentlyContinue suppresses the message but still trips the
    # exit code, so promote to terminating and swallow it instead.
    try {
        Remove-ItemProperty -Path $Key -Name $Name -ErrorAction Stop
        Write-Host ("  removed {0}" -f $Name)
    } catch {
        # Not present — nothing to undo.
    }
}

function Set-BrowserPolicy {
    param(
        [string]$Name,
        [string]$Key,
        [string]$StartupUrlsSubkey,
        [bool]$IncludeCloudApAuth
    )

    Write-Host "`n$Name" -ForegroundColor Cyan

    if ($Remove) {
        Remove-PolicyValue -Key $Key -Name 'RestoreOnStartup'
        Remove-PolicyValue -Key $Key -Name 'HomepageLocation'
        Remove-PolicyValue -Key $Key -Name 'HomepageIsNewTabPage'
        Remove-PolicyValue -Key $Key -Name 'ShowHomeButton'
        if ($IncludeCloudApAuth) {
            Remove-PolicyValue -Key $Key -Name 'CloudAPAuthEnabled'
        }
        $urlsKey = Join-Path $Key $StartupUrlsSubkey
        if (Test-Path $urlsKey) {
            if ($PSCmdlet.ShouldProcess($urlsKey, 'Remove startup URL list')) {
                Remove-Item -Path $urlsKey -Recurse -Force
                Write-Host "  removed $StartupUrlsSubkey"
            }
        }
        return
    }

    # 4 = "open a list of URLs" in both browsers' policy schema.
    Set-PolicyValue -Key $Key -Name 'RestoreOnStartup' -Value 4 -Kind DWord
    Set-PolicyValue -Key $Key -Name 'HomepageLocation' -Value $Url -Kind String
    # The Home button must point at the URL, not the new-tab page.
    Set-PolicyValue -Key $Key -Name 'HomepageIsNewTabPage' -Value 0 -Kind DWord
    Set-PolicyValue -Key $Key -Name 'ShowHomeButton' -Value 1 -Kind DWord

    if ($IncludeCloudApAuth) {
        # Without this Chrome cannot use the device's Primary Refresh Token and
        # the portal's silent sign-in fails for every Chrome user.
        Set-PolicyValue -Key $Key -Name 'CloudAPAuthEnabled' -Value 1 -Kind DWord
    }

    # The startup URL list is its own subkey with numbered string values.
    $urlsKey = Join-Path $Key $StartupUrlsSubkey
    if (-not (Test-Path $urlsKey)) {
        if ($PSCmdlet.ShouldProcess($urlsKey, 'Create startup URL key')) {
            New-Item -Path $urlsKey -Force | Out-Null
        }
    }
    # Clear any stale entries first, or a previous URL stays in the list and the
    # browser opens two tabs.
    Get-Item -Path $urlsKey | Select-Object -ExpandProperty Property | ForEach-Object {
        Remove-PolicyValue -Key $urlsKey -Name $_
    }
    Set-PolicyValue -Key $urlsKey -Name '1' -Value $Url -Kind String
}

$action = if ($Remove) { 'Removing' } else { 'Applying' }
Write-Host "$action browser homepage policy" -ForegroundColor Green
if (-not $Remove) { Write-Host "URL: $Url" }

if ($Browsers -eq 'Edge' -or $Browsers -eq 'Both') {
    Set-BrowserPolicy -Name 'Microsoft Edge' -Key $EdgeKey `
        -StartupUrlsSubkey 'RestoreOnStartupURLs' -IncludeCloudApAuth $false
}

if ($Browsers -eq 'Chrome' -or $Browsers -eq 'Both') {
    Set-BrowserPolicy -Name 'Google Chrome' -Key $ChromeKey `
        -StartupUrlsSubkey 'RestoreOnStartupURLs' -IncludeCloudApAuth $true
}

Write-Host "`nDone." -ForegroundColor Green
Write-Host 'Restart the browser, then check edge://policy or chrome://policy to confirm.'
if (-not $Remove) {
    Write-Host 'If the portal shows a sign-in button, run: dsregcmd /status'
    Write-Host '  AzureAdJoined should be YES, and under SSO State, AzureAdPrt should be YES.'
}
