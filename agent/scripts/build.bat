@echo off
rem Build and test on Windows with the MSVC Build Tools and Ninja:
rem   scripts\build.bat [Debug|Release]
rem Needs CMake and Ninja on PATH (e.g. pip install cmake ninja) and the
rem "Desktop development with C++" workload of Visual Studio or its Build Tools.
setlocal
set "CONFIG=%~1"
if "%CONFIG%"=="" set "CONFIG=Debug"

for /f "usebackq tokens=*" %%i in (`"%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VSINSTALL=%%i"
if not defined VSINSTALL (
  echo Visual Studio C++ build tools not found
  exit /b 1
)
call "%VSINSTALL%\VC\Auxiliary\Build\vcvars64.bat" >nul || exit /b 1

cd /d "%~dp0.."
cmake -G Ninja -S . -B build -DCMAKE_BUILD_TYPE=%CONFIG% || exit /b 1
cmake --build build || exit /b 1
ctest --test-dir build --output-on-failure -j 4 || exit /b 1
