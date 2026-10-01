# holidu_scraper

Panel web autoalojado para gestionar las reservas de un alojamiento turístico
publicado en **Holidu** (Bookiply). Sincroniza las reservas de Holidu con una base de
datos **PocketBase** y las muestra en un panel desde el que puedes:

- Ver las reservas **activas, pasadas y canceladas** en una tabla.
- Verlas en un **calendario de ocupación** mensual, coloreado por canal
  (Booking, Airbnb, Holidu…), con el porcentaje de ocupación del mes.
- **Copiar el mensaje** con el enlace al formulario de check-in, listo para mandárselo
  al huésped.
- Marcar si el huésped ya **rellenó el formulario**.
- Llevar el control de las **fianzas** (pendiente → pagada → devuelta / retenida).
- Apuntar **notas de huéspedes**, que vuelven a aparecer si el mismo huésped repite
  (se le reconoce por el teléfono).
- **Iniciar sesión en Holidu desde el propio panel**, incluido el código 2FA.

> ⚠️ Proyecto personal, sin relación con Holidu. Usa la API interna de la web de
> Holidu para propietarios, que no está documentada y puede cambiar sin aviso.

## Cómo funciona

```
Holidu ──(sesión con cookies)──> sync_holidu_pocketbase.py ──> PocketBase
                                                                 │
                         Panel PHP (index.php, api/*.php) <──────┘
```

- **`web/sync_holidu_pocketbase.py`**: descarga las reservas (lista + detalle de cada
  una) y hace *upsert* en PocketBase, sin tocar los campos que se rellenan a mano
  (formulario, fianza). Se ejecuta a las 02, 08, 14 y 20 h (hora local) y con el
  botón «🔄 Sincronizar».
- **`web/authentication.py`**: la sesión de Holidu. La web de Holidu obtiene su token
  con unas cookies; el sync las guarda y pide el token con una simple petición HTTP
  (~3 s). **Chrome headless** solo se abre para el login (con 2FA) o cuando esas
  cookies dejan de valer.
- **Panel** (`web/index.php`, `web/assets/`, `web/api/`): PHP sin frameworks, con
  login, sesión «Recordarme», protección CSRF y freno a la fuerza bruta.

## Puesta en marcha

Necesitas Docker con el plugin `docker compose`.

```bash
git clone https://github.com/Cosmar43/holidu_scraper.git
cd holidu_scraper
cp docker-compose.ejemplo.yml docker-compose.yml
cp .env.example .env
```

1. Arranca PocketBase y crea su superusuario:
   ```bash
   docker compose up -d pocketbase
   ```
   Abre `http://<servidor>:8090/_/`, crea el superusuario y pon su correo y su
   contraseña en `POCKETBASE_ADMIN_EMAIL` / `POCKETBASE_ADMIN_PASSWORD` del `.env`.
2. Rellena el resto del `.env` (credenciales de Holidu, nombre del alojamiento…).
   Para la contraseña del panel, mejor un hash (copia la línea que imprime, con sus
   comillas simples):
   ```bash
   docker compose run --rm --no-deps --entrypoint php web hash_password.php 'tu-contraseña'
   ```
3. Arranca el panel:
   ```bash
   docker compose up -d --build
   ```
4. Entra en `http://<servidor>:8080`, pulsa **«🔑 Holidu»** para iniciar sesión en
   Holidu (te pedirá el código que te llega por correo) y después **«🔄 Sincronizar»**.
   Las colecciones de PocketBase se crean solas en el primer sync.

> Si lo vas a usar fuera de tu red local, ponlo detrás de un proxy inverso con
> HTTPS (Nginx Proxy Manager, Caddy, Traefik…) y quita los `ports:` del compose.

## Configuración

Todo va en el `.env`; ver [`.env.example`](.env.example). Lo más habitual:

| Variable | Para qué |
|---|---|
| `NOMBRE_ALOJAMIENTO` | Nombre que se muestra en el panel y en el mensaje. |
| `MENSAJE_FORMULARIO` | Texto del mensaje al huésped; admite `{nombre}`, `{alojamiento}` y `{enlace}`. |
| `TZ` | Zona horaria (las horas del sync automático son locales). |
| `AGENTE_API_KEY` | Opcional: permite lanzar el sync desde otro servicio con la cabecera `X-API-Key`. |

## Actualizar

```bash
./update_web.sh
```

Hace `git pull --ff-only` (se para si tienes cambios locales en vez de pisarlos) y
reconstruye solo el contenedor `web`.

## Pruebas

```bash
docker compose exec web python3 pruebas_sync.py
```

Comprueban las funciones del sync con datos inventados: no tocan Holidu ni PocketBase.

## Seguridad

- El `.env`, los datos de PocketBase y el perfil de Chrome **no** se suben nunca: el
  `.gitignore` de la raíz funciona como lista blanca.
- Las cookies de la sesión de Holidu se guardan con permisos `600` y fuera de la
  carpeta que sirve Apache.
- Apache no sirve ficheros que empiecen por punto, `includes/`, scripts de Python ni
  el perfil de Chrome (`web/.htaccess`), y deniega cualquier `.git` a nivel de servidor.

## Licencia

[MIT](LICENSE)
