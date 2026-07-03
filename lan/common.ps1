Set-StrictMode -Version Latest

function Get-UmailCaddyPath {
    $command = Get-Command caddy.exe -ErrorAction SilentlyContinue
    $candidates = @(
        $(if ($command) { $command.Source }),
        (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Links\caddy.exe'),
        (Join-Path $env:ProgramFiles 'Caddy\caddy.exe')
    ) | Where-Object { $_ }

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'Caddy is not installed. Run: winget install --id CaddyServer.Caddy --exact'
}

function Get-UmailNodePath {
    $command = Get-Command node.exe -ErrorAction SilentlyContinue
    $candidates = @(
        $(if ($command) { $command.Source }),
        (Join-Path $env:ProgramFiles 'nodejs\node.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'nodejs\node.exe')
    ) | Where-Object { $_ }

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'Node.js is not installed. Install Node.js, then run start-u-mail.ps1 again.'
}

function Get-UmailOllamaPath {
    $command = Get-Command ollama.exe -ErrorAction SilentlyContinue
    $candidates = @(
        $(if ($command) { $command.Source }),
        (Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'),
        (Join-Path $env:ProgramFiles 'Ollama\ollama.exe')
    ) | Where-Object { $_ }

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'Ollama is not installed. Install it on the host machine from https://ollama.com/download and run this script again.'
}

function Get-UmailWifiAddress {
    $address = Get-NetIPAddress -InterfaceAlias 'Wi-Fi' -AddressFamily IPv4 -AddressState Preferred -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '169.254.*' } |
        Select-Object -First 1

    if (-not $address) {
        throw 'No active IPv4 address was found on the Wi-Fi interface.'
    }

    return $address.IPAddress
}

function Test-UmailTcpPort {
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

function Test-UmailAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-UmailCaddyRootCertificate {
    param([Parameter(Mandatory)][string] $CaddyPath)

    $appDataLine = & $CaddyPath environ 2>$null | Where-Object { $_ -like 'caddy.AppDataDir=*' } | Select-Object -First 1
    if ($appDataLine) {
        $appDataPath = $appDataLine.Substring('caddy.AppDataDir='.Length)
        $root = Join-Path $appDataPath 'pki\authorities\local\root.crt'
        if (Test-Path -LiteralPath $root) {
            return (Resolve-Path -LiteralPath $root).Path
        }
    }

    $fallbacks = @(
        (Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'),
        (Join-Path $env:LOCALAPPDATA 'Caddy\pki\authorities\local\root.crt')
    )

    foreach ($fallback in $fallbacks) {
        if (Test-Path -LiteralPath $fallback) {
            return (Resolve-Path -LiteralPath $fallback).Path
        }
    }

    throw 'The Caddy local root certificate has not been generated yet.'
}

function Get-UmailGatewayCertificatePaths {
    param([Parameter(Mandatory)][string] $ProjectRoot)

    $certificatePath = Join-Path $ProjectRoot 'storage\app\lan-certs'

    return [pscustomobject]@{
        Directory = $certificatePath
        Root = Join-Path $certificatePath 'u-mail-root.crt'
        ServerPfx = Join-Path $certificatePath 'u-mail-server.pfx'
        Password = Join-Path $certificatePath 'u-mail-server.pass'
    }
}

function Ensure-UmailGatewayCertificate {
    param([Parameter(Mandatory)][string] $ProjectRoot)

    $paths = Get-UmailGatewayCertificatePaths -ProjectRoot $ProjectRoot
    New-Item -ItemType Directory -Path $paths.Directory -Force | Out-Null

    if (-not ((Test-Path -LiteralPath $paths.Root) -and (Test-Path -LiteralPath $paths.ServerPfx) -and (Test-Path -LiteralPath $paths.Password))) {
        $password = [Guid]::NewGuid().ToString('N')
        $securePassword = ConvertTo-SecureString -String $password -AsPlainText -Force

        $root = New-SelfSignedCertificate `
            -Type Custom `
            -Subject 'CN=U-Mail Local Pilot Root' `
            -KeyAlgorithm RSA `
            -KeyLength 2048 `
            -HashAlgorithm SHA256 `
            -KeyExportPolicy Exportable `
            -KeyUsage CertSign, CRLSign, DigitalSignature `
            -CertStoreLocation 'Cert:\CurrentUser\My' `
            -NotAfter (Get-Date).AddYears(5) `
            -TextExtension @('2.5.29.19={critical}{text}ca=TRUE&pathlength=1')

        $server = New-SelfSignedCertificate `
            -Type Custom `
            -Subject 'CN=u-mail.test' `
            -DnsName 'u-mail.test', 'localhost' `
            -Signer $root `
            -KeyAlgorithm RSA `
            -KeyLength 2048 `
            -HashAlgorithm SHA256 `
            -KeyExportPolicy Exportable `
            -KeyUsage DigitalSignature, KeyEncipherment `
            -CertStoreLocation 'Cert:\CurrentUser\My' `
            -NotAfter (Get-Date).AddYears(3) `
            -TextExtension @('2.5.29.17={text}DNS=u-mail.test&DNS=localhost&IPAddress=127.0.0.1')

        Export-Certificate -Cert $root -FilePath $paths.Root -Force | Out-Null
        Export-PfxCertificate -Cert $server -FilePath $paths.ServerPfx -Password $securePassword -Force | Out-Null
        Set-Content -LiteralPath $paths.Password -Value $password -Encoding ASCII
    }

    $rootCertificate = [Security.Cryptography.X509Certificates.X509Certificate2]::new($paths.Root)
    $trustedRoot = Get-ChildItem 'Cert:\CurrentUser\Root' -ErrorAction SilentlyContinue |
        Where-Object { $_.Thumbprint -eq $rootCertificate.Thumbprint } |
        Select-Object -First 1

    if (-not $trustedRoot) {
        Import-Certificate -FilePath $paths.Root -CertStoreLocation 'Cert:\CurrentUser\Root' | Out-Null
    }

    return [pscustomobject]@{
        RootPath = $paths.Root
        ServerPfxPath = $paths.ServerPfx
        PasswordPath = $paths.Password
        RootThumbprint = $rootCertificate.Thumbprint
    }
}

function Set-UmailHostsEntry {
    param(
        [Parameter(Mandatory)][string] $Address,
        [string] $Hostname = 'u-mail.test'
    )

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
        if (-not $insideBlock -and $line -notmatch "(?i)(^|\s)$([regex]::Escape($Hostname))(\s|$)") {
            $lines.Add($line)
        }
    }

    $lines.Add($startMarker)
    $lines.Add("$Address`t$Hostname")
    $lines.Add($endMarker)
    [IO.File]::WriteAllLines($hostsPath, $lines, [Text.Encoding]::ASCII)
    Clear-DnsClientCache
}

function Remove-UmailHostsEntry {
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
        if (-not $insideBlock -and $line -notmatch '(?i)(^|\s)u-mail\.test(\s|$)') {
            $lines.Add($line)
        }
    }

    [IO.File]::WriteAllLines($hostsPath, $lines, [Text.Encoding]::ASCII)
    Clear-DnsClientCache
}
