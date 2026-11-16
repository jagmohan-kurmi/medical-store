@echo off
rem Stops only the demo web server on port 8095. The shared database keeps running for other projects.
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -match '127.0.0.1:8095' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"
echo Medical Store demo stopped.
timeout /t 3 >nul
