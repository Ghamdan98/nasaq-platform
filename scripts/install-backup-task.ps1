param([switch]$RunWhenLoggedOff)

$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runnerPath = Join-Path $projectRoot 'scripts\run-daily-backup.ps1'
if (-not (Test-Path -LiteralPath $runnerPath -PathType Leaf)) { throw 'Backup runner was not found.' }
if ((Get-TimeZone).Id -ne 'Arab Standard Time') { throw 'This task expects the Windows timezone to be Riyadh (Arab Standard Time). No task was registered.' }
$taskName = 'Settlement-DailyBackup'
$arguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $runnerPath + '"'
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing -and ($existing.Actions.Arguments -notcontains $arguments)) { throw 'A different task already uses this name. No task was replaced.' }
$action = New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Argument $arguments -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At '02:00'
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 6) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$logonType = if ($RunWhenLoggedOff) { 'S4U' } else { 'Interactive' }
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType $logonType -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Daily encrypted Settlement database and attachments backup with an isolated restore verification.' -Force | Select-Object TaskName,State
