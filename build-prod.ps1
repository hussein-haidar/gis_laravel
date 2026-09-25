# ============================================================
# build-prod.ps1  -  Buat zip production-ready untuk cPanel upload
# Jalankan di PowerShell:  powershell -ExecutionPolicy Bypass -File .\build-prod.ps1
# ============================================================

$ErrorActionPreference = "Stop"
$composer = "C:\composer\composer.phar"
$npm = "C:\Program Files\nodejs\npm.cmd"
$root = Resolve-Path "."
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$distName = "gis_laravel_prod_$timestamp"
$distDir = Join-Path $root "dist"

Write-Host ""
Write-Host "=== BUILD PRODUCTION READY ===" -ForegroundColor Cyan
Write-Host ""

# 1. Bersihkan cache & log Laravel
Write-Host "[1/5] Bersihkan cache & log..." -ForegroundColor Yellow
php artisan config:clear | Out-Null
php artisan route:clear | Out-Null
php artisan view:clear | Out-Null
php artisan cache:clear | Out-Null
php artisan log:clear | Out-Null
Remove-Item -Recurse -Force "storage/framework/cache/*","storage/framework/sessions/*","storage/framework/views/*","storage/framework/compiled*" -ErrorAction SilentlyContinue
Get-ChildItem "storage/logs/*.log" -ErrorAction SilentlyContinue | Remove-Item
Write-Host "   OK." -ForegroundColor Green

# 2. Install dependency production (tanpa dev)
Write-Host "[2/5] composer install --no-dev..." -ForegroundColor Yellow
php $composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | Out-Null
Write-Host "   OK." -ForegroundColor Green

# 3. Compile frontend asset
Write-Host "[3/5] npm run build..." -ForegroundColor Yellow
& $npm run build 2>&1 | Out-Null
Write-Host "   OK." -ForegroundColor Green

# 4. Buat folder dist, salin file yang dibutuhkan
Write-Host "[4/5] Menyalin file ke folder dist..." -ForegroundColor Yellow
if (Test-Path $distDir) { Remove-Item -Recurse -Force $distDir }
New-Item -ItemType Directory -Path $distDir | Out-Null

$keep = @(
    "app",
    "bootstrap",
    "config",
    "database/migrations",
    "database/seeders",
    "lang",
    "public",
    "resources",
    "routes",
    "storage/app",
    "storage/framework",
    "storage/logs",
    "vendor",
    ".env",
    ".env.example",
    ".gitignore",
    "artisan",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "vite.config.js",
    "phpunit.xml",
    ".editorconfig",
    "AKUN_LOGIN.md"
)

foreach ($item in $keep) {
    $src = Join-Path $root $item
    $dst = Join-Path $distDir $item
    if (Test-Path $src) {
        Copy-Item -Recurse -Force $src $dst -ErrorAction SilentlyContinue
    }
}

# 5. Kompresi ke zip
Write-Host "[5/5] Mengompresi ke zip..." -ForegroundColor Yellow
$zipPath = Join-Path $root "$distName.zip"
Compress-Archive -Path "$distDir/*" -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item -Recurse -Force $distDir

# 6. Info ukuran
$sizeBytes = (Get-Item $zipPath).Length
$sizeMB = $sizeBytes / 1MB
$sizeGB = $sizeBytes / 1GB
Write-Host ""
Write-Host "=== SELESAI ===" -ForegroundColor Cyan
Write-Host "Zip: $zipPath" -ForegroundColor White
Write-Host "Ukuran: $([math]::Round($sizeMB, 1)) MB ($([math]::Round($sizeGB, 3)) GB)" -ForegroundColor White
if ($sizeGB -gt 1) {
    Write-Host "  ⚠️ MASIH BESAR! Penyebab: storage/app/location_photos besar." -ForegroundColor Red
    Write-Host "  Hapus foto lawas via build-prod.ps1 sebelum ini, atau upload foto via cPanel nanti." -ForegroundColor Red
}
Write-Host ""
Write-Host "Langkah upload ke cPanel:" -ForegroundColor Cyan
Write-Host "  1. Upload zip ke public_html → Extract via File Manager" -ForegroundColor Gray
Write-Host "  2. Buat .env (salin .env.example, edit DB/email)" -ForegroundColor Gray
Write-Host "  3. Import DB via phpMyAdmin" -ForegroundColor Gray
Write-Host "  4. Set izin folder: chmod 755 storage & bootstrap/cache" -ForegroundColor Gray
Write-Host "  5. Jalankan di cPanel Terminal (jika ada SSH):" -ForegroundColor Gray
Write-Host "       composer install --no-dev --optimize-autoloader" -ForegroundColor Gray
Write-Host "       php artisan config:cache" -ForegroundColor Gray
Write-Host "       php artisan route:cache" -ForegroundColor Gray
Write-Host "       php artisan view:cache" -ForegroundColor Gray
Write-Host "       php artisan storage:link" -ForegroundColor Gray
Write-Host ""
