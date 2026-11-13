@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-plugin.ps1"
echo.
pause
