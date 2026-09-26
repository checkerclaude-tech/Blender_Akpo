@echo off
setlocal EnableDelayedExpansion
REM Opens Blender and imports every FBX from the folder below into one scene.
REM Change FBX_FOLDER if your models live somewhere else.

set "FBX_FOLDER=C:\Users\HP\OneDrive\Desktop\All 3D Built"
set "SCRIPT=%~dp0import_all_fbx.py"

REM --- Find blender.exe (newest version in Program Files, else on PATH) ---
set "BLENDER="
for /d %%D in ("%ProgramFiles%\Blender Foundation\Blender*") do (
    if exist "%%D\blender.exe" set "BLENDER=%%D\blender.exe"
)
if not defined BLENDER (
    for %%B in (blender.exe) do set "BLENDER=%%~$PATH:B"
)
if not defined BLENDER (
    echo Could not find blender.exe.
    echo Edit this file and set BLENDER to the full path of blender.exe.
    pause
    exit /b 1
)

echo Using Blender: !BLENDER!
echo Importing FBX files from: %FBX_FOLDER%
"!BLENDER!" --python "%SCRIPT%" -- "%FBX_FOLDER%"
