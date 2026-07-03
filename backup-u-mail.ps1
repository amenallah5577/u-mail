param(
    [string] $Label = 'manual'
)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$backupDirectory = Join-Path $projectRoot 'storage\app\pilot-backups'
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$safeLabel = $Label -replace '[^a-zA-Z0-9_-]', '-'
$destination = Join-Path $backupDirectory "u-mail-$safeLabel-$timestamp.zip"
$sources = [Collections.Generic.List[string]]::new()
$database = Join-Path $projectRoot 'database\database.sqlite'
$databaseSnapshot = Join-Path $backupDirectory "database-$timestamp.sqlite"
$privateStorage = Join-Path $projectRoot 'storage\app\private'

New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null

if (Test-Path -LiteralPath $database) {
    Copy-Item -LiteralPath $database -Destination $databaseSnapshot -Force
    $sources.Add($databaseSnapshot)
}
if (Test-Path -LiteralPath $privateStorage) {
    $sources.Add($privateStorage)
}
if ($sources.Count -eq 0) {
    throw 'No SQLite database or private attachment storage was found to back up.'
}

try {
    Compress-Archive -LiteralPath $sources.ToArray() -DestinationPath $destination -CompressionLevel Optimal
} finally {
    if (Test-Path -LiteralPath $databaseSnapshot) {
        Remove-Item -LiteralPath $databaseSnapshot -Force
    }
}
Write-Host "Backup created: $destination" -ForegroundColor Green
