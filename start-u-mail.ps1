param(
    [Alias('SkipAi')]
    [switch]$SkipAgent,
    [Alias('EnableAiForAllUsers')]
    [switch]$EnableAgentForAllUsers
)

$ErrorActionPreference = 'Stop'
if (Get-Variable PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}

$projectRoot = $PSScriptRoot
$php = 'C:\Users\ammou\.config\herd\bin\php84\php.exe'
$dockerDesktop = 'C:\Program Files\Docker\Docker\Docker Desktop.exe'
$pidFile = Join-Path $projectRoot 'storage\app\u-mail-server.pid'
$workerPidFile = Join-Path $projectRoot 'storage\app\u-mail-worker.pid'
$schedulerPidFile = Join-Path $projectRoot 'storage\app\u-mail-scheduler.pid'
$caddyPidFile = Join-Path $projectRoot 'storage\app\u-mail-caddy.pid'
$ollamaPidFile = Join-Path $projectRoot 'storage\app\u-mail-ollama.pid'
$caddyFile = Join-Path $projectRoot 'Caddyfile'
$stdoutLog = Join-Path $projectRoot 'storage\logs\u-mail-server.out.log'
$stderrLog = Join-Path $projectRoot 'storage\logs\u-mail-server.err.log'
$workerOutLog = Join-Path $projectRoot 'storage\logs\u-mail-worker.out.log'
$workerErrLog = Join-Path $projectRoot 'storage\logs\u-mail-worker.err.log'
$schedulerOutLog = Join-Path $projectRoot 'storage\logs\u-mail-scheduler.out.log'
$schedulerErrLog = Join-Path $projectRoot 'storage\logs\u-mail-scheduler.err.log'
$caddyOutLog = Join-Path $projectRoot 'storage\logs\u-mail-caddy.out.log'
$caddyErrLog = Join-Path $projectRoot 'storage\logs\u-mail-caddy.err.log'
$ollamaOutLog = Join-Path $projectRoot 'storage\logs\u-mail-ollama.out.log'
$ollamaErrLog = Join-Path $projectRoot 'storage\logs\u-mail-ollama.err.log'
$aiEndpoint = 'http://127.0.0.1:11434'
$aiModel = 'llama3.2:latest'
$adminLoginPath = 'utica-admin-entry'

. (Join-Path $projectRoot 'lan\common.ps1')

$envPath = Join-Path $projectRoot '.env'
if (Test-Path -LiteralPath $envPath) {
    $adminLoginLine = Get-Content -LiteralPath $envPath | Where-Object { $_ -match '^\s*ADMIN_LOGIN_PATH=' } | Select-Object -First 1
    if ($adminLoginLine) {
        $adminLoginPath = ($adminLoginLine -replace '^\s*ADMIN_LOGIN_PATH=', '').Trim().Trim('"').Trim("'").Trim('/')
    }
}
if ($adminLoginPath -eq '' -or $adminLoginPath -in @('login', 'admin/login')) {
    $adminLoginPath = 'utica-admin-entry'
}

$lanAddress = Get-UmailWifiAddress
$coworkerBaseUrl = 'https://u-mail.test'
$coworkerNamedUrl = "http://u-mail.$($lanAddress -replace '\.', '-').sslip.io"

function Set-UmailEnvValue {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $Value
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $updated = $false
    $lines = [Collections.Generic.List[string]]::new()
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match "^\s*$([regex]::Escape($Name))=") {
            $lines.Add("$Name=$Value")
            $updated = $true
        } else {
            $lines.Add($line)
        }
    }

    if (-not $updated) {
        $lines.Add("$Name=$Value")
    }

    [IO.File]::WriteAllLines($Path, $lines, [Text.Encoding]::ASCII)
}

if (-not (Test-Path $php)) {
    throw "PHP 8.4 was not found at $php"
}

function Get-PidFileProcess {
    param([Parameter(Mandatory)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $processIdText = (Get-Content -LiteralPath $Path -Raw).Trim()
    if ($processIdText -notmatch '^\d+$') {
        Remove-Item -LiteralPath $Path -Force
        return $null
    }

    return Get-CimInstance Win32_Process -Filter "ProcessId = $processIdText" -ErrorAction SilentlyContinue
}

function Remove-StalePidFile {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $ProcessName,
        [Parameter(Mandatory)][string] $CommandPattern
    )

    $process = Get-PidFileProcess -Path $Path
    if (-not $process -or $process.Name -ne $ProcessName -or $process.CommandLine -notmatch $CommandPattern) {
        if (Test-Path -LiteralPath $Path) {
            Remove-Item -LiteralPath $Path -Force
        }
    }
}

function Test-DockerReady {
    try {
        docker info *> $null

        return $LASTEXITCODE -eq 0
    } catch {
        return $false
    }
}

function Get-OllamaModelNames {
    param([Parameter(Mandatory)][string] $Endpoint)

    try {
        $response = Invoke-RestMethod -Uri "$Endpoint/api/tags" -Method Get -TimeoutSec 5

        return @($response.models | ForEach-Object { $_.name })
    } catch {
        return @()
    }
}

function Test-OllamaModelAvailable {
    param(
        [Parameter(Mandatory)][string] $Endpoint,
        [Parameter(Mandatory)][string] $Model
    )

    $models = Get-OllamaModelNames -Endpoint $Endpoint

    return @($models | Where-Object { $_ -eq $Model -or $_ -eq ($Model -replace ':latest$', '') }).Count -gt 0
}

function Start-UmailLocalAgent {
    if ($SkipAgent) {
        Write-Warning 'Skipping local agent-engine startup because -SkipAgent was provided.'

        return
    }

    $ollama = Get-UmailOllamaPath

    if (-not (Test-UmailTcpPort -Address '127.0.0.1' -Port 11434 -TimeoutMilliseconds 1000)) {
        Write-Host 'Starting local Ollama assistant on the host machine...'
        $ollamaProcess = Start-Process `
            -FilePath $ollama `
            -ArgumentList 'serve' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $ollamaOutLog `
            -RedirectStandardError $ollamaErrLog `
            -PassThru
        Set-Content -LiteralPath $ollamaPidFile -Value $ollamaProcess.Id

        $ollamaReady = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Seconds 1
            if (Test-UmailTcpPort -Address '127.0.0.1' -Port 11434 -TimeoutMilliseconds 1000) {
                $ollamaReady = $true
                break
            }
        }

        if (-not $ollamaReady) {
            throw "Ollama did not start. Check $ollamaErrLog"
        }
    }

    if (-not (Test-OllamaModelAvailable -Endpoint $aiEndpoint -Model $aiModel)) {
        Write-Host "Pulling local agent model $aiModel. This can take a while the first time..."
        & $ollama pull $aiModel
        if ($LASTEXITCODE -ne 0) {
            throw "Ollama could not pull $aiModel."
        }
    }

    Write-Host 'Warming local agent model for coworker requests...'
    $warmupBody = @{
        model = $aiModel
        prompt = 'Return JSON only: {"ok":true}'
        stream = $false
        format = 'json'
        keep_alive = '30m'
        options = @{
            temperature = 0.1
            num_predict = 16
        }
    } | ConvertTo-Json -Depth 5

    try {
        Invoke-RestMethod -Uri "$aiEndpoint/api/generate" -Method Post -ContentType 'application/json' -Body $warmupBody -TimeoutSec 30 | Out-Null
    } catch {
        Write-Warning 'Ollama is running, but the warmup request did not finish. U-Mail will retry when users open U-Assist.'
    }

    $aiConfigureArgs = @('artisan', 'ai:configure-local', "--endpoint=$aiEndpoint", "--model=$aiModel")
    if ($EnableAgentForAllUsers) {
        $aiConfigureArgs += '--enable-users'
    }
    & $php @aiConfigureArgs
    if ($LASTEXITCODE -ne 0) {
            throw 'U-Mail could not save the local agent-engine settings.'
    }
}

Push-Location $projectRoot
try {
    if (-not (Test-DockerReady)) {
        if (-not (Test-Path $dockerDesktop)) {
            throw 'Docker Desktop is not installed.'
        }

        Write-Host 'Starting Docker Desktop...'
        Start-Process -FilePath $dockerDesktop -WindowStyle Hidden

        $dockerReady = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Seconds 2
            if (Test-DockerReady) {
                $dockerReady = $true
                break
            }
        }

        if (-not $dockerReady) {
            throw 'Docker Desktop did not become ready within 60 seconds.'
        }
    }

    Write-Host 'Starting the local Mailpit inbox...'
    docker compose -f compose.mailpit.yaml up -d
    if ($LASTEXITCODE -ne 0) {
        throw 'Mailpit could not be started.'
    }

    Set-UmailEnvValue -Path $envPath -Name 'APP_URL' -Value $coworkerBaseUrl
    Set-UmailEnvValue -Path $envPath -Name 'SESSION_SECURE_COOKIE' -Value 'true'
    & $php artisan config:clear | Out-Null
    Start-UmailLocalAgent

    Remove-StalePidFile -Path $pidFile -ProcessName 'php.exe' -CommandPattern 'artisan\s+serve'
    Remove-StalePidFile -Path $workerPidFile -ProcessName 'php.exe' -CommandPattern 'artisan\s+queue:work'
    Remove-StalePidFile -Path $schedulerPidFile -ProcessName 'php.exe' -CommandPattern 'artisan\s+schedule:work'

    $appConnection = Get-NetTCPConnection -LocalPort 8090 -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalAddress -in @('127.0.0.1', '::1') } |
        Select-Object -First 1
    if (-not $appConnection) {
        Write-Host 'Starting U-Mail...'
        $server = Start-Process `
            -FilePath $php `
            -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', '--port=8090' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $stdoutLog `
            -RedirectStandardError $stderrLog `
            -PassThru
        Set-Content -LiteralPath $pidFile -Value $server.Id

        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            Start-Sleep -Milliseconds 500
            if (Get-NetTCPConnection -LocalPort 8090 -State Listen -ErrorAction SilentlyContinue) {
                $appConnection = Get-NetTCPConnection -LocalPort 8090 -State Listen -ErrorAction SilentlyContinue |
                    Select-Object -First 1
                break
            }
        }

        if (-not $appConnection) {
            throw "U-Mail did not start. Check $stderrLog"
        }
    }
    else {
        $existingServer = Get-CimInstance Win32_Process -Filter "ProcessId = $($appConnection.OwningProcess)" -ErrorAction SilentlyContinue
        if ($existingServer -and $existingServer.Name -eq 'php.exe' -and $existingServer.CommandLine -match '127\.0\.0\.1:8090' -and $existingServer.CommandLine -match 'resources[/\\]server\.php') {
            Set-Content -LiteralPath $pidFile -Value $appConnection.OwningProcess
        } else {
            throw 'Port 8090 is already used by a process that was not started by U-Mail.'
        }
    }

    $workerProcess = Get-PidFileProcess -Path $workerPidFile
    if (-not $workerProcess) {
        Write-Host 'Starting background delivery...'
        $worker = Start-Process `
            -FilePath $php `
            -ArgumentList 'artisan', 'queue:work', '--queue=critical-mail,external-mail,default', '--tries=5', '--sleep=2' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $workerOutLog `
            -RedirectStandardError $workerErrLog `
            -PassThru
        Set-Content -LiteralPath $workerPidFile -Value $worker.Id
    }

    $schedulerProcess = Get-PidFileProcess -Path $schedulerPidFile
    if (-not $schedulerProcess) {
        Write-Host 'Starting scheduled maintenance...'
        $scheduler = Start-Process `
            -FilePath $php `
            -ArgumentList 'artisan', 'schedule:work' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $schedulerOutLog `
            -RedirectStandardError $schedulerErrLog `
            -PassThru
        Set-Content -LiteralPath $schedulerPidFile -Value $scheduler.Id
    }

    $caddy = Get-UmailCaddyPath
    Remove-StalePidFile -Path $caddyPidFile -ProcessName 'caddy.exe' -CommandPattern ([regex]::Escape($caddyFile))
    & $caddy validate --config $caddyFile --adapter caddyfile | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'The U-Mail Caddy configuration is invalid.'
    }

    $caddyConnection = Get-NetTCPConnection -LocalPort 443 -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if (-not $caddyConnection) {
        Write-Host 'Starting same-Wi-Fi HTTPS gateway...'
        $caddyProcess = Start-Process `
            -FilePath $caddy `
            -ArgumentList 'run', '--config', $caddyFile, '--adapter', 'caddyfile' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $caddyOutLog `
            -RedirectStandardError $caddyErrLog `
            -PassThru
        Set-Content -LiteralPath $caddyPidFile -Value $caddyProcess.Id

        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            Start-Sleep -Milliseconds 500
            if (Get-NetTCPConnection -LocalPort 443 -State Listen -ErrorAction SilentlyContinue) {
                $caddyConnection = Get-NetTCPConnection -LocalPort 443 -State Listen -ErrorAction SilentlyContinue |
                    Select-Object -First 1
                break
            }
        }

        if (-not $caddyConnection) {
            throw "The U-Mail HTTPS gateway did not start. Check $caddyErrLog"
        }
    }
    else {
        $existingCaddy = Get-CimInstance Win32_Process -Filter "ProcessId = $($caddyConnection.OwningProcess)" -ErrorAction SilentlyContinue
        if ($existingCaddy -and $existingCaddy.Name -eq 'caddy.exe' -and $existingCaddy.CommandLine -match [regex]::Escape($caddyFile)) {
            Set-Content -LiteralPath $caddyPidFile -Value $caddyConnection.OwningProcess
        } else {
            throw 'Port 443 is already used by a process that was not started by U-Mail.'
        }
    }

    Write-Host ''
    Write-Host 'U-Mail is ready:' -ForegroundColor Green
    Write-Host "Coworker login: $coworkerBaseUrl/login"
    Write-Host "Admin login:    $coworkerBaseUrl/$adminLoginPath"
    Write-Host "Host Wi-Fi IP:  $lanAddress"
    Write-Host "Named link:     $coworkerNamedUrl/login"
    Write-Host 'Local emails:   http://127.0.0.1:8025 (host only)'
    Write-Host "Local agent:    $aiEndpoint using $aiModel (host only)"
    Write-Host 'Registration:   enabled'
    Write-Host 'Note: until real SMTP is configured, confirmation emails are captured in private Mailpit.'
}
finally {
    Pop-Location
}
