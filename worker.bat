@echo off
REM Worker Batch Script for Windows
REM Designed to run with NSSM (Non-Sucking Service Manager) as a Windows service
REM Can also be run directly for testing: worker.bat [--poll-interval=N]

REM ======== Install the service =================================================
REM nssm install workerEditor "C:\path\to\worker.bat"

REM Configure the service (optional)
REM nssm set workerEditor AppDirectory "C:\path\to\project\root"
REM nssm set workerEditor AppStdout "C:\path\to\project\root\logs\worker.log"
REM nssm set workerEditor AppStderr "C:\path\to\project\root\logs\worker-error.log"

REM Start the service
REM nssm start workerEditor
REM ==============================================================================

REM Get the directory where this batch file is located
set "SCRIPT_DIR=%~dp0"
set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"

REM Change to the script directory (project root)
cd /d "%SCRIPT_DIR%"

REM Build the PHP command
set "WORKER_CMD=php index.php cli/worker/run"

REM Pass through any command-line arguments (e.g., --poll-interval=10)
if not "%~1"=="" (
    set "WORKER_CMD=%WORKER_CMD% %*"
)

REM Ensure logs directory exists
if not exist "logs" mkdir logs

REM Run the worker
REM Note: When run as NSSM service, NSSM will handle output redirection
REM For direct execution, output goes to console
%WORKER_CMD%

REM Exit with the same code as PHP
exit /b %ERRORLEVEL%
