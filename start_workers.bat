@echo off
title LeadPilot AI - Worker Launcher
echo ========================================================
echo   Starting LeadPilot AI Queue Worker & Scheduler
echo ========================================================
echo.

cd /d "%~dp0"

echo [1/2] Launching Queue Worker (database)...
start "LeadPilot - Queue Worker" cmd /k "title LeadPilot - Queue Worker && cd /d %~dp0 && php artisan queue:work database --tries=3"

echo [2/2] Launching Task Scheduler (schedule:work)...
start "LeadPilot - Scheduler" cmd /k "title LeadPilot - Scheduler && cd /d %~dp0 && php artisan schedule:work"

echo.
echo ========================================================
echo  Workers are running in separate terminal windows!
echo  Leave them open to process hunts, audits, and schedules.
echo ========================================================
echo.
timeout /t 5
