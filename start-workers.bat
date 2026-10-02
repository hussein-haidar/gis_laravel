@echo off
REM Background workers untuk GIS Laravel: scheduler + queue worker.
REM Dijalankan otomatis oleh Task Scheduler setiap kali Windows start.

setlocal
cd /d "C:\xampp\htdocs\gis_laravel"

set PHP="C:\xampp\php\php.exe"

start "GIS Scheduler" /min %PHP% artisan schedule:work
start "GIS Queue Worker" /min %PHP% artisan queue:work --tries=3 --timeout=300

endlocal
