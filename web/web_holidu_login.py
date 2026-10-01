"""
web_holidu_login.py — Login de Holidu lanzado desde la web (no interactivo).

Se ejecuta en segundo plano (lo lanza api/holidu_login_start.php como www-data).
Mantiene Chrome abierto y se comunica con la web mediante archivos de estado:

  /tmp/holidu_login/status.json  -> estado actual {state, message, ts}
  /tmp/holidu_login/code.txt     -> código 2FA que escribe la web

Estados (state):
  running       -> conectando / verificando
  waiting_2fa   -> esperando que el usuario introduzca el código
  success       -> sesión iniciada correctamente
  error         -> fallo (message contiene el detalle)
"""
import os
import sys
import json
import time

import authentication

STATE_DIR = "/tmp/holidu_login"
STATUS_FILE = os.path.join(STATE_DIR, "status.json")
CODE_FILE = os.path.join(STATE_DIR, "code.txt")

CODE_TIMEOUT = 300  # segundos que esperamos el código 2FA


def set_status(state, message=""):
    os.makedirs(STATE_DIR, exist_ok=True)
    tmp = STATUS_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump({"state": state, "message": message, "ts": int(time.time())}, f)
    os.replace(tmp, STATUS_FILE)


def wait_for_code():
    """Proveedor de código 2FA para la web: marca 'waiting_2fa' y espera el archivo."""
    set_status("waiting_2fa", "Introduce el codigo de verificacion enviado a tu correo.")
    start = time.time()
    while time.time() - start < CODE_TIMEOUT:
        if os.path.exists(CODE_FILE):
            code = ""
            try:
                with open(CODE_FILE, "r", encoding="utf-8") as f:
                    code = f.read().strip()
            finally:
                try:
                    os.remove(CODE_FILE)
                except OSError:
                    pass
            if code:
                set_status("running", "Verificando codigo...")
                return code
        time.sleep(1)
    raise TimeoutError("Tiempo de espera agotado para el codigo 2FA.")


def main():
    os.makedirs(STATE_DIR, exist_ok=True)
    # Limpiar cualquier código previo
    try:
        os.remove(CODE_FILE)
    except OSError:
        pass

    set_status("running", "Conectando con Holidu...")

    driver = None
    try:
        driver = authentication.get_driver(headless=True)
        driver.get(authentication.HOLIDU_HOST)
        ok = authentication.login_holidu(driver, code_provider=wait_for_code)
        if ok:
            # Dejar tambien guardadas las cookies de la sesion, para que el
            # siguiente sync pida el token sin abrir Chrome.
            set_status("running", "Guardando la sesion...")
            driver.get(authentication.HOLIDU_BOOKINGS_URL)
            for _ in range(20):
                if authentication.guardar_cookies_del_navegador(driver):
                    break
                time.sleep(1)
            set_status("success", "Sesion de Holidu iniciada correctamente.")
        else:
            set_status("error", "No se pudo iniciar sesion en Holidu. Revisa las credenciales o reintenta.")
    except Exception as e:
        set_status("error", str(e))
    finally:
        try:
            if driver:
                driver.quit()
        except Exception:
            pass


if __name__ == "__main__":
    main()
