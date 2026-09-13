# PowerShell Store Release Build Script for AI Prompt Hub
# Package: com.rtmcreator.promptlibrary | Target SDK: 35 (Android 15)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptDir

Write-Host "=====================================================================" -ForegroundColor Cyan
Write-Host " AI Prompt Hub - Google Play Store & Amazon Appstore Build Script" -ForegroundColor Cyan
Write-Host " Package: com.rtmcreator.promptlibrary" -ForegroundColor Yellow
Write-Host " Target SDK: 35 (Android 15) | Version: 1.0.2 (Build 3)" -ForegroundColor Yellow
Write-Host "=====================================================================" -ForegroundColor Cyan

if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    Write-Host "`n[ERROR] Flutter SDK not found in PATH." -ForegroundColor Red
    Write-Host "Please install Flutter or add its bin directory to PATH." -ForegroundColor Yellow
    exit 1
}

Write-Host "`n[1/4] Running flutter clean..." -ForegroundColor Green
flutter clean

Write-Host "`n[2/4] Running flutter pub get..." -ForegroundColor Green
flutter pub get

Write-Host "`n[3/4] Building Google Play Store App Bundle (.aab)..." -ForegroundColor Green
flutter build appbundle --release
if ($LASTEXITCODE -ne 0) {
    Write-Host "`n[ERROR] Building .aab failed!" -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host "`n[4/4] Building Amazon Appstore & Universal Release APK (.apk)..." -ForegroundColor Green
flutter build apk --release
if ($LASTEXITCODE -ne 0) {
    Write-Host "`n[ERROR] Building .apk failed!" -ForegroundColor Red
    exit $LASTEXITCODE
}

$aabSource = Join-Path $ScriptDir "build\app\outputs\bundle\release\app-release.aab"
$apkSource = Join-Path $ScriptDir "build\app\outputs\flutter-apk\app-release.apk"
$targetDir = Split-Path -Parent $ScriptDir

if (Test-Path $aabSource) {
    $aabDest = Join-Path $targetDir "AI_Prompt_Hub_v1.0.2_PlayStore.aab"
    Copy-Item -Path $aabSource -Destination $aabDest -Force
    Write-Host "`n[OK] Copied Play Store Bundle: $aabDest" -ForegroundColor Cyan
}

if (Test-Path $apkSource) {
    $apkDest = Join-Path $targetDir "AI_Prompt_Hub_v1.0.2_Amazon_Universal.apk"
    Copy-Item -Path $apkSource -Destination $apkDest -Force
    Write-Host "[OK] Copied Amazon/Universal APK: $apkDest" -ForegroundColor Cyan
}

Write-Host "`n=====================================================================" -ForegroundColor Green
Write-Host " SUCCESS! Store-compliant production release builds completed!" -ForegroundColor Green
Write-Host "=====================================================================" -ForegroundColor Green
