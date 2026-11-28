#!/usr/bin/env bash
# Instala AlfaVía (Laravel + React + Python) creando un proyecto Laravel limpio y aplicando el "overlay".
# Uso: ./instalar.sh [carpeta_destino]   (por defecto ./alfavia-app)
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")" && pwd)"
DEST="${1:-$RAIZ/alfavia-app}"
command -v php >/dev/null      || { echo "Falta PHP 8.2+"; exit 1; }
command -v composer >/dev/null || { echo "Falta Composer"; exit 1; }
command -v npm >/dev/null      || { echo "Falta Node.js 20+ (npm)"; exit 1; }

composer create-project laravel/laravel:^12.0 "$DEST" --prefer-dist
cd "$DEST"
rm -f database/migrations/0001_01_01_*.php resources/js/app.js resources/js/bootstrap.js resources/views/welcome.blade.php
cp -R "$RAIZ/overlay/." .
cp -R "$RAIZ/ml" "$RAIZ/agent" .
find ml agent -name __pycache__ -type d -prune -exec rm -rf {} + 2>/dev/null || true

npm install react react-dom react-router-dom
npm install -D @vitejs/plugin-react

[ -f .env ] || cp .env.example .env
python3 - <<'PY'
import re
p = '.env'; t = open(p).read()
cambios = {
 'APP_TIMEZONE': 'America/Lima', 'APP_LOCALE': 'es',
 'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '5432',
 'DB_DATABASE': 'alfavia_monitoreo', 'DB_USERNAME': 'postgres', 'DB_PASSWORD': 'postgres',
 'SESSION_DRIVER': 'file', 'SESSION_LIFETIME': '720', 'SESSION_SAME_SITE': 'strict',
 'CACHE_STORE': 'file', 'QUEUE_CONNECTION': 'sync',
 'ML_URL': 'http://127.0.0.1:5001', 'ML_TOKEN': 'token-de-prueba-local-0123456789abcdef',
 'GEMINI_API_KEY': '', 'GEMINI_MODEL': 'gemini-2.5-flash',
}
for k, v in cambios.items():
    if re.search(rf'^#?\s*{k}=.*$', t, re.M): t = re.sub(rf'^#?\s*{k}=.*$', f'{k}={v}', t, flags=re.M)
    else: t += f'\n{k}={v}'
open(p, 'w').write(t + '\n')
PY
php artisan key:generate --force
echo
echo "Edita .env (DB_PASSWORD, GEMINI_API_KEY) y crea la BD:  createdb alfavia_monitoreo"
echo "Luego:  php artisan migrate && php artisan db:seed --class=DemoSeeder"
echo "Proyecto listo en: $DEST   (ver README.md)"
