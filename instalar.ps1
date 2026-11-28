# Instala AlfaVía (Laravel + React + Python) en Windows.  Uso: .\instalar.ps1 [carpeta_destino]
param([string]$Dest = "")
$ErrorActionPreference = "Stop"
$Raiz = $PSScriptRoot
if (-not $Dest) { $Dest = Join-Path $Raiz "alfavia-app" }
foreach ($c in "php","composer","npm") { if (-not (Get-Command $c -ErrorAction SilentlyContinue)) { throw "Falta $c" } }

composer create-project laravel/laravel:^12.0 $Dest --prefer-dist
Set-Location $Dest
Remove-Item "database\migrations\0001_01_01_*.php","resources\js\app.js","resources\js\bootstrap.js","resources\views\welcome.blade.php" -ErrorAction SilentlyContinue
Copy-Item "$Raiz\overlay\*" . -Recurse -Force
Copy-Item "$Raiz\ml" . -Recurse -Force
Copy-Item "$Raiz\agent" . -Recurse -Force

npm install react react-dom react-router-dom
npm install -D "@vitejs/plugin-react"

if (-not (Test-Path .env)) { Copy-Item .env.example .env }
$cambios = [ordered]@{
 APP_TIMEZONE="America/Lima"; APP_LOCALE="es"; DB_CONNECTION="pgsql"; DB_HOST="127.0.0.1"; DB_PORT="5432"
 DB_DATABASE="alfavia_monitoreo"; DB_USERNAME="postgres"; DB_PASSWORD="postgres"
 SESSION_DRIVER="file"; SESSION_LIFETIME="720"; SESSION_SAME_SITE="strict"; CACHE_STORE="file"; QUEUE_CONNECTION="sync"
 ML_URL="http://127.0.0.1:5001"; ML_TOKEN="token-de-prueba-local-0123456789abcdef"; GEMINI_API_KEY=""; GEMINI_MODEL="gemini-2.5-flash"
}
$lineas = Get-Content .env
foreach ($k in $cambios.Keys) {
  $nuevo = "$k=$($cambios[$k])"; $hallado = $false
  for ($i=0; $i -lt $lineas.Count; $i++) { if ($lineas[$i] -match "^#?\s*$k=") { $lineas[$i] = $nuevo; $hallado = $true } }
  if (-not $hallado) { $lineas += $nuevo }
}
Set-Content .env $lineas -Encoding utf8
php artisan key:generate --force
Write-Host "Edita .env (DB_PASSWORD, GEMINI_API_KEY), crea la BD alfavia_monitoreo y ejecuta: php artisan migrate; php artisan db:seed --class=DemoSeeder"
