@echo off
title Reset Medical Store Demo
cd /d "%~dp0"
set TOOLS=%~dp0..\_tools
echo This deletes ALL bills, stock and medicines in the LOCAL demo database and reloads sample data.
choice /m "Continue"
if errorlevel 2 exit /b
"%TOOLS%\mariadb-11.4.13-winx64\bin\mariadb.exe" -h 127.0.0.1 -P 3307 -u root -e "DROP DATABASE IF EXISTS medstore; CREATE DATABASE medstore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"%TOOLS%\php\php.exe" scripts\seed-demo.php
pause
