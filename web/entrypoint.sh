#!/bin/bash
# Arreglar permisos en cada arranque (los volumenes Docker se crean como root)
chown -R www-data:www-data /var/www/html/chrome_profile /var/www/.cache /var/www/.local /var/www/html/ 2>/dev/null || true

# Volcar las variables de entorno de docker-compose a un archivo para el cron
# (cron NO hereda el entorno del contenedor; sin esto el sync programado corre
# con POCKETBASE_URL=None y nunca escribe en PocketBase). printf %q escapa
# caracteres especiales (#, $, espacios).
{
    for var in POCKETBASE_URL POCKETBASE_ADMIN_EMAIL POCKETBASE_ADMIN_PASSWORD \
               HOLIDU_EMAIL HOLIDU_PASSWORD HOLIDU_COOKIES_FILE TZ; do
        printf 'export %s=%q\n' "$var" "${!var}"
    done
} > /etc/app_env.sh
chown root:www-data /etc/app_env.sh
chmod 640 /etc/app_env.sh

# Iniciar cron en background (sync programado). Hereda TZ, asi que las horas
# del cron son locales.
cron

# Arrancar Apache en foreground (comportamiento por defecto de la imagen PHP)
exec apache2-foreground
