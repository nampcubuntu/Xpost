@echo off
REM ---------------------------------------------------------------------------
REM Xpost development stack -- everything, in one command.
REM
REM   dev.bat            web + scheduler + Vite, plus infra
REM   dev.bat tunnel     the same, with Vite swapped for the Cloudflare tunnel
REM
REM Four things have to be alive at once and they die for different reasons:
REM MySQL and Redis run in Docker inside WSL, which sleeps on its own and takes
REM them with it; Horizon runs in WSL too (Windows PHP has no pcntl); the web
REM server and the tunnel run here and die with their window. This starts all of
REM them so a cold machine is one command, not four.
REM
REM The scheduler window is not optional -- it runs xpost:dispatch every minute,
REM and without it scheduled posts sit in `pending` forever.
REM ---------------------------------------------------------------------------
setlocal
set PHP=%~dp0.tools\php85\php.exe
set ART=%~dp0artisan
set CFD=%ProgramFiles(x86)%\cloudflared\cloudflared.exe

REM Clear any orphaned server from a previous run before binding port 8000.
call "%~dp0stop.bat"

echo Starting infrastructure...
call "%~dp0infra.bat" up

echo.
echo Starting Windows processes...

start "Xpost web"       cmd /k ""%PHP%" "%ART%" serve --host=127.0.0.1 --port=8000"
start "Xpost scheduler" cmd /k ""%PHP%" "%ART%" schedule:work"

if /I "%1"=="tunnel" goto tunnel

start "Xpost vite" cmd /k npm run dev

echo.
echo   Web       http://localhost:8000
echo   Horizon   http://localhost:8000/horizon
echo   Mailpit   http://localhost:8025
echo.
echo   Vite is running, so .vue changes are live. For OAuth work run
echo   "dev.bat tunnel" instead -- Vite serves assets over http, which a
echo   browser blocks as mixed content on the tunnel's https page.
goto done

:tunnel
REM 127.0.0.1, never "localhost": Windows resolves that to ::1 first, and
REM `artisan serve` only binds IPv4. The tunnel comes up and forwards into
REM nothing, which looks like the app being down.
if not exist "%CFD%" (
    echo.
    echo   cloudflared not found at "%CFD%".
    echo   Install it, or run "dev.bat" without the tunnel argument.
    goto done
)

start "Xpost tunnel" cmd /k "%CFD%" tunnel run --url http://127.0.0.1:8000 xpost-dev

echo.
echo   Tunnel    https://dev.zenomedia.co   (fixed hostname, safe to register with X)
echo   Web       http://localhost:8000
echo   Horizon   http://localhost:8000/horizon
echo.
echo   Vite is deliberately NOT started: it serves assets over
echo   http://localhost:5173, which the browser blocks on an https page and
echo   leaves the SPA blank. Build once with "npm run build" after .vue edits.
echo.
echo   Point .env at the tunnel:  bash bin/use-tunnel.sh https://dev.zenomedia.co
echo   And back again:            bash bin/use-tunnel.sh off

:done
endlocal
