@echo off
title City Care Medical Store - Demo
cd /d "%~dp0"
set TOOLS=%~dp0..\_tools
set PHP=%TOOLS%\php\php.exe
set MARIADB=%TOOLS%\mariadb-11.4.13-winx64\bin

echo ==============================================
echo   Medical Store Management - starting demo...
echo ==============================================

netstat -an | find ":3307 " | find "LISTENING" >nul
if errorlevel 1 (
  echo Starting database...
  powershell -NoProfile -Command "Start-Process -WindowStyle Hidden -FilePath '%MARIADB%\mariadbd.exe' -ArgumentList '--datadir=\"%TOOLS%\mariadb-data\"','--port=3307'"
  for /l %%i in (1,1,20) do (
    "%MARIADB%\mysqladmin.exe" -h 127.0.0.1 -P 3307 -u root ping >nul 2>nul && goto dbready
    timeout /t 1 /nobreak >nul
  )
)
:dbready

rem Loads sample data the first time; does nothing once bills exist.
"%PHP%" scripts\seed-demo.php

netstat -an | find "127.0.0.1:8095 " | find "LISTENING" >nul
if errorlevel 1 (
  echo Starting web server...
  rem Runs in the background with no window, so it cannot be closed by mistake.
  powershell -NoProfile -Command "Start-Process -WindowStyle Hidden -WorkingDirectory '%~dp0' -FilePath '%PHP%' -ArgumentList '-S','127.0.0.1:8095','-t','public_html'"
  for /l %%i in (1,1,10) do (
    netstat -an | find "127.0.0.1:8095 " | find "LISTENING" >nul && goto webready
    timeout /t 1 /nobreak >nul
  )
)
:webready

start "" "http://127.0.0.1:8095/login.php?demo=admin"
echo.
echo Demo is open in your browser: http://127.0.0.1:8095
echo It keeps running in the background until you run "Stop Demo.bat" or restart the PC.
echo After a restart, open the demo again from the Desktop shortcut.
timeout /t 5 >nul
