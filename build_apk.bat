@echo off
echo ============================================================
echo   Building AI Prompt Hub Release APK
echo ============================================================
echo.

cd prompt_library_app

echo [1/3] Fetching Flutter packages...
call flutter pub get
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Flutter pub get failed. Ensure Flutter SDK is installed and in PATH.
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo [2/3] Building Release APK...
call flutter build apk --release
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] APK Build failed. Check Android SDK and Java installation.
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo [3/3] Copying APK to root directory...
copy /Y "build\app\outputs\flutter-apk\app-release.apk" "..\PromptLibrary_v1.0.2_Release.apk"

echo.
echo ============================================================
echo   SUCCESS! Release APK generated at:
echo   PromptLibrary_v1.0.2_Release.apk
echo ============================================================
pause
