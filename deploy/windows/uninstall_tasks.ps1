Unregister-ScheduledTask -TaskName "LeadPilot Scheduler" -Confirm:$false -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName "LeadPilot Queue Worker" -Confirm:$false -ErrorAction SilentlyContinue
Write-Host "LeadPilot tasks removed."
