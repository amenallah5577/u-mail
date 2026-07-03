$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $projectRoot 'lan\common.ps1')

if (-not (Test-UmailAdministrator)) {
    $process = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`""
    )
    exit $process.ExitCode
}

$wifiAddress = Get-UmailWifiAddress
Set-UmailHostsEntry -Address '127.0.0.1'

try {
    Set-NetConnectionProfile -InterfaceAlias 'Wi-Fi' -NetworkCategory Private
} catch {
    Write-Warning "Windows did not allow changing the Wi-Fi profile to Private. Continuing with firewall rules for all profiles. Details: $($_.Exception.Message)"
}

$caddyPath = Get-UmailCaddyPath
$ruleNames = @(
    'U-Mail LAN HTTPS',
    'U-Mail LAN HTTP',
    'U-Mail LAN Ping',
    'U-Mail Caddy Gateway'
)
foreach ($ruleName in $ruleNames) {
    Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
}

New-NetFirewallRule `
    -DisplayName 'U-Mail LAN HTTPS' `
    -Description 'Allow the U-Mail HTTPS pilot only from devices on the local Wi-Fi subnet.' `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 443 `
    -RemoteAddress LocalSubnet `
    -InterfaceAlias 'Wi-Fi' `
    -Profile Any | Out-Null

New-NetFirewallRule `
    -DisplayName 'U-Mail LAN HTTP' `
    -Description 'Allow the U-Mail local health check only from devices on the local Wi-Fi subnet.' `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 80 `
    -RemoteAddress LocalSubnet `
    -InterfaceAlias 'Wi-Fi' `
    -Profile Any | Out-Null

New-NetFirewallRule `
    -DisplayName 'U-Mail LAN Ping' `
    -Description 'Allow coworker setup diagnostics to ping the U-Mail host on the local Wi-Fi subnet.' `
    -Direction Inbound `
    -Action Allow `
    -Protocol ICMPv4 `
    -IcmpType 8 `
    -RemoteAddress LocalSubnet `
    -InterfaceAlias 'Wi-Fi' `
    -Profile Any | Out-Null

New-NetFirewallRule `
    -DisplayName 'U-Mail Caddy Gateway' `
    -Description 'Allow the Caddy HTTPS gateway used by the U-Mail local pilot.' `
    -Direction Inbound `
    -Action Allow `
    -Program $caddyPath `
    -RemoteAddress LocalSubnet `
    -InterfaceAlias 'Wi-Fi' `
    -Profile Any | Out-Null

Write-Host "U-Mail host access configured for Wi-Fi address $wifiAddress." -ForegroundColor Green
Write-Host 'If a coworker still cannot ping this address, the Wi-Fi/router is blocking device-to-device traffic.' -ForegroundColor Yellow
