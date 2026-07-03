param(
    [Alias('SkipAi')]
    [switch]$SkipAgent
)

$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$php = 'C:\Users\ammou\.config\herd\bin\php84\php.exe'
$aiEndpoint = 'http://127.0.0.1:11434'
$aiModel = 'llama3.2:latest'
. (Join-Path $projectRoot 'lan\common.ps1')

$wifiAddress = Get-UmailWifiAddress
$failures = 0

function Write-Check {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][bool] $Passed,
        [string] $Details = ''
    )

    if ($Passed) {
        Write-Host "[PASS] $Name $Details" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Name $Details" -ForegroundColor Red
        $script:failures++
    }
}

function Test-TcpPort {
    param(
        [Parameter(Mandatory)][string] $Address,
        [Parameter(Mandatory)][int] $Port,
        [int] $TimeoutMilliseconds = 1000
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

function Test-PidFileCommand {
    param(
        [Parameter(Mandatory)][string] $FileName,
        [Parameter(Mandatory)][string] $ProcessName,
        [Parameter(Mandatory)][string] $CommandPattern
    )

    $pidPath = Join-Path $projectRoot "storage\app\$FileName"
    if (-not (Test-Path -LiteralPath $pidPath)) {
        return $false
    }

    $processIdText = (Get-Content -LiteralPath $pidPath -Raw).Trim()
    if ($processIdText -notmatch '^\d+$') {
        return $false
    }

    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processIdText" -ErrorAction SilentlyContinue

    return [bool] ($process -and $process.Name -eq $ProcessName -and $process.CommandLine -match $CommandPattern)
}

function Get-OllamaModelNames {
    try {
        $response = Invoke-RestMethod -Uri "$aiEndpoint/api/tags" -Method Get -TimeoutSec 5

        return @($response.models | ForEach-Object { $_.name })
    } catch {
        return @()
    }
}

$savedAddressPath = Join-Path $projectRoot 'storage\app\lan-ip.txt'
$savedAddress = if (Test-Path -LiteralPath $savedAddressPath) { (Get-Content -LiteralPath $savedAddressPath -Raw).Trim() } else { '' }
Write-Check 'Wi-Fi address matches prepared package' ($savedAddress -eq $wifiAddress) "($wifiAddress)"

$httpsReady = $false
try {
    $response = Invoke-WebRequest -Uri 'https://u-mail.test/login' -UseBasicParsing -TimeoutSec 10
    $httpsReady = $response.StatusCode -eq 200
} catch {
    $httpsReady = $false
}
Write-Check 'Trusted HTTPS login' $httpsReady
Write-Check 'Wi-Fi HTTPS listener' (Test-TcpPort -Address $wifiAddress -Port 443) "($wifiAddress`:443)"
Write-Check 'No UDP HTTPS listener' (@(Get-NetUDPEndpoint -LocalPort 443 -ErrorAction SilentlyContinue).Count -eq 0)

foreach ($port in @(8090, 8025, 1025)) {
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
    $loopbackOnly = $listeners.Count -gt 0 -and @($listeners | Where-Object { $_.LocalAddress -notin @('127.0.0.1', '::1') }).Count -eq 0
    Write-Check "Port $port listens only on loopback" $loopbackOnly
    Write-Check "Port $port is unavailable through Wi-Fi" (-not (Test-TcpPort -Address $wifiAddress -Port $port))
}

$serverListener = Get-NetTCPConnection -State Listen -LocalPort 8090 -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalAddress -in @('127.0.0.1', '::1') } |
    Select-Object -First 1
$serverProcess = if ($serverListener) { Get-CimInstance Win32_Process -Filter "ProcessId = $($serverListener.OwningProcess)" -ErrorAction SilentlyContinue } else { $null }
$serverReady = [bool] ($serverProcess -and $serverProcess.Name -eq 'php.exe' -and $serverProcess.CommandLine -match '127\.0\.0\.1:8090' -and $serverProcess.CommandLine -match 'resources[/\\]server\.php')
Write-Check 'U-Mail loopback server process' $serverReady

$firewallRule = Get-NetFirewallRule -DisplayName 'U-Mail LAN HTTPS' -ErrorAction SilentlyContinue
$firewallAddress = if ($firewallRule) { $firewallRule | Get-NetFirewallAddressFilter } else { $null }
$firewallReady = $firewallRule -and $firewallRule.Enabled -eq 'True' -and $firewallRule.Action -eq 'Allow' -and $firewallAddress.RemoteAddress -contains 'LocalSubnet'
Write-Check 'Local-subnet firewall rule' ([bool] $firewallReady)

Write-Check 'background delivery process' (Test-PidFileCommand -FileName 'u-mail-worker.pid' -ProcessName 'php.exe' -CommandPattern 'artisan\s+queue:work')
Write-Check 'scheduled maintenance process' (Test-PidFileCommand -FileName 'u-mail-scheduler.pid' -ProcessName 'php.exe' -CommandPattern 'artisan\s+schedule:work')
Write-Check 'HTTPS gateway process' (Test-PidFileCommand -FileName 'u-mail-caddy.pid' -ProcessName 'caddy.exe' -CommandPattern ([regex]::Escape((Join-Path $projectRoot 'Caddyfile'))))

if ($SkipAgent.IsPresent) {
    Write-Host '[SKIP] Local agent checks were skipped.' -ForegroundColor Yellow
} else {
    $ollamaReady = Test-TcpPort -Address '127.0.0.1' -Port 11434
    $ollamaModels = if ($ollamaReady) { Get-OllamaModelNames } else { @() }
    $ollamaModelReady = @($ollamaModels | Where-Object { $_ -eq $aiModel -or $_ -eq ($aiModel -replace ':latest$', '') }).Count -gt 0
    $aiSettingsReady = $false
    if (Test-Path -LiteralPath $php) {
        & $php artisan ai:configure-local "--endpoint=$aiEndpoint" "--model=$aiModel" --check *> $null
        $aiSettingsReady = $LASTEXITCODE -eq 0
    }
    Write-Check 'host local Ollama endpoint' $ollamaReady 'host-only 127.0.0.1:11434'
    Write-Check "host local agent model $aiModel" $ollamaModelReady
    Write-Check 'U-Mail local agent-engine settings' $aiSettingsReady
    Write-Check 'Ollama is not exposed directly through Wi-Fi' (-not (Test-TcpPort -Address $wifiAddress -Port 11434)) "($wifiAddress`:11434)"
}

if ($failures -gt 0) {
    throw "$failures LAN pilot check(s) failed."
}

Write-Host ''
Write-Host 'U-Mail LAN pilot checks passed.' -ForegroundColor Green
