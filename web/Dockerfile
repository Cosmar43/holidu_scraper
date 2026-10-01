FROM php:8.2-apache

# Instalación de dependencias del sistema y Python
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    wget \
    gnupg \
    unzip \
    curl \
    # Dependencias críticas para Chrome headless en Linux
    libnss3 \
    libfontconfig1 \
    libxrender1 \
    libxtst6 \
    libglib2.0-0 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxi6 \
    libxrandr2 \
    libxss1 \
    libasound2 \
    libpangocairo-1.0-0 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libxshmfence1 \
    fonts-liberation \
    xdg-utils \
    dbus \
    cron \
    logrotate \
    && rm -rf /var/lib/apt/lists/*

# Instalación de Google Chrome estable
RUN wget -q -O /etc/apt/trusted.gpg.d/google-chrome.asc https://dl-ssl.google.com/linux/linux_signing_key.pub \
    && echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update \
    && apt-get install -y google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

# Configuración de Apache
RUN a2enmod rewrite && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copiar requirements primero para aprovechar caché de Docker
COPY requirements.txt /tmp/requirements.txt

# Instalacion de dependencias de Python
# Usar python3 -m pip (no pip3) para asegurar que instala en el Python correcto
RUN python3 -m pip install --no-cache-dir --break-system-packages -r /tmp/requirements.txt && \
    python3 -c "from selenium import webdriver; print('selenium OK')"

# Un repositorio git no se sirve NUNCA, aunque alguien clone dentro del
# DocumentRoot o falle el .htaccess (depende de AllowOverride All, que viene de
# la imagen base y no lo fija este proyecto). Esto no depende de nada de eso.
RUN printf '%s\n' \
        '<DirectoryMatch "/\\.git">' \
        '    Require all denied' \
        '</DirectoryMatch>' \
        > /etc/apache2/conf-available/sin-git.conf \
    && a2enconf sin-git

# Copiar archivos del proyecto
COPY . /var/www/html

# Configuracion de permisos
# www-data necesita escribir en chrome_profile, logs, /tmp y su HOME (.cache)
RUN mkdir -p /var/www/.cache /var/www/.local /var/www/html/chrome_profile \
    && chown -R www-data:www-data /var/www

# Cookies de la sesion de Holidu (ver authentication.py): fuera del
# DocumentRoot y en la carpeta de www-data.
ENV HOLIDU_COOKIES_FILE=/var/www/.cache/holidu_cookies.json

# Cron: sincronizar Holidu 4 veces al dia. Las horas son LOCALES (variable TZ
# del contenedor, por defecto Europe/Madrid en el docker-compose).
# El entrypoint genera /etc/app_env.sh con las variables de docker-compose;
# hay que cargarlo porque cron no hereda el entorno del contenedor.
# El log lo rota el logrotate diario de Debian (/etc/cron.daily).
RUN printf '%s\n' \
        "SHELL=/bin/bash" \
        "0 2,8,14,20 * * * www-data . /etc/app_env.sh && cd /var/www/html && python3 -W ignore sync_holidu_pocketbase.py >> /var/log/holidu_sync.log 2>&1" \
        > /etc/cron.d/holidu-sync \
    && chmod 0644 /etc/cron.d/holidu-sync \
    && touch /var/log/holidu_sync.log \
    && chown www-data:www-data /var/log/holidu_sync.log \
    && printf '%s\n' \
        '/var/log/holidu_sync.log {' \
        '    weekly' \
        '    rotate 4' \
        '    compress' \
        '    missingok' \
        '    notifempty' \
        '    copytruncate' \
        '}' \
        > /etc/logrotate.d/holidu-sync

# Para que Chrome pueda escribir en /tmp
RUN chmod 1777 /tmp

# Entrypoint que arregla permisos de volumenes antes de arrancar Apache
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
