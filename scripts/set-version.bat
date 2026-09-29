@echo off
rem Updates every current-release reference from the value stored in VERSION.
rem Historical CHANGELOG entries are intentionally never modified.
setlocal EnableExtensions DisableDelayedExpansion

rem Resolve the project root from scripts\set-version.bat and work from there.
pushd "%~dp0.." >nul 2>&1
if errorlevel 1 (
    echo Unable to open the project directory. 1>&2
    exit /b 1
)

rem Display usage information when requested or when the version is invalid.
if "%~1"=="-h" goto :usage_success
if "%~1"=="--help" goto :usage_success
if "%~1"=="" goto :usage_error

set "REQUESTED_VERSION=%~1"

rem Validate semantic version syntax and derive release and numeric versions.
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ^
    "$v = $env:REQUESTED_VERSION; if ($v -notmatch '^\d+\.\d+\.\d+(?:-beta)?$') { exit 2 }; exit 0"
if errorlevel 1 goto :usage_error

set "NEW_RELEASE=%REQUESTED_VERSION%"
if /i not "%NEW_RELEASE:~-5%"=="-beta" set "NEW_RELEASE=%NEW_RELEASE%-beta"
set "NEW_NUMERIC=%NEW_RELEASE:-beta=%"

rem Read and validate the currently configured release version.
if not exist "VERSION" (
    echo Required VERSION file is missing. 1>&2
    goto :failure
)
set "CURRENT_RELEASE="
set /p "CURRENT_RELEASE=" < "VERSION"
set "CURRENT_RELEASE=%CURRENT_RELEASE: =%"

powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ^
    "$v = $env:CURRENT_RELEASE; if ($v -notmatch '^\d+\.\d+\.\d+-beta$') { exit 2 }; exit 0"
if errorlevel 1 (
    echo Invalid current VERSION value: %CURRENT_RELEASE% 1>&2
    goto :usage_failure
)
set "CURRENT_NUMERIC=%CURRENT_RELEASE:-beta=%"

rem Avoid unnecessary writes when the requested release is already active.
if /i "%NEW_RELEASE%"=="%CURRENT_RELEASE%" (
    echo Version is already %NEW_RELEASE%. Running quality checks only.
    call :run_quality_checks
    if errorlevel 1 goto :failure
    set "RESULT=0"
    goto :finish
)

rem Verify every required file before changing any project content.
for %%F in (
    "src\AppInfo.php"
    "Dockerfile"
    "README.md"
    "docs\installation.md"
    "docs\reference.md"
    "public\index.php"
    "public\login.php"
    "public\profile.php"
    "public\verify_email.php"
    "public\assets\switchly-logo.svg"
) do (
    if not exist "%%~F" (
        echo Required version file is missing: %%~F 1>&2
        goto :failure
    )
)

rem Replace literal version strings while preserving UTF-8 without a BOM.
for %%F in (
    "src\AppInfo.php"
    "Dockerfile"
    "README.md"
    "docs\installation.md"
    "docs\reference.md"
    "public\index.php"
    "public\login.php"
    "public\profile.php"
    "public\verify_email.php"
    "public\assets\switchly-logo.svg"
) do (
    set "VERSION_FILE=%%~fF"
    powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ^
        "$p = $env:VERSION_FILE; $utf8 = New-Object System.Text.UTF8Encoding($false); $text = [IO.File]::ReadAllText($p); $text = $text.Replace($env:CURRENT_RELEASE, $env:NEW_RELEASE).Replace($env:CURRENT_NUMERIC, $env:NEW_NUMERIC); [IO.File]::WriteAllText($p, $text, $utf8)"
    if errorlevel 1 (
        echo Failed to update version references in %%~F. 1>&2
        goto :failure
    )
)

rem Store the canonical beta version in VERSION using UTF-8 without a BOM.
set "VERSION_FILE=%CD%\VERSION"
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ^
    "$utf8 = New-Object System.Text.UTF8Encoding($false); [IO.File]::WriteAllText($env:VERSION_FILE, $env:NEW_RELEASE + [Environment]::NewLine, $utf8)"
if errorlevel 1 (
    echo Failed to update VERSION. 1>&2
    goto :failure
)

echo Updated Switchly from %CURRENT_RELEASE% to %NEW_RELEASE%.
echo CHANGELOG.md was not modified; add the release notes manually.
call :run_quality_checks
set "RESULT=%ERRORLEVEL%"
goto :finish

:run_quality_checks
rem Locate Git Bash explicitly so Windows does not select the WSL bash launcher.
call :find_git_bash
if not defined BASH_EXE (
    echo Git Bash was not found. Install Git for Windows or run scripts\quality-check.sh manually. 1>&2
    exit /b 1
)
"%BASH_EXE%" scripts/quality-check.sh
exit /b %ERRORLEVEL%

:find_git_bash
rem Probe the standard system-wide and per-user Git for Windows locations.
set "BASH_EXE="
for %%B in (
    "%ProgramFiles%\Git\bin\bash.exe"
    "%ProgramFiles%\Git\usr\bin\bash.exe"
    "%LocalAppData%\Programs\Git\bin\bash.exe"
    "%LocalAppData%\Programs\Git\usr\bin\bash.exe"
) do (
    if not defined BASH_EXE if exist "%%~B" set "BASH_EXE=%%~fB"
)

rem Also derive Git Bash from any git.exe available on PATH, including portable Git.
if not defined BASH_EXE (
    for /f "delims=" %%G in ('where git.exe 2^>nul') do (
        if not defined BASH_EXE call :probe_git_install "%%~dpG.."
    )
)
exit /b 0

:probe_git_install
rem Resolve a candidate Git root and accept only its bundled bash.exe.
for %%D in ("%~1") do set "GIT_ROOT=%%~fD"
if exist "%GIT_ROOT%\bin\bash.exe" set "BASH_EXE=%GIT_ROOT%\bin\bash.exe"
if not defined BASH_EXE if exist "%GIT_ROOT%\usr\bin\bash.exe" set "BASH_EXE=%GIT_ROOT%\usr\bin\bash.exe"
exit /b 0

:usage_success
call :usage
set "RESULT=0"
goto :finish

:usage_error
call :usage 1>&2
set "RESULT=2"
goto :finish

:usage_failure
set "RESULT=2"
goto :finish

:failure
set "RESULT=1"
goto :finish

:usage
echo Usage: scripts\set-version.bat ^<major.minor.patch[-beta]^>
echo Example: scripts\set-version.bat 1.4.8
exit /b 0

:finish
popd >nul 2>&1
endlocal & exit /b %RESULT%
