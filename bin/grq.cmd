@echo off
REM grq launcher for Windows — selects prebuilt binary (no download).
setlocal
set "DIST=%~dp0dist"
set "ARCH=%PROCESSOR_ARCHITECTURE%"
if /I "%ARCH%"=="AMD64" set "BIN=grq-windows-amd64.exe"
if /I "%ARCH%"=="ARM64" set "BIN=grq-windows-arm64.exe"
if not defined BIN (
  echo grq: unsupported arch %ARCH% 1>&2
  exit /b 1
)
if not exist "%DIST%\%BIN%" (
  echo grq: missing %DIST%\%BIN% — run make grq-release 1>&2
  exit /b 1
)
"%DIST%\%BIN%" %*
