$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
. (Join-Path $projectRoot 'lan\common.ps1')

if (-not (Test-UmailAdministrator)) {
    Write-Host 'Requesting administrator permission to restore LAN access...' -ForegroundColor Yellow
    $process = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`""
    )
    exit $process.ExitCode
}

$wifiAddress = Get-UmailWifiAddress

try {
    Set-NetConnectionProfile -InterfaceAlias 'Wi-Fi' -NetworkCategory Private
    Write-Host 'Wi-Fi network profile set to Private.' -ForegroundColor Green
} catch {
    Write-Warning "Could not set Wi-Fi profile to Private: $($_.Exception.Message)"
}

$rules = @(
    @{
        Name = 'U-Mail LAN HTTP'
        Description = 'Allow coworkers to open U-Mail over HTTP on the same network.'
        Protocol = 'TCP'
        LocalPort = 80
    },
    @{
        Name = 'U-Mail LAN HTTPS'
        Description = 'Allow coworkers to open U-Mail over HTTPS on the same network.'
        Protocol = 'TCP'
        LocalPort = 443
    },
    @{
        Name = 'U-Mail LAN Ping'
        Description = 'Allow ping tests from coworker laptops.'
        Protocol = 'ICMPv4'
        LocalPort = 'Any'
    }
)

foreach ($rule in $rules) {
    Get-NetFirewallRule -DisplayName $rule.Name -ErrorAction SilentlyContinue | Remove-NetFirewallRule

    $parameters = @{
        DisplayName = $rule.Name
        Description = $rule.Description
        Direction = 'Inbound'
        Action = 'Allow'
        Protocol = $rule.Protocol
        Profile = 'Any'
        InterfaceAlias = 'Wi-Fi'
        RemoteAddress = 'Any'
    }

    if ($rule.Protocol -eq 'TCP') {
        $parameters.LocalPort = $rule.LocalPort
    }

    New-NetFirewallRule @parameters | Out-Null
}

$listeners = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in 80, 443, 8090 } |
    Select-Object LocalAddress, LocalPort, OwningProcess

Write-Host ''
Write-Host 'U-Mail LAN access restored from the host side.' -ForegroundColor Green
Write-Host "Direct coworker URL: http://$wifiAddress/login"
Write-Host "Health check URL:    http://$wifiAddress/u-mail-health"
Write-Host ''
Write-Host 'Active listeners:'
$listeners | Format-Table -AutoSize
Write-Host ''
Write-Host 'Ask the coworker to run Check U-Mail Connection.cmd again.'
Write-Host 'If ping or port 80 still fails after this, the block is the Wi-Fi/router client isolation or the coworker laptop security/VPN.'
