@echo off
REM ---------------------------------------------------------------------------
REM Stop every Xpost PHP process on the Windows side.
REM
REM `artisan serve` spawns a child `php -S` that survives when only the parent is
REM killed. The orphan keeps holding port 8000 with the php.ini it started with,
REM so an ini change appears to have no effect. Match on the project path to
REM catch both, and leave any unrelated PHP alone.
REM ---------------------------------------------------------------------------
powershell -NoProfile -Command ^
  "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*%~dp0*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Write-Host 'Stopped Xpost PHP processes.'"
