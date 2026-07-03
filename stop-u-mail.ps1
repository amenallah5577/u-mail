$ErrorActionPreference = 'Stop'
if (Get-Variable PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}

$projectRoot = $PSScriptRoot
$pidFile = Join-Path $projectRoot 'storage\app\u-mail-server.pid'
$workerPidFile = Join-Path $projectRoot 'storage\app\u-mail-worker.pid'
$schedulerPidFile = Join-Path $projectRoot 'storage\app\u-mail-scheduler.pid'
$caddyPidFile = Join-Path $projectRoot 'storage\app\u-mail-caddy.pid'
$ollamaPidFile = Join-Path $projectRoot 'storage\app\u-mail-ollama.pid'
$cloudflaredPidFile = Join-Path $projectRoot 'storage\app\u-mail-cloudflared.pid'

function Test-DockerReady {
    try {
        docker info *> $null

        return $LASTEXITCODE -eq 0
    } catch {
        return $false
    }
}

Push-Location $projectRoot
try {
    if (Test-Path $pidFile) {
        $serverPid = [int](Get-Content -LiteralPath $pidFile)
        $server = Get-CimInstance Win32_Process -Filter "ProcessId = $serverPid" -ErrorAction SilentlyContinue

        if ($server -and $server.CommandLine -match '8090' -and $server.CommandLine -match 'artisan.*serve|resources[/\\]server\.php') {
            Stop-Process -Id $serverPid
        }

        Remove-Item -LiteralPath $pidFile -Force
    }

    $serverConnection = Get-NetTCPConnection -LocalPort 8090 -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalAddress -in @('127.0.0.1', '::1') } |
        Select-Object -First 1
    if ($serverConnection) {
        $serverChild = Get-CimInstance Win32_Process -Filter "ProcessId = $($serverConnection.OwningProcess)" -ErrorAction SilentlyContinue
        if ($serverChild -and $serverChild.Name -eq 'php.exe' -and $serverChild.CommandLine -match '127\.0\.0\.1:8090' -and $serverChild.CommandLine -match 'resources[/\\]server\.php') {
            Stop-Process -Id $serverConnection.OwningProcess
        }
    }

    foreach ($servicePidFile in @($workerPidFile, $schedulerPidFile)) {
        if (Test-Path $servicePidFile) {
            $servicePid = [int](Get-Content -LiteralPath $servicePidFile)
            $service = Get-CimInstance Win32_Process -Filter "ProcessId = $servicePid" -ErrorAction SilentlyContinue

            if ($service -and $service.CommandLine -match 'artisan.*(queue:work|schedule:work)') {
                Stop-Process -Id $servicePid
            }

            Remove-Item -LiteralPath $servicePidFile -Force
        }
    }

    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'artisan\s+(queue:work|schedule:work)' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -ErrorAction SilentlyContinue }

    if (Test-Path $caddyPidFile) {
        $caddyPid = [int](Get-Content -LiteralPath $caddyPidFile)
        $caddy = Get-Process -Id $caddyPid -ErrorAction SilentlyContinue

        if ($caddy -and $caddy.ProcessName -eq 'caddy') {
            Stop-Process -Id $caddyPid
        }

        Remove-Item -LiteralPath $caddyPidFile -Force
    }

    $caddyConnection = Get-NetTCPConnection -LocalPort 443 -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($caddyConnection) {
        $caddyProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $($caddyConnection.OwningProcess)" -ErrorAction SilentlyContinue
        if ($caddyProcess -and $caddyProcess.Name -eq 'caddy.exe' -and $caddyProcess.CommandLine -match 'Caddyfile') {
            Stop-Process -Id $caddyConnection.OwningProcess
        }
    }

    if (Test-Path $cloudflaredPidFile) {
        $cloudflaredPid = [int](Get-Content -LiteralPath $cloudflaredPidFile)
        $cloudflared = Get-CimInstance Win32_Process -Filter "ProcessId = $cloudflaredPid" -ErrorAction SilentlyContinue

        if ($cloudflared -and $cloudflared.Name -eq 'cloudflared.exe' -and $cloudflared.CommandLine -match 'tunnel') {
            Stop-Process -Id $cloudflaredPid -ErrorAction SilentlyContinue
        }

        Remove-Item -LiteralPath $cloudflaredPidFile -Force
    }

    if (Test-DockerReady) {
        docker compose -f compose.mailpit.yaml down
        if ($LASTEXITCODE -ne 0) {
            throw 'Mailpit could not be stopped.'
        }
    } else {
        Write-Warning 'Docker is not running, so private Mailpit was already unavailable.'
    }

    if (Test-Path $ollamaPidFile) {
        $ollamaPid = [int](Get-Content -LiteralPath $ollamaPidFile)
        $ollamaProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $ollamaPid" -ErrorAction SilentlyContinue

        if ($ollamaProcess -and $ollamaProcess.Name -eq 'ollama.exe' -and $ollamaProcess.CommandLine -match '\bserve\b') {
            Stop-Process -Id $ollamaPid -ErrorAction SilentlyContinue
        }

        Remove-Item -LiteralPath $ollamaPidFile -Force
    }

    Write-Host 'U-Mail, its HTTPS gateway, private Mailpit, and any U-Mail-started Ollama process are stopped.' -ForegroundColor Green
}
finally {
    Pop-Location
}
