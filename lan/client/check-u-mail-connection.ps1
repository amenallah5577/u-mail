$ErrorActionPreference = 'Stop'

function Write-Result {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][bool] $Passed,
        [string] $Details = ''
    )

    if ($Passed) {
        Write-Host "[PASS] $Name $Details" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Name $Details" -ForegroundColor Red
        $script:Failures++
    }
}

function Test-TcpPort {
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

function Get-HostsMatches {
    param(
        [Parameter(Mandatory)][string] $Hostname
    )

    $hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
    if (-not (Test-Path -LiteralPath $hostsPath)) {
        return @()
    }

    return @([IO.File]::ReadAllLines($hostsPath) |
        Where-Object { $_ -match "(?i)(^|\s)$([regex]::Escape($Hostname))(\s|$)" })
}

$Failures = 0
$packageRoot = $PSScriptRoot
$configPath = Join-Path $packageRoot 'pilot-config.json'
$logPath = Join-Path $env:TEMP 'u-mail-coworker-check.log'

try {
    Start-Transcript -LiteralPath $logPath -Force | Out-Null

    if (-not (Test-Path -LiteralPath $configPath)) {
        throw "Missing pilot-config.json. Extract the complete coworker-setup folder again."
    }

    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    $hostname = [string] $config.hostname
    $ipAddress = [string] $config.ipAddress
    $thumbprint = ([string] $config.certificateThumbprint).Replace(' ', '').ToUpperInvariant()

    Write-Host 'U-Mail coworker connection check' -ForegroundColor Cyan
    Write-Host "Expected name: $hostname"
    Write-Host "Expected host: $ipAddress"
    Write-Host ''

    Write-Host 'Local network:' -ForegroundColor Yellow
    Get-NetIPConfiguration |
        Where-Object { $_.IPv4Address -and $_.NetAdapter.Status -eq 'Up' } |
        Select-Object InterfaceAlias, InterfaceDescription, IPv4Address, IPv4DefaultGateway |
        Format-List |
        Out-String |
        Write-Host

    $tcpReady = Test-TcpPort -Address $ipAddress -Port 443 -TimeoutMilliseconds 3000
    Write-Result 'Can reach host laptop on HTTPS port 443' $tcpReady "($ipAddress`:443)"

    $healthOk = $false
    if ($tcpReady) {
        try {
            $health = Invoke-WebRequest -Uri "http://$ipAddress/u-mail-health" -UseBasicParsing -TimeoutSec 8
            $healthOk = ($health.StatusCode -eq 200 -and $health.Content -match 'U-Mail host reached')
        } catch {
            $healthOk = $false
        }
    }
    Write-Result 'Host gateway answers health check' $healthOk

    $hostsMatches = Get-HostsMatches -Hostname $hostname
    $hostsReady = @($hostsMatches | Where-Object { $_ -match "^\s*$([regex]::Escape($ipAddress))\s+$([regex]::Escape($hostname))(\s|$)" }).Count -gt 0
    Write-Result 'Windows hosts file maps u-mail.test correctly' $hostsReady "($ipAddress -> $hostname)"
    if ($hostsMatches.Count -gt 0) {
        Write-Host 'Hosts entries found:'
        $hostsMatches | ForEach-Object { Write-Host "  $_" }
    }

    Clear-DnsClientCache
    $resolved = @()
    try {
        $resolved = @([Net.Dns]::GetHostAddresses($hostname) |
            Where-Object { $_.AddressFamily -eq [Net.Sockets.AddressFamily]::InterNetwork } |
            ForEach-Object { $_.IPAddressToString })
    } catch {
        $resolved = @()
    }
    Write-Result 'u-mail.test resolves to the host laptop' ($resolved -contains $ipAddress) "resolved: $($resolved -join ', ')"

    $trustedCertificate = @(
        Get-ChildItem 'Cert:\LocalMachine\Root' -ErrorAction SilentlyContinue
        Get-ChildItem 'Cert:\CurrentUser\Root' -ErrorAction SilentlyContinue
    ) | Where-Object { $_.Thumbprint -eq $thumbprint } | Select-Object -First 1
    Write-Result 'U-Mail local certificate is trusted' ([bool] $trustedCertificate) $thumbprint

    $httpsOk = $false
    try {
        $response = Invoke-WebRequest -Uri "https://$hostname/login" -UseBasicParsing -TimeoutSec 15
        $httpsOk = $response.StatusCode -eq 200
    } catch {
        Write-Host "HTTPS error: $($_.Exception.Message)" -ForegroundColor Yellow
        $httpsOk = $false
    }
    Write-Result 'Secure login page opens' $httpsOk "https://$hostname/login"

    Write-Host ''
    if ($Failures -eq 0) {
        Write-Host 'This laptop can reach U-Mail correctly.' -ForegroundColor Green
        exit 0
    }

    Write-Host 'What to do next:' -ForegroundColor Yellow
    if (-not $tcpReady) {
        Write-Host '- This laptop cannot reach the host laptop at all on port 443.'
        Write-Host '- Confirm both laptops are on the same non-Guest Wi-Fi.'
        Write-Host '- Keep the host laptop awake and plugged in.'
        Write-Host '- On the host laptop, run C:\utica-mail-v2\Fix U-Mail Host Access.cmd as administrator.'
        Write-Host '- If it still fails after the host repair, the Wi-Fi/router likely has client isolation enabled.'
    } elseif (-not $hostsReady -or -not ($resolved -contains $ipAddress) -or -not $trustedCertificate) {
        Write-Host '- Run "Install U-Mail Access.cmd" as administrator from this same extracted folder.'
        Write-Host '- If the host IP changed, ask for the fresh coworker-setup.zip.'
    } else {
        Write-Host '- The network path works, but HTTPS failed. Ask the host to rebuild the coworker package and rerun setup.'
    }
    Write-Host "Diagnostic log: $logPath"
    exit 1
} catch {
    Write-Host ''
    Write-Host 'U-Mail connection check could not finish.' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Diagnostic log: $logPath"
    exit 1
} finally {
    try {
        Stop-Transcript | Out-Null
    } catch {
        # Transcript was not started.
    }
}
