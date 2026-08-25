@echo off
REM Artisan wrapper -- uses the portable PHP 8.5, not the system 8.2
"%~dp0.tools\php85\php.exe" "%~dp0artisan" %*
