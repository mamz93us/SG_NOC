# NOC-DeviceInfo.ps1
# Deployed via Microsoft Intune as a device management script.
# Run context : SYSTEM account, 64-bit PowerShell
# Output      : A single compact JSON string written to stdout → becomes resultMessage in Graph API
#
# Fields returned:
#   computer_name  — hostname
#   model          — hardware model (e.g. "HP EliteBook 840 G8")
#   cpu            — processor name (e.g. "Intel Core i7-1185G7 @ 3.00GHz")
#   wifi_mac       — built-in wireless adapter MAC (AA-BB-CC-DD-EE-FF format)
#   ethernet_mac   — built-in wired Ethernet MAC
#   usb_eth        — array of {name, mac, desc} for USB/dock Ethernet adapters
#   teamviewer_id  — TeamViewer client ID (string) or $null
#   tv_version     — TeamViewer version string or $null
#
# The Laravel command intune:sync-net-data reads these via Graph beta and
# stores them in azure_devices + device_macs tables.

$ErrorActionPreference = 'SilentlyContinue'

# ── Basic system info ────────────────────────────────────────────────────────
$computerName = $env:COMPUTERNAME
$cpu          = (Get-CimInstance Win32_Processor | Select-Object -First 1).Name.Trim()
$model        = (Get-CimInstance Win32_ComputerSystem).Model.Trim()

# ── Network adapters ─────────────────────────────────────────────────────────
$wifiMac     = $null
$ethernetMac = $null
$usbEthList  = @()

# Exclude loopback, tunnel, virtual, and irrelevant adapter types
$adapters = Get-NetAdapter | Where-Object {
    $_.Status -in @('Up', 'Disconnected') -and
    $_.InterfaceType -ne 24 -and   # 24 = loopback
    $_.InterfaceDescription -notmatch 'Virtual|Hyper-V|VPN|TAP|Bluetooth|WAN Miniport|Teredo|6to4|ISATAP|Pseudo|Microsoft Wi-Fi Direct'
}

foreach ($a in $adapters) {
    $desc = $a.InterfaceDescription
    $mac  = $a.MacAddress    # Windows returns AA-BB-CC-DD-EE-FF

    # ── Wi-Fi / Wireless ────────────────────────────────────────────
    if ($a.InterfaceType -eq 71 -or $desc -match 'Wi-Fi|Wireless|802\.11|WLAN|WiFi') {
        if (-not $wifiMac) { $wifiMac = $mac }
        continue
    }

    # ── Detect USB / dock adapters via PnP device instance path ─────
    $isUsb = $false
    try {
        $pnp   = Get-PnpDevice -FriendlyName $a.InterfaceDescription -ErrorAction SilentlyContinue |
                 Select-Object -First 1
        $isUsb = $pnp.InstanceId -match '^USB\\'
    } catch {}

    # Fallback: match common USB NIC vendor names in description
    if (-not $isUsb -and $desc -match 'USB|Plugable|Belkin|Anker|j5create|Club3D|Realtek USB|AX88|ASIX|CDC|Thunderbolt') {
        $isUsb = $true
    }

    if ($isUsb) {
        $usbEthList += @{ name = $a.Name; mac = $mac; desc = $desc }
        continue
    }

    # ── Built-in wired Ethernet ──────────────────────────────────────
    if (-not $ethernetMac) { $ethernetMac = $mac }
}

# ── TeamViewer ───────────────────────────────────────────────────────────────
$tvId      = $null
$tvVersion = $null

foreach ($path in @('HKLM:\SOFTWARE\TeamViewer', 'HKLM:\SOFTWARE\WOW6432Node\TeamViewer')) {
    if (Test-Path $path) {
        $tv = Get-ItemProperty -Path $path -ErrorAction SilentlyContinue
        if ($tv.ClientID) {
            $tvId      = [string]$tv.ClientID
            $tvVersion = [string]$tv.Version
            break
        }
    }
}

# ── Output ───────────────────────────────────────────────────────────────────
Write-Output (ConvertTo-Json @{
    computer_name = $computerName
    model         = $model
    cpu           = $cpu
    wifi_mac      = $wifiMac
    ethernet_mac  = $ethernetMac
    usb_eth       = $usbEthList
    teamviewer_id = $tvId
    tv_version    = $tvVersion
} -Compress -Depth 3)
