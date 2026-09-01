@echo off
REM ---------------------------------------------------------------------------
REM Xpost infrastructure (WSL: Ubuntu-Redis)
REM
REM   infra.bat up      start MySQL + Redis + Mailpit and Horizon
REM   infra.bat down    stop them
REM   infra.bat status  show what is running
REM
REM ClickHouse is installed natively in the distro and is not managed here.
REM ---------------------------------------------------------------------------
setlocal
set DISTRO=Ubuntu-Redis

if "%1"=="down" goto down
if "%1"=="status" goto status

:up
REM WSL stops itself after a period of inactivity, which takes Docker and the
REM natively installed ClickHouse down with it. Touching the distro wakes it.
wsl -d %DISTRO% -- true

echo Starting MySQL 8.4 + Redis 8.10 + Mailpit...
wsl -d %DISTRO% -- bash -c "cd /mnt/d/Xpost && docker compose up -d mysql redis mailpit"
echo.
echo Starting Horizon (WSL PHP 8.5 -- Windows PHP has no pcntl)...
wsl -d %DISTRO% -- bash /mnt/d/Xpost/bin/horizon-start.sh

REM Reverb lives here for the same reason Horizon does, and it is no longer
REM optional: the posts list dropped its five second poll, so a stopped
REM Reverb means the status column simply stops moving.
echo Starting Reverb (websocket, port 8080)...
wsl -d %DISTRO% -- bash /mnt/d/Xpost/bin/reverb-start.sh
powershell -NoProfile -Command "Start-Sleep -Seconds 5"
goto status

:down
echo Stopping Reverb...
wsl -d %DISTRO% -- bash -c "pkill -f 'artisan reverb' 2>/dev/null; true"
echo Stopping Horizon...
wsl -d %DISTRO% -- bash /mnt/d/Xpost/bin/horizon-stop.sh
echo Stopping containers...
wsl -d %DISTRO% -- bash -c "cd /mnt/d/Xpost && docker compose stop mysql redis mailpit"
goto end

:status
echo.
wsl -d %DISTRO% -- bash -c "cd /mnt/d/Xpost && docker compose ps --format '  {{.Service}}  {{.Status}}  {{.Ports}}'"
echo.
wsl -d %DISTRO% -- bash -c "pgrep -c -f 'artisan horizon' >/dev/null && echo '  horizon  running' || echo '  horizon  stopped'"
echo.
echo   Web       http://localhost:8000
echo   Horizon   http://localhost:8000/horizon
echo   Mailpit   http://localhost:8025
echo   ClickHouse http://localhost:8123

:end
endlocal
