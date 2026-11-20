# Ejecutar dentro de la carpeta del proyecto (C:\alfavia-app) despues de extraer el parche encima.
Remove-Item "app\Http\Controllers\Api\ProveedorController.php" -ErrorAction SilentlyContinue
Remove-Item "resources\js\pages\Proveedores.jsx" -ErrorAction SilentlyContinue
npm run build
Write-Host "Listo. Reinicia 'php artisan serve' y recarga el navegador con Ctrl+F5."
