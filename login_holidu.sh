#!/bin/bash
# Login interactivo de Holidu (con 2FA) desde la consola.
#
# Alternativa al botón «🔑 Holidu» del panel. Se ejecuta SIEMPRE como www-data
# dentro del contenedor: si se hiciera como root, Chrome dejaría en
# chrome_profile/ ficheros que la web (www-data) no puede leer y el sync
# fallaría con PermissionError.
#
# Uso:   ./login_holidu.sh
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

if [ -z "$(docker compose ps -q web 2>/dev/null)" ]; then
    echo "❌ El servicio 'web' no está en marcha. Arráncalo con: docker compose up -d"
    exit 1
fi

# Devolver a www-data lo que se haya quedado como root (idempotente).
echo "🔧 Reparando permisos del perfil de Chrome..."
docker compose exec -u 0 web chown -R www-data:www-data \
    /var/www/html/chrome_profile /var/www/.cache /var/www/.local 2>/dev/null || true

echo "🔐 Iniciando login de Holidu como www-data..."
echo "   (si pide código 2FA, míralo en tu correo e introdúcelo aquí)"
echo
exec docker compose exec -u www-data web python3 /var/www/html/authentication.py
