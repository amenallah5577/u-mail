$ErrorActionPreference = 'Stop'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
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

function Write-NetworkDiagnostics {
    param(
        [Parameter(Mandatory)]$Config
    )

    Write-Host ''
    Write-Host '--- Network diagnostics ---' -ForegroundColor Yellow
    Write-Host "Expected host: $($Config.ipAddress)"
    Write-Host "Expected name: $($Config.hostname)"
    try {
        Write-Host 'IPv4 configuration:'
        Get-NetIPConfiguration |
            Select-Object InterfaceAlias, InterfaceDescription, IPv4Address, IPv4DefaultGateway, DNSServer |
            Format-List |
            Out-String |
            Write-Host
    } catch {
        Write-Host "Could not read network configuration: $($_.Exception.Message)"
    }
    try {
        Write-Host 'Ping check:'
        ping.exe -4 -n 1 -w 1200 $Config.ipAddress | Out-String | Write-Host
    } catch {
        Write-Host "Ping check failed: $($_.Exception.Message)"
    }
}

if (-not (Test-Administrator)) {
    Write-Host 'Requesting administrator permission...' -ForegroundColor Yellow
    $process = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`""
    )
    exit $process.ExitCode
}

$packageRoot = $PSScriptRoot
$logPath = Join-Path $env:TEMP 'u-mail-coworker-install.log'

try {
    Start-Transcript -LiteralPath $logPath -Force | Out-Null
    Write-Host 'Preparing U-Mail coworker access...' -ForegroundColor Cyan

    $configPath = Join-Path $packageRoot 'pilot-config.json'
    $rootCertificate = Join-Path $packageRoot 'u-mail-root.crt'
    foreach ($requiredFile in @($configPath, $rootCertificate)) {
        if (-not (Test-Path -LiteralPath $requiredFile)) {
            throw "Missing setup file: $requiredFile. Keep every file from the coworker-setup folder together."
        }
    }

    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    Write-Host "Package expects U-Mail host $($config.ipAddress) for $($config.hostname)."
    Write-Host ''
    Write-Host '[1/5] Checking the host laptop on Wi-Fi...'
    $hostReachable = $false
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        Write-Host "  Attempt $attempt of 3: testing $($config.ipAddress):443..."
        if (Test-TcpPort -Address $config.ipAddress -Port 443 -TimeoutMilliseconds 3000) {
            $hostReachable = $true
            break
        }
    }

    if (-not $hostReachable) {
        Write-NetworkDiagnostics -Config $config
        throw "This computer cannot reach the U-Mail host at $($config.ipAddress):443. On the host laptop, run C:\u-mail\Fix U-Mail Host Access.cmd as administrator, keep it awake, then rerun this installer. If it still fails, the Wi-Fi may have client/AP isolation enabled, which blocks computers on the same Wi-Fi from reaching each other."
    }

    Write-Host '[2/5] Connecting the U-Mail address...'
    $hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
    $startMarker = '# BEGIN U-MAIL PILOT'
    $endMarker = '# END U-MAIL PILOT'
    $lines = [Collections.Generic.List[string]]::new()
    $insideBlock = $false

    foreach ($line in [IO.File]::ReadAllLines($hostsPath)) {
        if ($line.Trim() -eq $startMarker) {
            $insideBlock = $true
            continue
        }
        if ($line.Trim() -eq $endMarker) {
            $insideBlock = $false
            continue
        }
        if (-not $insideBlock) {
            $lines.Add($line)
        }
    }

    $lines.Add($startMarker)
    $lines.Add("$($config.ipAddress)`t$($config.hostname)")
    $lines.Add($endMarker)
    [IO.File]::WriteAllLines($hostsPath, $lines, [Text.Encoding]::ASCII)
    Write-Host "Connected $($config.hostname) to $($config.ipAddress)."

    Write-Host '[3/5] Installing the U-Mail security certificate...'
    Import-Certificate -FilePath $rootCertificate -CertStoreLocation 'Cert:\LocalMachine\Root' | Out-Null
    $trusted = Get-ChildItem 'Cert:\LocalMachine\Root' |
        Where-Object { $_.Thumbprint -eq $config.certificateThumbprint } |
        Select-Object -First 1
    if (-not $trusted) {
        throw 'The U-Mail local security certificate could not be installed.'
    }
    Write-Host 'Installed the U-Mail local security certificate.'

    Write-Host '[4/5] Checking local name resolution...'
    Clear-DnsClientCache
    $resolvedAddress = [Net.Dns]::GetHostAddresses($config.hostname) |
        Where-Object { $_.AddressFamily -eq [Net.Sockets.AddressFamily]::InterNetwork } |
        Select-Object -First 1
    if (-not $resolvedAddress -or $resolvedAddress.IPAddressToString -ne $config.ipAddress) {
        throw "$($config.hostname) did not resolve to $($config.ipAddress)."
    }

    Write-Host '[5/5] Opening secure U-Mail...'
    $response = Invoke-WebRequest -Uri "https://$($config.hostname)/login" -UseBasicParsing -TimeoutSec 15
    if ($response.StatusCode -ne 200) {
        throw "U-Mail returned HTTP $($response.StatusCode)."
    }

    Write-Host ''
    Write-Host 'U-Mail coworker access is ready.' -ForegroundColor Green
    Write-Host "Open: https://$($config.hostname)/register"
    Start-Process "https://$($config.hostname)/register"
    exit 0
} catch {
    Write-Host ''
    Write-Host 'U-Mail setup could not finish.' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host ''
    Write-Host 'Most common causes: the host IP changed, the host laptop is asleep, Windows Firewall is blocking port 443, or the Wi-Fi blocks device-to-device traffic.'
    Write-Host 'On the host laptop, run C:\u-mail\Fix U-Mail Host Access.cmd as administrator, then try this installer again.'
    Write-Host "Diagnostic log: $logPath"
    exit 1
} finally {
    try {
        Stop-Transcript | Out-Null
    } catch {
        # Transcript was not started.
    }
}
