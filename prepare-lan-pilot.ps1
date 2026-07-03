param(
    [Alias('SkipAi')]
    [switch]$SkipAgent
)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
. (Join-Path $projectRoot 'lan\common.ps1')

$wifiAddress = Get-UmailWifiAddress
$savedAddressPath = Join-Path $projectRoot 'storage\app\lan-ip.txt'
$previousAddress = if (Test-Path -LiteralPath $savedAddressPath) { (Get-Content -LiteralPath $savedAddressPath -Raw).Trim() } else { '' }
if ($previousAddress -and $previousAddress -ne $wifiAddress) {
    Write-Warning "The Wi-Fi address changed from $previousAddress to $wifiAddress. Regenerate and rerun the coworker package."
}
Set-Content -LiteralPath $savedAddressPath -Value $wifiAddress

$hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$hostsReady = [bool] (Select-String -Path $hostsPath -Pattern '^\s*127\.0\.0\.1\s+u-mail\.test\s*$' -Quiet)
$firewallRule = Get-NetFirewallRule -DisplayName 'U-Mail LAN HTTPS' -ErrorAction SilentlyContinue
$firewallAddress = if ($firewallRule) { $firewallRule | Get-NetFirewallAddressFilter } else { $null }
$firewallReady = $firewallRule -and $firewallRule.Enabled -eq 'True' -and $firewallRule.Action -eq 'Allow' -and $firewallAddress.RemoteAddress -contains 'LocalSubnet'

if (-not ($hostsReady -and $firewallReady)) {
    & (Join-Path $projectRoot 'lan\configure-host.ps1')
    if ($LASTEXITCODE -ne 0) {
        throw 'The administrator-only host configuration did not complete.'
    }
}

& (Join-Path $projectRoot 'backup-u-mail.ps1') -Label 'before-lan-pilot'
$startArgs = @()
if ($SkipAgent.IsPresent) {
    $startArgs += '-SkipAgent'
} else {
    $startArgs += '-EnableAgentForAllUsers'
}
& (Join-Path $projectRoot 'start-u-mail.ps1') @startArgs

$caddy = Get-UmailCaddyPath
$rootCertificate = $null
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    try {
        $rootCertificate = Get-UmailCaddyRootCertificate -CaddyPath $caddy
        break
    } catch {
        Start-Sleep -Milliseconds 500
    }
}
if (-not $rootCertificate) {
    throw 'Caddy did not generate its local root certificate.'
}

$packagePath = Join-Path $projectRoot 'storage\app\coworker-setup'
if (Test-Path -LiteralPath $packagePath) {
    $resolvedPackage = [IO.Path]::GetFullPath($packagePath)
    $allowedRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot 'storage\app'))
    if (-not $resolvedPackage.StartsWith($allowedRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to replace unexpected package path: $resolvedPackage"
    }
    Remove-Item -LiteralPath $resolvedPackage -Recurse -Force
}
New-Item -ItemType Directory -Path $packagePath -Force | Out-Null

$certificate = [Security.Cryptography.X509Certificates.X509Certificate2]::new($rootCertificate)
$pilotConfig = @{
    hostname = 'u-mail.test'
    ipAddress = $wifiAddress
    certificateThumbprint = $certificate.Thumbprint
    generatedAt = (Get-Date).ToString('o')
}
$pilotConfig | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $packagePath 'pilot-config.json')
Copy-Item -LiteralPath $rootCertificate -Destination (Join-Path $packagePath 'u-mail-root.crt')
$clientTemplates = @(
    'Install U-Mail Access.cmd',
    'Remove U-Mail Access.cmd',
    'Check U-Mail Connection.cmd',
    'install-u-mail-access.ps1',
    'check-u-mail-connection.ps1',
    'remove-u-mail-access.ps1'
)
foreach ($templateName in $clientTemplates) {
    $content = Get-Content -LiteralPath (Join-Path $projectRoot "lan\client\$templateName") -Raw
    $content = $content.Replace('{{HOSTNAME}}', $pilotConfig.hostname)
    $content = $content.Replace('{{IP_ADDRESS}}', $pilotConfig.ipAddress)
    $content = $content.Replace('{{CERTIFICATE_FILE}}', 'u-mail-root.crt')
    $content = $content.Replace('{{CERTIFICATE_THUMBPRINT}}', $pilotConfig.certificateThumbprint)
    Set-Content -LiteralPath (Join-Path $packagePath $templateName) -Value $content -Encoding ASCII
}

@"
U-MAIL COWORKER PILOT

1. Connect to Wi-Fi TT_1B88.
2. Extract this complete folder before running setup.
3. Double-click "Install U-Mail Access.cmd".
4. Accept the administrator prompt.
5. The setup opens https://u-mail.test/register automatically.

This package points u-mail.test to $wifiAddress.
U-Assist runs on the host laptop through local Ollama; coworkers do not install Ollama.
If setup fails, double-click "Check U-Mail Connection.cmd".
Double-click "Remove U-Mail Access.cmd" after the pilot.
Do not share this package outside the pilot group.
"@ | Set-Content -LiteralPath (Join-Path $packagePath 'README.txt')

$zipPath = Join-Path $projectRoot 'storage\app\coworker-setup.zip'
$ipZipPath = Join-Path $projectRoot ("storage\app\coworker-setup-{0}.zip" -f ($wifiAddress -replace '\.', '-'))
foreach ($candidateZip in @($zipPath, $ipZipPath)) {
    if (Test-Path -LiteralPath $candidateZip) {
        Remove-Item -LiteralPath $candidateZip -Force
    }
}
Compress-Archive -Path (Join-Path $packagePath '*') -DestinationPath $zipPath -CompressionLevel Optimal
Copy-Item -LiteralPath $zipPath -Destination $ipZipPath -Force

$verifyArgs = @()
if ($SkipAgent.IsPresent) {
    $verifyArgs += '-SkipAgent'
}
& (Join-Path $projectRoot 'verify-lan-pilot.ps1') @verifyArgs

Write-Host ''
Write-Host 'U-Mail same-Wi-Fi pilot is prepared.' -ForegroundColor Green
Write-Host "Coworker package: $packagePath"
Write-Host "Coworker ZIP:     $zipPath"
Write-Host "IP-named ZIP:     $ipZipPath"
Write-Host "Coworker URL:     https://u-mail.test/login"
Write-Host 'Registration is enabled. Until real SMTP is configured, confirmation emails are captured in private Mailpit.'
