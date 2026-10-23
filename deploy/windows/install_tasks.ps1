# LeadPilot AI - register Windows Task Scheduler jobs (run once, in PowerShell AS ADMINISTRATOR)
# Replaces keeping terminal windows open. Survives logoff/reboot. Restarts the worker if it dies.
#   Usage:  powershell -ExecutionPolicy Bypass -File deploy\windows\install_tasks.ps1
param(
    [string]$ProjectDir = (Resolve-Path "$PSScriptRoot\..\..").Path,
    [string]$Php = "C:\xampp\php\php.exe"
)
if (-not (Test-Path $Php)) { Write-Error "php.exe not found at $Php (pass -Php <path>)"; exit 1 }
$user = "$env:USERDOMAIN\$env:USERNAME"

# 1) Scheduler: php artisan schedule:run every minute
$a1 = New-ScheduledTaskAction -Execute $Php -Argument "artisan schedule:run" -WorkingDirectory $ProjectDir
$t1 = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
$s1 = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
Register-ScheduledTask -TaskName "LeadPilot Scheduler" -Action $a1 -Trigger $t1 -Settings $s1 -User $user -RunLevel Limited -Force | Out-Null

# 2) Queue worker: restarts hourly (--max-time) and on failure; started at boot + every 5 min if not running
$a2 = New-ScheduledTaskAction -Execute $Php -Argument "artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --timeout=1800" -WorkingDirectory $ProjectDir
$t2a = New-ScheduledTaskTrigger -AtStartup
$t2b = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5)
$s2 = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Hours 2)
Register-ScheduledTask -TaskName "LeadPilot Queue Worker" -Action $a2 -Trigger @($t2a, $t2b) -Settings $s2 -User $user -RunLevel Limited -Force | Out-Null

Write-Host "Registered: 'LeadPilot Scheduler' (every minute) and 'LeadPilot Queue Worker' (boot + watchdog every 5 min)."
Write-Host "Check health in the app: Setup & Health, or: php artisan leadpilot:doctor"
Write-Host "NOTE: tasks run while you are logged in. For 24/7 on a server, edit the tasks to 'Run whether user is logged on or not'."
