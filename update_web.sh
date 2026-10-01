#!/bin/bash
# Despliega la última versión de la web desde GitHub.
#
# - `git pull --ff-only`: si hay cambios locales sin subir o las ramas se han
#   separado, se para y avisa en vez de pisar nada (un `git reset --hard`
#   borraría en silencio lo que no estuviera subido).
# - El .git vive en la raíz del proyecto, fuera de web/ (el DocumentRoot), así
#   que Apache no puede servirlo aunque falle el .htaccess.
# - Los permisos de www-data los arregla entrypoint.sh al arrancar el contenedor.
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

echo "⬇️  Bajando cambios de GitHub..."
git pull --ff-only

echo "🐳 Reconstruyendo el contenedor web..."
# --no-deps: no recrear también la base de datos ni otros servicios.
docker compose up -d --build --no-deps web

echo "✅ Hecho. Logs: docker compose logs -f web"
