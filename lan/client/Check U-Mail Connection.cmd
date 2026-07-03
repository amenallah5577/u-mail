@echo off
setlocal EnableExtensions
title Check U-Mail Connection
cd /d "%~dp0"

if not exist "check-u-mail-connection.ps1" (
    echo The diagnostic script is missing.
    echo Extract the complete coworker-setup folder, then run this file again.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0check-u-mail-connection.ps1"
set "RESULT=%errorlevel%"
echo.
if "%RESULT%"=="0" (
    echo Check finished: this laptop can reach U-Mail.
) else (
    echo Check found a problem. Send the text above to the U-Mail host.
)
echo.
pause
exit /b %RESULT%
