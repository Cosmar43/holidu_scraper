"""Sesión de Holidu: login con Chrome y obtención del token de su API.

El panel de Holidu (host.holidu.com) pide su token Bearer a
internal-auth.holidu.com/clients/hx-pms-web-client/token, y ese endpoint se
autentica con cookies (la de refresco es `__Secure-hx-pms-web-client._R-V2`).
Así que, una vez hecho el login, no hace falta un navegador para conseguir
tokens nuevos: basta con guardar esas cookies y pedírselo con `requests`.

    get_token()
      1. Con las cookies guardadas: una petición HTTP, ~1 s, sin Chrome.
      2. Si no hay cookies o ya no valen: abre Chrome con el perfil guardado
         (que conserva la sesión de Keycloak, «Recordarme»), deja que la web
         renueve las cookies, las guarda y vuelve al paso 1.
      3. Si tampoco hay sesión en Chrome, hay que volver a hacer login (con
         2FA): `python3 authentication.py` o el botón «🔑 Holidu» del panel.

Las cookies valen tanto como la sesión de Holidu: se guardan con permisos 600
y FUERA del DocumentRoot.
"""
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException
from dotenv import load_dotenv
import json
import time
import os
import shutil
import sys

import requests

# Cargar variables de entorno
load_dotenv()

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROFILE_PATH = os.path.join(BASE_DIR, "chrome_profile")

HOLIDU_HOST = "https://host.holidu.com/"
HOLIDU_BOOKINGS_URL = "https://host.holidu.com/app/bookings"

# De donde saca el token la propia web de Holidu.
TOKEN_URL = "https://internal-auth.holidu.com/clients/hx-pms-web-client/token"
COOKIES_DOMINIO = "internal-auth.holidu.com"
COOKIE_REFRESCO = "__Secure-hx-pms-web-client._R-V2"

# Fuera del DocumentRoot (/var/www/html) a proposito: es una sesion de Holidu.
COOKIES_FILE = os.getenv(
    "HOLIDU_COOKIES_FILE",
    os.path.join(os.path.expanduser("~"), ".cache", "holidu_cookies.json"),
)

USER_AGENT = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

HOLIDU_EMAIL = os.getenv("HOLIDU_EMAIL")
HOLIDU_PASSWORD = os.getenv("HOLIDU_PASSWORD")

# --- Global Driver ---
driver = None

def get_driver(headless: bool = True) -> webdriver.Chrome:
    """Configura y devuelve un Chrome con el perfil persistente."""
    chrome_options = Options()
    # En Linux/Docker siempre headless (no hay display)
    if headless or sys.platform != 'win32':
        chrome_options.add_argument("--headless=new")

    chrome_options.add_argument("--start-maximized")
    chrome_options.add_argument("--disable-infobars")
    chrome_options.add_argument("--disable-extensions")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument(f'--user-agent={USER_AGENT}')
    chrome_options.add_argument("--log-level=3")
    chrome_options.add_argument("--silent")
    chrome_options.add_experimental_option('excludeSwitches', ['enable-logging'])
    chrome_options.add_argument("--disable-gpu")
    chrome_options.add_argument("--window-size=1920,1080")
    # Que Chrome no se descargue modelos, componentes ni sugerencias al
    # perfil: aqui solo se usa para iniciar sesion, y llego a ocupar ~190 MB.
    chrome_options.add_argument("--no-first-run")
    chrome_options.add_argument("--disable-component-update")
    chrome_options.add_argument("--disable-background-networking")
    chrome_options.add_argument(
        "--disable-features=OptimizationGuideModelDownloading,"
        "OptimizationHintsFetching,OptimizationTargetPrediction,"
        "OptimizationHints,MediaRouter,Translate")

    chrome_options.add_argument("--disk-cache-size=52428800")

    chrome_options.add_argument(f"--user-data-dir={PROFILE_PATH}")
    chrome_options.add_argument(f"--profile-directory=Default")
    os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'

    # La cache de JavaScript compilado crece sin limite (llego a 290 MB) y no
    # guarda nada de la sesion: se tira antes de cada arranque.
    shutil.rmtree(os.path.join(PROFILE_PATH, "Default", "Code Cache"), ignore_errors=True)

    # FIX: Manejar archivo Preferences corrupto que causa "cannot parse internal JSON template"
    prefs_file = os.path.join(PROFILE_PATH, "Default", "Preferences")
    if os.path.exists(prefs_file):
        try:
            with open(prefs_file, 'r', encoding='utf-8') as f:
                json.load(f)
        except (ValueError, json.JSONDecodeError):
            print("⚠️ Archivo Preferences de Chrome corrupto detectado. Eliminándolo para restaurar...")
            try:
                os.remove(prefs_file)
            except Exception as e:
                print(f"❌ Error al intentar eliminar Preferences corrupto: {e}")

    try:
        return webdriver.Chrome(options=chrome_options)
    except Exception as e:
        print(f"❌ Error al crear driver: {e}")
        raise e

def login_holidu(driver, code_provider=None):
    """Maneja el login en Holidu incluyendo 2FA.

    code_provider: callable opcional que devuelve el código 2FA (string). Si es None,
    se pide por consola con input(). La web pasa un proveedor que espera el código
    desde un archivo (login no interactivo)."""
    print("ℹ️ Comprobando sesión de Holidu...")

    # Verificar si ya estamos logueados
    if "/app" in driver.current_url:
        print("✅ Ya logueado en Holidu.")
        return True

    # Intentar acceder y verificar si pide login
    try:
        # Esperar a ver si aparece el botón de login con email o si estamos dentro
        try:
            WebDriverWait(driver, 5).until(EC.element_to_be_clickable((By.CSS_SELECTOR, 'a[data-provider="email"]')))
            print("ℹ️ Iniciando proceso de login...")
        except TimeoutException:
            if "/app" in driver.current_url:
                print("✅ Ya logueado en Holidu (detectado por URL).")
                return True
            else:
                 print(f"⚠️ No se detecta sesión ni pantalla de login conocida. URL actual: {driver.current_url}")

        if not HOLIDU_EMAIL or not HOLIDU_PASSWORD:
            print("❌ Faltan HOLIDU_EMAIL / HOLIDU_PASSWORD en el entorno.")
            return False

        # 1. Click en "Continúa con Correo electrónico"
        driver.find_element(By.CSS_SELECTOR, 'a[data-provider="email"]').click()

        # 2. Rellenar credenciales
        print("✍️ Introduciendo credenciales...")
        WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.NAME, "username")))

        username_field = driver.find_element(By.NAME, "username")
        username_field.clear()
        username_field.send_keys(HOLIDU_EMAIL)

        password_field = driver.find_element(By.NAME, "password")
        password_field.clear()
        password_field.send_keys(HOLIDU_PASSWORD)

        # 3. Mantener sesión iniciada
        try:
            driver.find_element(By.NAME, "rememberMe").click()
        except Exception:
            pass

        # 4. Click en Login
        driver.find_element(By.NAME, "login").click()

        # 5. Manejar posible 2FA
        try:
            # Esperar a ver si pide código 2FA o entra directo
            WebDriverWait(driver, 10).until(
                lambda d: d.find_elements(By.NAME, "sms_2fa_code") or "/app/" in d.current_url
            )

            if "/app/" in driver.current_url:
                print("✅ Login exitoso (sin 2FA o recordado).")
                return True

            # Si estamos aquí, es que encontró el input de 2FA
            print("\n" + "!"*50)
            print("🔐 SE REQUIERE CÓDIGO DE AUTENTICACIÓN DE 2 FACTORES")
            print("El código ha sido enviado a tu email/SMS asociado a Holidu.")
            if code_provider is not None:
                code = code_provider()  # web: espera el código desde archivo
            else:
                code = input(">> INTRODUCE EL CÓDIGO AQUÍ: ").strip()
            print("!"*50 + "\n")

            driver.find_element(By.NAME, "sms_2fa_code").send_keys(code)
            driver.find_element(By.ID, "btn-verify-sms-code").click()

            # Esperar a que redirija
            print("⏳ Esperando redirección tras 2FA...")
            try:
                WebDriverWait(driver, 60).until(lambda d: "/app" in d.current_url)
                print("✅ Login con 2FA completado.")
                return True
            except TimeoutException:
                print(f"❌ Timeout esperando redirección. URL actual: {driver.current_url}")
                return False

        except TimeoutException:
             print("❌ Timeout esperando respuesta inicial del login (input de 2FA).")
             return False

    except Exception as e:
        print(f"❌ Error durante el login de Holidu: {e}")
        return False


# --- Cookies de sesion -----------------------------------------------------

def leer_cookies() -> dict:
    try:
        with open(COOKIES_FILE, encoding="utf-8") as f:
            datos = json.load(f)
        return datos if isinstance(datos, dict) else {}
    except (OSError, ValueError):
        return {}


def guardar_cookies(cookies: dict) -> None:
    """Escribe las cookies de forma atomica y legibles solo por el dueño."""
    os.makedirs(os.path.dirname(COOKIES_FILE), mode=0o700, exist_ok=True)
    tmp = COOKIES_FILE + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(cookies, f)
    os.replace(tmp, COOKIES_FILE)


def borrar_cookies() -> None:
    try:
        os.remove(COOKIES_FILE)
    except OSError:
        pass


def guardar_cookies_del_navegador(drv) -> bool:
    """Copia de Chrome las cookies de internal-auth. True si estaba la de refresco."""
    todas = drv.execute_cdp_cmd("Network.getAllCookies", {}).get("cookies", [])
    cookies = {c["name"]: c["value"] for c in todas
               if c.get("domain", "").lstrip(".") == COOKIES_DOMINIO}
    if COOKIE_REFRESCO not in cookies:
        return False
    guardar_cookies(cookies)
    return True


def token_con_cookies():
    """Pide un token con las cookies guardadas. None si no hay o no valen."""
    cookies = leer_cookies()
    if not cookies:
        return None
    sesion = requests.Session()
    for nombre, valor in cookies.items():
        sesion.cookies.set(nombre, valor, domain=COOKIES_DOMINIO)
    try:
        resp = sesion.get(TOKEN_URL, timeout=20, headers={
            "origin": HOLIDU_HOST.rstrip("/"),
            "referer": HOLIDU_HOST,
            "user-agent": USER_AGENT,
        })
        token = resp.json().get("token") if resp.status_code == 200 else None
    except (requests.RequestException, ValueError):
        return None
    if not token:
        return None
    # Holidu va rotando las cookies en cada respuesta: hay que quedarse con
    # las nuevas o la de refresco acabaria caducando aunque se use a diario.
    nuevas = {c.name: c.value for c in sesion.cookies if c.domain.lstrip(".") == COOKIES_DOMINIO}
    if nuevas != cookies:
        guardar_cookies(nuevas)
    return token


def get_token():
    """Devuelve un token Bearer de Holidu, o None si no hay sesión."""
    global driver

    token = token_con_cookies()
    if token:
        print("✅ Token obtenido con la sesión guardada (sin Chrome).")
        return token

    print("ℹ️ Sin sesión guardada válida; abriendo Chrome para renovarla...")
    try:
        if driver is None:
            driver = get_driver(headless=True)
        driver.get(HOLIDU_HOST)
        if not login_holidu(driver):
            raise Exception("No se pudo iniciar sesión en Holidu.")

        # La SPA pide su token al cargar: cuando esten las cookies, listo.
        driver.get(HOLIDU_BOOKINGS_URL)
        inicio = time.time()
        recargada = False
        while time.time() - inicio < 60:
            if guardar_cookies_del_navegador(driver):
                token = token_con_cookies()
                if token:
                    print("✅ Token obtenido (sesión renovada con Chrome).")
                    return token
            # Si a mitad de espera no hay nada, recargar por si la SPA se colgo
            if not recargada and time.time() - inicio > 25:
                print("🔄 Sin sesión aún; recargando la página de reservas...")
                recargada = True
                driver.refresh()
            time.sleep(1)

        raise Exception("Holidu no entregó la sesión tras 60 s.")

    except Exception as e:
        print(f"Error en auth_holidu: {e}")
        borrar_cookies()
        return None

def quit_driver():
    global driver
    if driver:
        driver.quit()
        driver = None

if __name__ == "__main__":
    print("\n" + "="*50)
    print(" 🤖 LOGIN DE HOLIDU")
    print("="*50)

    try:
        driver = get_driver(headless=True) # Siempre headless en Docker
        print("\nIniciando Chrome y abriendo Holidu...")
        driver.get(HOLIDU_HOST)
        if login_holidu(driver):
            print("\n✅ SESION DE HOLIDU GUARDADA CORRECTAMENTE.")
            # Deja tambien las cookies listas, para que el proximo sync no
            # tenga que abrir Chrome.
            driver.get(HOLIDU_BOOKINGS_URL)
            time.sleep(8)
            guardar_cookies_del_navegador(driver)
        else:
            print("\n❌ Fallo al iniciar sesion en Holidu.")

    except Exception:
        import traceback
        print(f"\n❌ Error fatal:\n{traceback.format_exc()}")
    finally:
        quit_driver()
