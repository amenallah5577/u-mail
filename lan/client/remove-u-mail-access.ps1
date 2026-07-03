$ErrorActionPreference = 'Stop'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
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

$config = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'pilot-config.json') -Raw | ConvertFrom-Json
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

[IO.File]::WriteAllLines($hostsPath, $lines, [Text.Encoding]::ASCII)
Get-ChildItem 'Cert:\LocalMachine\Root' |
    Where-Object { $_.Thumbprint -eq $config.certificateThumbprint } |
    Remove-Item -Force
Clear-DnsClientCache

Write-Host 'U-Mail coworker access and its local certificate were removed.' -ForegroundColor Green
