$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
. (Join-Path $projectRoot 'lan\common.ps1')

$wifiAddress = Get-UmailWifiAddress
$baseUrl = "http://$wifiAddress"
$subnetPrefix = $wifiAddress -replace '\.\d+$', '.'
$packagePath = Join-Path $projectRoot 'storage\app\coworker-link'
$zipPath = Join-Path $projectRoot 'storage\app\coworker-link.zip'

if (Test-Path -LiteralPath $packagePath) {
    $resolvedPackage = [IO.Path]::GetFullPath($packagePath)
    $allowedRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot 'storage\app'))
    if (-not $resolvedPackage.StartsWith($allowedRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to replace unexpected package path: $resolvedPackage"
    }
    Remove-Item -LiteralPath $resolvedPackage -Recurse -Force
}

New-Item -ItemType Directory -Path $packagePath -Force | Out-Null

$openCmd = @'
@echo off
setlocal EnableExtensions
title Open U-Mail
cd /d "%~dp0"

if not exist "open-u-mail.ps1" (
    echo The U-Mail opener script is missing.
    echo Keep Open U-Mail.cmd and open-u-mail.ps1 in the same folder.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-u-mail.ps1"
set "RESULT=%errorlevel%"
if not "%RESULT%"=="0" (
    echo.
    pause
)
exit /b %RESULT%
'@

$checkCmd = @'
@echo off
setlocal EnableExtensions
title Check U-Mail Connection
cd /d "%~dp0"

if not exist "open-u-mail.ps1" (
    echo The U-Mail opener script is missing.
    echo Keep this folder complete.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-u-mail.ps1" -CheckOnly
echo.
pause
'@

$openPs1 = @'
param(
    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'

$uMailHostIp = '{{IP_ADDRESS}}'
$loginUrl = '{{LOGIN_URL}}'
$hostSubnetPrefix = '{{SUBNET_PREFIX}}'
$desktop = [Environment]::GetFolderPath('Desktop')
if ([string]::IsNullOrWhiteSpace($desktop) -or -not (Test-Path -LiteralPath $desktop)) {
    $desktop = $env:TEMP
}
$reportPath = Join-Path $desktop 'u-mail-connection-report.txt'

if (Test-Path -LiteralPath $reportPath) {
    Remove-Item -LiteralPath $reportPath -Force
}

function Write-Report {
    param(
        [Parameter(Mandatory)][AllowEmptyString()][string] $Message,
        [ConsoleColor] $Color = [ConsoleColor]::Gray
    )

    Write-Host $Message -ForegroundColor $Color
    Add-Content -LiteralPath $reportPath -Value $Message -Encoding ASCII
}

function Add-CommandReport {
    param(
        [Parameter(Mandatory)][string] $Title,
        [Parameter(Mandatory)][scriptblock] $Command
    )

    Add-Content -LiteralPath $reportPath -Value ''
    Add-Content -LiteralPath $reportPath -Value "## $Title" -Encoding ASCII
    try {
        $output = & $Command | Out-String
        Add-Content -LiteralPath $reportPath -Value $output -Encoding ASCII
    } catch {
        Add-Content -LiteralPath $reportPath -Value "ERROR: $($_.Exception.Message)" -Encoding ASCII
    }
}

function Test-UmailTcpPort {
    param(
        [Parameter(Mandatory)][string] $Address,
        [Parameter(Mandatory)][int] $Port,
        [int] $TimeoutMilliseconds = 3000
    )

    $client = [Net.Sockets.TcpClient]::new()
    try {
        $result = $client.BeginConnect($Address, $Port, $null, $null)
        if (-not $result.AsyncWaitHandle.WaitOne($TimeoutMilliseconds)) {
            return $false
        }
        $client.EndConnect($result)
        return $true
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

Write-Report 'U-Mail coworker diagnostic' Cyan
Write-Report "Created:     $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
Write-Report "Computer:    $env:COMPUTERNAME"
Write-Report "User:        $env:USERNAME"
Write-Report "Host laptop: $uMailHostIp"
Write-Report "Page:        $loginUrl"
Write-Report "Report:      $reportPath"
Write-Report ''

Add-CommandReport 'Network adapters' {
    Get-NetIPConfiguration |
        Where-Object { $_.NetAdapter.Status -eq 'Up' } |
        Select-Object InterfaceAlias, InterfaceDescription, IPv4Address, IPv4DefaultGateway, DNSServer |
        Format-List
}

Add-CommandReport 'IPv4 addresses' {
    Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        Select-Object InterfaceAlias, IPAddress, PrefixLength, AddressState |
        Format-Table -AutoSize
}

$localAddresses = @(Get-NetIPAddress -AddressFamily IPv4 -AddressState Preferred -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' })
$sameSubnet = @($localAddresses | Where-Object { $_.IPAddress -like "$hostSubnetPrefix*" }).Count -gt 0

if ($sameSubnet) {
    Write-Report "[PASS] Coworker laptop appears to be on the same $hostSubnetPrefix`x network." Green
} else {
    Write-Report "[FAIL] Coworker laptop is not on the same $hostSubnetPrefix`x network as the host." Red
    Write-Report 'This usually means it is on Guest Wi-Fi, another router/VLAN, mobile hotspot, VPN-only network, or a different office network.' Yellow
}

Add-CommandReport 'Ping host laptop' {
    ping.exe -4 -n 2 -w 1500 $uMailHostIp
}

Write-Report '[CHECK] Testing host port 80...'
$portReachable = Test-UmailTcpPort -Address $uMailHostIp -Port 80
if ($portReachable) {
    Write-Report '[PASS] Host port 80 is reachable.' Green
} else {
    Write-Report '[FAIL] Host port 80 is not reachable.' Red
    if ($sameSubnet) {
        Write-Report 'Most likely cause: router Wi-Fi client/AP isolation, or firewall/security software on the host laptop.' Yellow
    } else {
        Write-Report 'Most likely cause: coworker laptop is not on the same LAN as the host laptop.' Yellow
    }
}

Add-CommandReport 'Test-NetConnection port 80' {
    Test-NetConnection $uMailHostIp -Port 80
}

Write-Report '[CHECK] Testing U-Mail login page...'
$pageReachable = $false
try {
    $response = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing -TimeoutSec 10
    $pageReachable = $response.StatusCode -eq 200
    Write-Report "[PASS] U-Mail login page returned HTTP $($response.StatusCode)." Green
} catch {
    Write-Report '[FAIL] U-Mail login page did not load.' Red
    Write-Report $_.Exception.Message Red
}

Write-Report ''
Write-Report "Diagnostic report saved to: $reportPath" Cyan

if (-not $sameSubnet -or -not $portReachable -or -not $pageReachable) {
    Write-Report 'Send this report file to the host laptop owner.' Yellow
    exit 1
}

if ($CheckOnly) {
    Write-Report 'Connection looks good.' Green
    exit 0
}

Write-Report 'Opening U-Mail...' Green
Start-Process $loginUrl
exit 0
'@

$readme = @"
U-MAIL COWORKER LINK

1. Connect to the same Wi-Fi as the host laptop.
2. Double-click "Open U-Mail.cmd".
3. If it does not open, double-click "Check U-Mail Connection.cmd".

Coworker URL:
$baseUrl/login

Host laptop:
$wifiAddress

Coworkers do not install or run U-Mail. The host laptop must stay on and connected.
"@

$shortcut = @"
[InternetShortcut]
URL=$baseUrl/login
"@

$openPs1 = $openPs1.Replace('{{IP_ADDRESS}}', $wifiAddress).Replace('{{LOGIN_URL}}', "$baseUrl/login").Replace('{{SUBNET_PREFIX}}', $subnetPrefix)

Set-Content -LiteralPath (Join-Path $packagePath 'Open U-Mail.cmd') -Value $openCmd -Encoding ASCII
Set-Content -LiteralPath (Join-Path $packagePath 'Check U-Mail Connection.cmd') -Value $checkCmd -Encoding ASCII
Set-Content -LiteralPath (Join-Path $packagePath 'open-u-mail.ps1') -Value $openPs1 -Encoding ASCII
Set-Content -LiteralPath (Join-Path $packagePath 'U-Mail.url') -Value $shortcut -Encoding ASCII
Set-Content -LiteralPath (Join-Path $packagePath 'README.txt') -Value $readme -Encoding ASCII

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}
Compress-Archive -Path (Join-Path $packagePath '*') -DestinationPath $zipPath -CompressionLevel Optimal

Write-Host ''
Write-Host 'Coworker opener is ready.' -ForegroundColor Green
Write-Host "Folder: $packagePath"
Write-Host "ZIP:    $zipPath"
Write-Host "Link:   $baseUrl/login"
