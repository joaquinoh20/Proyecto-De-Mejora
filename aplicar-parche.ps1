# Ejecutar dentro de la carpeta del proyecto (la que contiene artisan), despues de extraer el parche encima.
$ErrorActionPreference = 'Stop'
if (-not (Test-Path artisan)) { Write-Host "Esta no es la carpeta del proyecto (no hay 'artisan'). Entra a la carpeta correcta y vuelve a ejecutar."; exit 1 }
if (-not (Test-Path routes\api.php)) { Write-Host "No existe routes\api.php."; exit 1 }

# 1) Registrar la ruta del agente (/api/agent/rendimiento) una sola vez
if (-not (Select-String -Path routes\api.php -Pattern 'rendimiento_agente' -Quiet)) {
  Add-Content -Path routes\api.php -Value "`r`nrequire __DIR__.'/rendimiento_agente.php';"
  Write-Host "Ruta del agente registrada en routes\api.php"
}

# 2) Quitar el simulador de datos (ya no se usa)
Remove-Item "agent\simulador.py","agent\sim_control.json" -ErrorAction SilentlyContinue

# 3) Limpiar cache y compilar el frontend
$env:Path += ";C:\xampp\php"
php artisan optimize:clear
npm run build

Write-Host ""
Write-Host "Listo. Siguiente: ejecuta database\sql\06_datos_reales.sql en pgAdmin (base Alfavia_monitoreo), reinicia 'php artisan serve --host=0.0.0.0 --port=8000' y recarga con Ctrl+F5."
