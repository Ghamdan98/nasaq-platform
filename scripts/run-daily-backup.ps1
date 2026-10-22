$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$phpExecutable = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) { throw 'XAMPP PHP executable was not found.' }
Set-Location -LiteralPath $projectRoot
$logPath = Join-Path $projectRoot 'storage\logs\backup-task.log'
try {
    "[$(Get-Date -Format o)] Backup started" | Out-File -LiteralPath $logPath -Append -Encoding utf8
    $backupOutput = & $phpExecutable (Join-Path $projectRoot 'artisan') backup:run 2>&1
    $backupExitCode = $LASTEXITCODE
    $backupOutput | Out-File -LiteralPath $logPath -Append -Encoding utf8
    "[$(Get-Date -Format o)] Backup finished; exit=$backupExitCode" | Out-File -LiteralPath $logPath -Append -Encoding utf8
    exit $backupExitCode
} catch {
    "[$(Get-Date -Format o)] Backup failed: $($_.Exception.Message)" | Out-File -LiteralPath $logPath -Append -Encoding utf8
    exit 1
}
