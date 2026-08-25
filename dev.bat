@echo off
REM ---------------------------------------------------------------------------
REM Xpost development stack.
REM
REM Run infra.bat first (MySQL, Redis, Mailpit, Horizon in WSL), then this for
REM the Windows-side processes: web server, scheduler, and Vite.
REM
REM The scheduler window is not optional -- it runs xpost:dispatch every minute,
REM and without it scheduled posts sit in `pending` forever.
REM ---------------------------------------------------------------------------
setlocal
set PHP=%~dp0.tools\php85\php.exe
set ART=%~dp0artisan

REM Clear any orphaned server from a previous run before binding port 8000.
call "%~dp0stop.bat"

echo Starting infrastructure...
call "%~dp0infra.bat" up

echo.
echo Starting Windows processes...
start "Xpost web"       cmd /k "%PHP%" "%ART%" serve --port=8000
start "Xpost scheduler" cmd /k "%PHP%" "%ART%" schedule:work
start "Xpost vite"      cmd /k npm run dev

echo.
echo   Web       http://localhost:8000   admin@xpost.test / password
echo   Horizon   http://localhost:8000/horizon
echo   Mailpit   http://localhost:8025
endlocal
