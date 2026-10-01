import warnings
import authentication
import json
import requests
import os
import sys
from dotenv import load_dotenv
import time
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor

# Fix Windows console encoding for emojis
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

load_dotenv()
warnings.simplefilter("ignore", category=UserWarning)

POCKETBASE_URL = os.getenv("POCKETBASE_URL")
POCKETBASE_ADMIN_EMAIL = os.getenv("POCKETBASE_ADMIN_EMAIL")
POCKETBASE_ADMIN_PASSWORD = os.getenv("POCKETBASE_ADMIN_PASSWORD")

HOLIDU_API_URL = "https://api.host.holidu.com/rest/bookiply/web/v2/bookings"
HOLIDU_ORIGIN = "https://host.holidu.com"

# Campos que escribe este script desde los datos de Holidu.
CAMPOS_HOLIDU = [
    "id_reserva", "nombre_huesped", "correo_electronico", "numero_de_telefono",
    "check_in", "check_out", "pagado", "ganancia", "estado", "link_formulario",
    "fianza", "fianza_gestiona", "canal", "fecha_reserva",
]

# Campos que se rellenan a mano desde el panel (o desde el agente). El sync
# nunca los toca: no aparecen en el diccionario que se manda en el PATCH.
CAMPOS_MANUALES = [
    "formulario", "fianza_estado", "fianza_retenido", "fianza_nota",
]

# Coleccion 'notas': detalles sobre un huesped que interesan si vuelve a
# reservar. No viven en 'reservas' porque van pegadas al HUESPED, no a la
# estancia: la clave es su telefono normalizado, asi que la nota que se escribe
# hoy aparece sola en la reserva que haga el año que viene. Ver notas.php en el
# panel y agente/app/notas.py.
CAMPOS_NOTAS = [
    "huesped_clave", "huesped_nombre", "id_reserva", "texto", "origen",
]

# --- Helper Functions ---

def extraer_fianza(detalle):
    """Saca (importe, quien_la_gestiona) del detalle de una reserva de Holidu.

    La fianza real de cada reserva esta en additionalFees, en la entrada con
    feeCategory 'SECURITY_DEPOSIT'. OJO: el campo suelto securityDepositAmount
    NO sirve, porque trae siempre el valor configurado en el apartamento (500)
    aunque la reserva lleve otra cantidad; en las reservas actuales la mayoria
    son de 250. Las reservas que no traen ese fee (las de Airbnb) no llevan
    fianza nuestra.
    """
    if not isinstance(detalle, dict):
        return "", ""

    for tarifa in (detalle.get('additionalFees') or []):
        if tarifa.get('feeCategory') != 'SECURITY_DEPOSIT':
            continue
        importe = formatear_importe(tarifa.get('baseAmount'))
        gestiona = "nosotros" if tarifa.get('feeHandledBy') == 'HOME_OWNER' else "canal"
        return importe, gestiona

    return "", ""


def formatear_importe(valor):
    """Convierte un importe de Holidu a texto: 500.0 -> '500', 12.5 -> '12.5'."""
    if valor is None:
        return ""
    try:
        numero = float(valor)
    except (TypeError, ValueError):
        return str(valor)
    if numero.is_integer():
        return str(int(numero))
    return str(numero)


def has_booking_changed(booking, existing_row):
    for key, new_value in booking.items():
        existing_val = existing_row.get(key)
        val1 = new_value if new_value is not None else ""
        val2 = existing_val if existing_val is not None else ""
        is_equal = False
        
        try:
            if float(val1) == float(val2):
                is_equal = True
        except (ValueError, TypeError):
            if str(val1) == str(val2):
                 is_equal = True
        
        if not is_equal:
             return True
             
    return False

# --- Main Logic ---

DETALLES_A_LA_VEZ = 6


def descargar_detalles(ids, headers):
    """Baja el detalle de varias reservas a la vez.

    Devuelve {id: detalle}. Las que fallen NO aparecen en el diccionario, y eso
    es justo lo que hace que el que llama no pise con vacio la fianza, el canal
    ni el enlace del formulario que ya estaban guardados.
    """
    def una(booking_id):
        try:
            resp = requests.get(f"{HOLIDU_API_URL}/{booking_id}",
                                headers=headers, timeout=30)
            if resp.status_code != 200:
                print(f"⚠️  Detalle de {booking_id}: HTTP {resp.status_code}")
                return booking_id, None
            datos = resp.json()
            return booking_id, (datos if isinstance(datos, dict) else None)
        except Exception as e:
            print(f"❌ Error obteniendo detalles de {booking_id}: {e}")
            return booking_id, None

    salida = {}
    with ThreadPoolExecutor(max_workers=DETALLES_A_LA_VEZ) as pool:
        for booking_id, datos in pool.map(una, ids):
            if datos is not None:
                salida[booking_id] = datos

    fallidos = len(ids) - len(salida)
    if fallidos:
        print(f"⚠️  {fallidos} detalle(s) sin descargar: esas reservas conservan "
              f"la fianza, el canal y el enlace que ya tenian")
    return salida


def get_holidu_bookings():
    """Fetches bookings from Holidu API (both active and cancelled)."""
    token = authentication.get_token()
    if not token:
        print("❌ No hay sesión de Holidu: hay que volver a iniciarla "
              "(botón «🔑 Holidu» del panel o login_holidu.sh).")
        return []

    headers = {
        'accept': '*/*',
        'accept-language': 'es-ES,es;q=0.7',
        'authorization': f'Bearer {token}',
        'client': 'pms-web',
        'origin': HOLIDU_ORIGIN,
        'referer': f'{HOLIDU_ORIGIN}/',
        'user-agent': 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36'
    }

    params = {
        'orderByField': 'timestampOfArrival',
        'orderBy': 'ASC',
        'pageSize': '999',
        'pageNum': '0',
        'includeCheckin': 'true'
    }

    try:
        print(f"Consultando Holidu...")
        response = requests.get(HOLIDU_API_URL, headers=headers, params=params, timeout=60)
        if response.status_code in (401, 403):
            # El token de la sesion guardada no vale: se descarta y se pide
            # otro pasando por Chrome, una sola vez.
            print(f"⚠️  Holidu rechaza el token (HTTP {response.status_code}); renovando sesión...")
            authentication.borrar_cookies()
            token = authentication.get_token()
            if not token:
                return []
            headers['authorization'] = f'Bearer {token}'
            response = requests.get(HOLIDU_API_URL, headers=headers, params=params, timeout=60)
        response.raise_for_status()
        data = response.json()
        
        bookings_list = []
        if "bookings" in data:
            bookings_list = data["bookings"]
        elif "result" in data:
            bookings_list = data["result"]
        elif isinstance(data, list):
            bookings_list = data
        
        print(f"  -> Encontradas {len(bookings_list)} reservas.")
        
        # Deduplicate
        unique_bookings = {b['id']: b for b in bookings_list}.values()
        print(f"Total reservas únicas recuperadas: {len(unique_bookings)}")

        # Los detalles, en paralelo. Uno por reserva y en serie eran los ~25 s
        # que tardaba el sync entero: 56 idas y vueltas a Holidu esperando cada
        # una a la anterior. Con 6 a la vez baja a unos pocos segundos y sigue
        # siendo suave con su API. El limite importa: api/sync.php corta a los
        # 300 s, y a 500 reservas el modelo en serie se acercaba peligrosamente.
        detalles = descargar_detalles([b.get('id') for b in unique_bookings], headers)

        mapped_bookings = []
        for i, b in enumerate(unique_bookings):
            booking_id = b.get('id')
            
            full_phone = None
            full_email = None
            checkin_url = None
            # Datos de fianza, canal y fecha de reserva: solo existen en el
            # detalle, no en el listado.
            fianza = ""
            fianza_gestiona = ""
            canal = ""
            fecha_reserva = ""
            # Si la peticion del detalle falla (timeout, un 500 de Holidu, una
            # excepcion), estas cuatro se quedan vacias. Sin esta bandera se
            # mandaban igual en el PATCH y PISABAN CON VACIO el importe de la
            # fianza, quien la gestiona, el canal y el enlace del formulario que
            # ya estaban guardados. Y no vale un `if fianza:`, porque una fianza
            # vacia es legitima: las reservas de Airbnb no llevan.
            d_data = detalles.get(booking_id)
            detalle_ok = isinstance(d_data, dict) and bool(d_data)
            if detalle_ok:
                customer_data = d_data.get('customer') or {}
                full_phone = customer_data.get('phoneNumber')
                full_email = customer_data.get('email')

                checkin_info = d_data.get('checkinInformation') or {}
                checkin_url = checkin_info.get('checkinUrl')

                fianza, fianza_gestiona = extraer_fianza(d_data)
                canal = d_data.get('distributionPartner') or ""
                # Cuando se hizo la reserva. Holidu la da en ISO con su huso
                # ("2025-11-30T22:02:28+01:00") y se guarda tal cual: es lo que
                # pinta el panel y lo que ordena al agente para saber cuales son
                # las mas recientes.
                fecha_reserva = d_data.get('dateOfBooking') or ""

            customer = b.get('customer', {})
            first_name = customer.get('firstname', '')
            surname = customer.get('surname', '')
            guest_name = f"{first_name} {surname}".strip()
            
            phone_to_use = full_phone if full_phone else customer.get('phone')
            if phone_to_use:
                phone_to_use = phone_to_use.replace(" ", "").strip()
                
            email_to_use = full_email if full_email else customer.get('email')

            # We use pocketbase column names (lowercase with underscores)
            reserva = {
                "id_reserva": str(booking_id),
                "nombre_huesped": guest_name,
                "correo_electronico": email_to_use or "",
                "numero_de_telefono": phone_to_use or "",
                "check_in": b.get('travelDateFrom', ""),
                "check_out": b.get('travelDateTo', ""),
                "pagado": str(b.get('totalHandledByBookiply', "")),
                "ganancia": str(b.get('netPayoutToHo', "")),
                "estado": b.get('bookingStatus', 'UNKNOWN'),
            }
            # Todo lo que solo sale del detalle se manda SOLO si el detalle se
            # descargo de verdad. El PATCH lleva unicamente las claves de este
            # diccionario, asi que si no estan, lo que ya hubiera guardado
            # sobrevive: el mismo mecanismo por el que sobreviven 'formulario' y
            # los tres campos manuales de la fianza.
            if detalle_ok:
                reserva["link_formulario"] = checkin_url or ""
                reserva["fianza"] = fianza
                reserva["fianza_gestiona"] = fianza_gestiona
                reserva["canal"] = canal
            else:
                print(f"  ⚠️  Sin detalle de {booking_id}: no se tocan fianza, canal ni enlace")
            # La fecha de reserva no cambia nunca, asi que ademas se protege por
            # si viene vacia con el detalle correcto.
            if fecha_reserva:
                reserva["fecha_reserva"] = fecha_reserva
            mapped_bookings.append(reserva)
            # Ojo: fianza_estado, fianza_retenido y fianza_nota NO se mapean aquí
            # a propósito. Son campos manuales del panel y el PATCH del sync solo
            # manda las claves de este diccionario, así que sobreviven al sync
            # igual que 'formulario'.

        return mapped_bookings

    except Exception as e:
        print(f"Error getting Holidu bookings: {e}")
        return []

def get_pb_token():
    try:
        resp = requests.post(f"{POCKETBASE_URL}/api/collections/_superusers/auth-with-password", json={
            "identity": POCKETBASE_ADMIN_EMAIL,
            "password": POCKETBASE_ADMIN_PASSWORD
        })
        resp.raise_for_status()
        return resp.json().get("token")
    except Exception as e:
        print(f"Error autenticando con PocketBase: {e}")
        return None

def ensure_collection_exists(token):
    headers = {"Authorization": token}
    try:
        resp = requests.get(f"{POCKETBASE_URL}/api/collections/reservas", headers=headers)
        if resp.status_code == 200:
            return True # Exists
        
        if resp.status_code == 404:
            print("Creando coleccion 'reservas' en PocketBase...")
            fields = [
                {"name": nombre, "type": "text"}
                for nombre in CAMPOS_HOLIDU + CAMPOS_MANUALES
            ]
            # Las cinco reglas a None = solo superusuario, igual que 'notas'.
            # Nacio con "" (abierto a cualquiera sin autenticarse) y asi estuvo
            # hasta el 2026-08-22, sirviendo nombres, telefonos y correos de los
            # huespedes a toda la LAN por el puerto 8091. El panel, el agente y
            # este mismo script entran como superusuario, asi que no necesitan
            # el acceso anonimo para nada.
            payload = {
                "name": "reservas",
                "type": "base",
                "fields": fields,
                "listRule": None,
                "viewRule": None,
                "createRule": None,
                "updateRule": None,
                "deleteRule": None,
            }
            create_resp = requests.post(f"{POCKETBASE_URL}/api/collections", headers=headers, json=payload)
            create_resp.raise_for_status()
            print("Coleccion creada exitosamente.")
            return True
            
    except Exception as e:
        print(f"Error asegurando coleccion: {e}")
        return False

def asegurar_campos(token):
    """Añade a la coleccion los campos que falten, sin tocar los que ya estan.

    ensure_collection_exists() solo crea la coleccion la primera vez; si ya
    existe no anade nada. Esta funcion es la que permite meter campos nuevos
    (fianza, canal...) en una base que ya estaba en marcha. Es idempotente:
    si no falta ninguno, no manda nada.
    """
    headers = {"Authorization": token, "Content-Type": "application/json"}
    url = f"{POCKETBASE_URL}/api/collections/reservas"

    try:
        resp = requests.get(url, headers=headers)
        resp.raise_for_status()
        coleccion = resp.json()

        campos_actuales = coleccion.get("fields", [])
        nombres_actuales = {campo.get("name") for campo in campos_actuales}

        faltan = [
            nombre for nombre in CAMPOS_HOLIDU + CAMPOS_MANUALES
            if nombre not in nombres_actuales
        ]
        if not faltan:
            return True

        print(f"Anadiendo campos a 'reservas': {', '.join(faltan)}")
        # Se reenvia la lista completa (incluidos los campos de sistema como
        # 'id'), porque PocketBase reemplaza el esquema entero en el PATCH.
        nuevos = campos_actuales + [{"name": nombre, "type": "text"} for nombre in faltan]
        patch_resp = requests.patch(url, headers=headers, json={"fields": nuevos})
        patch_resp.raise_for_status()
        print("Campos anadidos correctamente.")
        return True

    except Exception as e:
        print(f"Error anadiendo campos a la coleccion: {e}")
        return False

def asegurar_coleccion_notas(token):
    """Crea la coleccion 'notas' si no existe. Idempotente.

    A diferencia de 'reservas', nace con las cinco reglas de acceso en None, o
    sea SOLO superusuario. 'reservas' las tiene en "" (abiertas) de cuando se
    creo, y eso significa que cualquiera en la LAN la lee sin autenticarse; aqui
    no se repite el error. El panel y el agente entran como superusuario, asi
    que no les estorba.

    'creado' y 'actualizado' son campos autodate: los rellena PocketBase solo.
    """
    headers = {"Authorization": token}
    try:
        resp = requests.get(f"{POCKETBASE_URL}/api/collections/notas", headers=headers)
        if resp.status_code == 200:
            return True # Ya existe

        if resp.status_code != 404:
            print(f"Error consultando la coleccion 'notas': HTTP {resp.status_code}")
            return False

        print("Creando coleccion 'notas' en PocketBase...")
        fields = [{"name": nombre, "type": "text"} for nombre in CAMPOS_NOTAS]
        fields.append({"name": "creado", "type": "autodate", "onCreate": True, "onUpdate": False})
        fields.append({"name": "actualizado", "type": "autodate", "onCreate": True, "onUpdate": True})
        payload = {
            "name": "notas",
            "type": "base",
            "fields": fields,
            "listRule": None,
            "viewRule": None,
            "createRule": None,
            "updateRule": None,
            "deleteRule": None,
        }
        create_resp = requests.post(f"{POCKETBASE_URL}/api/collections", headers=headers, json=payload)
        create_resp.raise_for_status()
        print("Coleccion 'notas' creada exitosamente.")
        return True

    except Exception as e:
        print(f"Error asegurando la coleccion 'notas': {e}")
        return False

def get_existing_pocketbase_rows(token):
    headers = {"Authorization": token}
    lookup = {} # {id_reserva (string): Row Dictionary}
    
    page = 1
    per_page = 500
    
    try:
        while True:
            url = f"{POCKETBASE_URL}/api/collections/reservas/records?page={page}&perPage={per_page}"
            resp = requests.get(url, headers=headers)
            resp.raise_for_status()
            data = resp.json()
            
            items = data.get('items', [])
            for row in items:
                holidu_id = row.get("id_reserva")
                if holidu_id:
                    lookup[str(holidu_id)] = row
                    
            if page >= data.get('totalPages', 1):
                break
            page += 1
            
        print(f"PocketBase: Se encontraron {len(lookup)} reservas existentes.")
        return lookup
    except Exception as e:
        print(f"Error fetching PocketBase rows: {e}")
        return {}

def sync_to_pocketbase(bookings):
    """Syncs bookings using upsert logic with change detection."""
    token = get_pb_token()
    if not token:
        return
        
    if not ensure_collection_exists(token):
        print("No se pudo asegurar la coleccion de PocketBase, cancelando sync.")
        return

    if not asegurar_campos(token):
        print("No se pudieron asegurar los campos de la coleccion, cancelando sync.")
        return

    # Las notas no dependen del sync para nada, pero este es el unico sitio del
    # proyecto que sabe crear colecciones, asi que aqui se asegura la suya. Si
    # falla no se cancela el sync: las reservas son lo importante.
    asegurar_coleccion_notas(token)

    existing_lookup = get_existing_pocketbase_rows(token)
    
    headers = {
        "Authorization": token,
        "Content-Type": "application/json"
    }
    
    msg_created = 0
    msg_updated = 0
    msg_skipped = 0
    
    for booking in bookings:
        holidu_id = booking.get("id_reserva")
        
        if not holidu_id:
            continue
            
        row_data = existing_lookup.get(str(holidu_id))
        
        try:
            if row_data:
                if not has_booking_changed(booking, row_data):
                    msg_skipped += 1
                    continue
                
                row_id = row_data["id"]
                url = f"{POCKETBASE_URL}/api/collections/reservas/records/{row_id}"
                resp = requests.patch(url, headers=headers, json=booking)
                resp.raise_for_status()
                msg_updated += 1
                print(f"✅ Actualizada: {holidu_id}")
            
            else:
                url = f"{POCKETBASE_URL}/api/collections/reservas/records"
                resp = requests.post(url, headers=headers, json=booking)
                resp.raise_for_status()
                msg_created += 1
                print(f"✅ Creada: {holidu_id}")
                
        except Exception as e:
            print(f"Error procesando reserva {holidu_id}: {e}")

    print(f"\n✅ Creadas: {msg_created} | Actualizadas: {msg_updated} | Saltadas: {msg_skipped}")
    apuntar_sync_correcto(msg_created, msg_updated, msg_skipped)


# Marca de la ultima pasada que fue bien. La lee api/sync_estado.php, que es
# como el asistente se entera de que hace dias que no se sincroniza (lo normal
# cuando caduca la sesion de Holidu). Va en includes/ porque el .htaccess no
# sirve esa carpeta.
MARCA_SYNC = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          "includes", ".ultimo_sync.json")


def apuntar_sync_correcto(creadas, actualizadas, saltadas):
    """Deja constancia de que esta pasada termino bien."""
    try:
        with open(MARCA_SYNC, "w") as f:
            json.dump({
                "cuando": datetime.now().astimezone().isoformat(timespec="seconds"),
                "creadas": creadas,
                "actualizadas": actualizadas,
                "saltadas": saltadas,
            }, f)
    except Exception as e:
        print(f"(no pude escribir la marca de sync: {e})")


if __name__ == "__main__":
    # Hasta ahora esto salia con codigo 0 SIEMPRE, incluso cuando Holidu no
    # devolvia nada porque la sesion habia caducado: quien llamara al script no
    # tenia forma de distinguir "todo bien" de "no se sincronizo nada".
    codigo = 0
    try:
        bookings = get_holidu_bookings()
        if bookings:
            sync_to_pocketbase(bookings)
        else:
            print("No se obtuvieron reservas de Holidu.")
            codigo = 1
    except Exception as e:
        print(f"❌ El sync ha fallado: {e}")
        codigo = 1
    finally:
        authentication.quit_driver()

    sys.exit(codigo)
