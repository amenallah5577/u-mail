@echo off
setlocal EnableExtensions
title Remove U-Mail Coworker Access
cd /d "%~dp0"

if not exist "remove-u-mail-access.ps1" (
    echo The removal script is missing.
    echo Extract the complete coworker-setup folder, then run this file again.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0remove-u-mail-access.ps1"
set "RESULT=%errorlevel%"
echo.
pause
exit /b %RESULT%
