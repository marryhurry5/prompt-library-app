@echo off
setlocal enabledelayedexpansion

echo =====================================================================
echo  AI Prompt Hub - Google Play Store ^& Amazon Appstore Build Script
echo  Package: com.rtmcreator.promptlibrary
echo  Version: 1.0.2 (Build 3) | Target SDK: 35 (Android 15)
echo =====================================================================
echo.

cd /d "%~dp0"

where flutter >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Flutter SDK was not found in your system PATH!
    echo Please ensure Flutter is installed and added to PATH, or run:
    echo set PATH=C:\path\to\flutter\bin;%%PATH%%
    pause
    exit /b 1
)

echo [1/4] Cleaning previous builds...
call flutter clean

echo.
echo [2/4] Fetching dependencies...
call flutter pub get

echo.
echo [3/4] Building Google Play Store Android App Bundle (.aab)...
call flutter build appbundle --release
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Failed to build Google Play App Bundle (.aab)!
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo [4/4] Building Amazon Appstore ^& Universal Release APK (.apk)...
call flutter build apk --release
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Failed to build Amazon Release APK (.apk)!
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo [COPIES] Copying build outputs to project root for easy access...
if exist "build\app\outputs\bundle\release\app-release.aab" (
    copy /y "build\app\outputs\bundle\release\app-release.aab" "..\AI_Prompt_Hub_v1.0.2_PlayStore.aab" >nul
    echo  [OK] Copied: ..\AI_Prompt_Hub_v1.0.2_PlayStore.aab
)
if exist "build\app\outputs\flutter-apk\app-release.apk" (
    copy /y "build\app\outputs\flutter-apk\app-release.apk" "..\AI_Prompt_Hub_v1.0.2_Amazon_Universal.apk" >nul
    echo  [OK] Copied: ..\AI_Prompt_Hub_v1.0.2_Amazon_Universal.apk
)

echo.
echo =====================================================================
echo  SUCCESS! Both store-compliant production release builds are ready:
echo.
echo  1. Google Play Console:
echo     ..\AI_Prompt_Hub_v1.0.2_PlayStore.aab
echo.
echo  2. Amazon Appstore / Direct Install:
echo     ..\AI_Prompt_Hub_v1.0.2_Amazon_Universal.apk
echo =====================================================================
echo.
pause
