@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0lan\configure-host.ps1"
echo.
echo After this finishes, ask the coworker to rerun Install U-Mail Access.cmd from the latest ZIP.
pause
