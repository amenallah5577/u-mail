$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$php = 'C:\Users\ammou\.config\herd\bin\php84\php.exe'
$cloudflaredCandidates = @(
    (Join-Path ${env:ProgramFiles(x86)} 'cloudflared\cloudflared.exe'),
    (Join-Path $env:ProgramFiles 'cloudflared\cloudflared.exe'),
    'cloudflared.exe'
)
$cloudflared = $cloudflaredCandidates | Where-Object { Get-Command $_ -ErrorAction SilentlyContinue } | Select-Object -First 1
if (-not $cloudflared) {
    throw 'cloudflared is not installed. Run: winget install --id Cloudflare.cloudflared --exact'
}

$cloudflared = (Get-Command $cloudflared).Source
$outLog = Join-Path $projectRoot 'storage\logs\u-mail-cloudflared.out.log'
$errLog = Join-Path $projectRoot 'storage\logs\u-mail-cloudflared.err.log'
$pidFile = Join-Path $projectRoot 'storage\app\u-mail-cloudflared.pid'
$packagePath = Join-Path $projectRoot 'storage\app\coworker-tunnel-link'
$zipPath = Join-Path $projectRoot 'storage\app\coworker-tunnel-link.zip'
$envPath = Join-Path $projectRoot '.env'

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

function Get-CloudflaredUrlFromLogs {
    $text = ''
    if (Test-Path -LiteralPath $outLog) {
        $text += Get-Content -LiteralPath $outLog -Raw -ErrorAction SilentlyContinue
    }
    if (Test-Path -LiteralPath $errLog) {
        $text += "`n" + (Get-Content -LiteralPath $errLog -Raw -ErrorAction SilentlyContinue)
    }

    $matches = [regex]::Matches($text, 'https://[a-zA-Z0-9-]+\.trycloudflare\.com')
    if ($matches.Count -gt 0) {
        return $matches[$matches.Count - 1].Value
    }

    return $null
}

function New-CoworkerTunnelPackage {
    param([Parameter(Mandatory)][string] $BaseUrl)

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
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-u-mail.ps1"
if not "%errorlevel%"=="0" pause
'@

    $checkCmd = @'
@echo off
setlocal EnableExtensions
title Check U-Mail Tunnel
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-u-mail.ps1" -CheckOnly
echo.
pause
'@

    $openPs1 = @'
param(
    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'
$loginUrl = '{{LOGIN_URL}}'
$healthUrl = '{{HEALTH_URL}}'

Write-Host 'U-Mail tunnel opener' -ForegroundColor Cyan
Write-Host "Page: $loginUrl"
Write-Host ''

try {
    Write-Host '[1/2] Checking the tunnel...'
    $health = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 20
    if ($health.StatusCode -ne 200) {
        throw "Tunnel health returned HTTP $($health.StatusCode)."
    }
    Write-Host 'PASS: tunnel reaches the host laptop.' -ForegroundColor Green

    Write-Host '[2/2] Checking U-Mail...'
    $response = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing -TimeoutSec 20
    if ($response.StatusCode -ne 200) {
        throw "U-Mail returned HTTP $($response.StatusCode)."
    }
    Write-Host 'PASS: U-Mail login page is reachable.' -ForegroundColor Green
} catch {
    Write-Host ''
    Write-Host 'U-Mail did not open through the tunnel.' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host ''
    Write-Host 'Ask the host laptop owner to run start-u-mail-tunnel.ps1 again and send the new ZIP/link.'
    exit 1
}

if ($CheckOnly) {
    Write-Host ''
    Write-Host 'Connection looks good.' -ForegroundColor Green
    exit 0
}

Write-Host ''
Write-Host 'Opening U-Mail...'
Start-Process $loginUrl
exit 0
'@

    $readme = @"
U-MAIL COWORKER TUNNEL LINK

Double-click "Open U-Mail.cmd".

URL:
$BaseUrl/login

This uses a temporary Cloudflare tunnel from the host laptop. Coworkers do not install U-Mail.
If it stops working, the host laptop owner must run start-u-mail-tunnel.ps1 again and send the new ZIP/link.
"@

    $shortcut = @"
[InternetShortcut]
URL=$BaseUrl/login
"@

    $openPs1 = $openPs1.Replace('{{LOGIN_URL}}', "$BaseUrl/login").Replace('{{HEALTH_URL}}', "$BaseUrl/u-mail-health")

    Set-Content -LiteralPath (Join-Path $packagePath 'Open U-Mail.cmd') -Value $openCmd -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $packagePath 'Check U-Mail Tunnel.cmd') -Value $checkCmd -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $packagePath 'open-u-mail.ps1') -Value $openPs1 -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $packagePath 'U-Mail.url') -Value $shortcut -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $packagePath 'README.txt') -Value $readme -Encoding ASCII

    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }
    Compress-Archive -Path (Join-Path $packagePath '*') -DestinationPath $zipPath -CompressionLevel Optimal
}

$baseUrl = $null
for ($tunnelAttempt = 1; $tunnelAttempt -le 4; $tunnelAttempt++) {
    Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
    Remove-Item $outLog, $errLog -Force -ErrorAction SilentlyContinue

    $process = Start-Process `
        -FilePath $cloudflared `
        -ArgumentList 'tunnel', '--url', 'http://localhost:80', '--no-autoupdate' `
        -WorkingDirectory $projectRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $outLog `
        -RedirectStandardError $errLog `
        -PassThru
    Set-Content -LiteralPath $pidFile -Value $process.Id

    for ($attempt = 0; $attempt -lt 45; $attempt++) {
        Start-Sleep -Seconds 1
        $baseUrl = Get-CloudflaredUrlFromLogs
        if ($baseUrl) {
            break
        }
        if ($process.HasExited) {
            break
        }
    }

    if ($baseUrl) {
        break
    }

    $logText = ''
    if (Test-Path -LiteralPath $errLog) {
        $logText = Get-Content -LiteralPath $errLog -Raw -ErrorAction SilentlyContinue
    }
    if ($tunnelAttempt -lt 4 -and ($process.HasExited -or $logText -match 'QuickTunnel|Internal Server Error|status_code="500')) {
        Write-Warning "Cloudflare quick tunnel was not ready on attempt $tunnelAttempt. Retrying..."
        Start-Sleep -Seconds 4
        continue
    }
}

if (-not $baseUrl) {
    throw "Cloudflare tunnel did not produce a URL. Check $errLog"
}

Set-UmailEnvValue -Path $envPath -Name 'APP_URL' -Value $baseUrl
Set-UmailEnvValue -Path $envPath -Name 'SESSION_SECURE_COOKIE' -Value 'false'
if (Test-Path -LiteralPath $php) {
    & $php artisan config:clear | Out-Null
}

New-CoworkerTunnelPackage -BaseUrl $baseUrl

Write-Host ''
Write-Host 'U-Mail tunnel is ready:' -ForegroundColor Green
Write-Host "Coworker login: $baseUrl/login"
Write-Host "Coworker ZIP:   $zipPath"
Write-Host 'Keep this host laptop on. If this tunnel stops, run this script again and share the new link.'
