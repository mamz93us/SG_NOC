<#
.SYNOPSIS
    Detection script for Intune Proactive Remediations.

.DESCRIPTION
    Exits 0 (compliant, no action) when the signature file exists AND matches the
    current server-rendered signature. Exits 1 (non-compliant) when the signature
    is missing or the template has changed on the server — which makes Intune run
    Deploy-Signature.ps1 as the remediation.

    Pair this as the DETECTION script and Deploy-Signature.ps1 as the REMEDIATION
    script in a Proactive Remediation, scheduled (e.g. daily) so signatures refresh
    automatically whenever an admin edits a template. Run in user context.
#>

param(
    [string] $BaseUrl       = 'https://noc.samirgroup.net',
    [string] $ApiKey        = 'REPLACE_WITH_SIGNATURE_SCOPED_API_KEY',
    [string] $SignatureName = 'SamirGroup'
)

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

try {
    $sigFile = Join-Path $env:APPDATA "Microsoft\Signatures\$SignatureName.htm"
    if (-not (Test-Path $sigFile)) { Write-Output 'Signature file missing'; exit 1 }

    # Resolve UPN (whoami is enough for the detection pass)
    $upn = (& whoami /upn) 2>$null
    if (-not ($upn -match '@')) { Write-Output 'No UPN'; exit 1 }

    $u = [uri]::EscapeDataString($upn.Trim()); $k = [uri]::EscapeDataString($ApiKey)
    $new   = (Invoke-RestMethod -Uri "$BaseUrl/api/signature?upn=$u&type=new_email&format=json&api_key=$k" -TimeoutSec 30).html
    try   { $reply = (Invoke-RestMethod -Uri "$BaseUrl/api/signature?upn=$u&type=reply&format=json&api_key=$k" -TimeoutSec 30).html }
    catch { $reply = $new }
    if (-not $new) { Write-Output 'API returned no HTML'; exit 1 }

    $serverHash = (Get-FileHash -Algorithm SHA256 -InputStream `
        ([IO.MemoryStream]::new([Text.Encoding]::UTF8.GetBytes([string]$new + [string]$reply)))).Hash

    $storedHash = Get-Content -Path (Join-Path $env:LOCALAPPDATA 'SamirGroup\SignatureDeploy\last.hash') -ErrorAction SilentlyContinue

    if ($storedHash -ne $serverHash) { Write-Output 'Signature changed on server'; exit 1 }

    Write-Output 'Signature up to date'
    exit 0
}
catch {
    # On any error, trigger remediation so the full script can log details
    Write-Output ("Detection error: " + $_.Exception.Message)
    exit 1
}
