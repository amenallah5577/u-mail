@echo off
setlocal EnableExtensions
title U-Mail Coworker Setup
cd /d "%~dp0"

if not exist "install-u-mail-access.ps1" (
    echo The installer script is missing.
    echo Extract the complete coworker-setup folder, then run this file again.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-u-mail-access.ps1"
set "RESULT=%errorlevel%"

echo.
if "%RESULT%"=="0" (
    echo Setup finished.
) else (
    echo Setup did not finish. See the message above.
)
echo.
pause
exit /b %RESULT%
