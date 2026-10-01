"""Pruebas en frio de las funciones puras del sync.

    docker compose exec web python3 pruebas_sync.py

No tocan Holidu, ni PocketBase, ni Chrome: solo llaman a funciones con datos de
mentira. Mismo estilo que agente/app/pruebas_*.py, que tampoco usan pytest.

Se escribieron despues de que el sync borrara importes de fianza en silencio
(2026-08-22): cuando fallaba la peticion del detalle de una reserva, los campos
que solo salen de ahi se mandaban vacios en el PATCH y pisaban lo que ya estaba
guardado. La prueba de `has_booking_changed` con claves ausentes es la que
vigila que eso no vuelva.
"""

import sys

sys.path.insert(0, "/var/www/html")

import sync_holidu_pocketbase as sync  # noqa: E402

fallos = []


def comprobar(titulo, obtenido, esperado):
    if obtenido == esperado:
        print(f"  ok   {titulo}")
    else:
        print(f"  MAL  {titulo}\n         esperado: {esperado!r}\n         obtenido: {obtenido!r}")
        fallos.append(titulo)


# --- extraer_fianza --------------------------------------------------------
#
# El importe sale de additionalFees con feeCategory SECURITY_DEPOSIT. El campo
# suelto securityDepositAmount NO vale: devuelve siempre el valor configurado en
# el apartamento (500) aunque la reserva lleve otra cantidad.

def pruebas_fianza():
    print("\nextraer_fianza()")

    nuestra = {
        "securityDepositAmount": 500,
        "additionalFees": [
            {"feeCategory": "CLEANING", "baseAmount": 60},
            {"feeCategory": "SECURITY_DEPOSIT", "baseAmount": 250,
             "feeHandledBy": "HOME_OWNER"},
        ],
    }
    comprobar("la cobramos nosotros", sync.extraer_fianza(nuestra), ("250", "nosotros"))
    comprobar("ignora securityDepositAmount (daria 500)",
              sync.extraer_fianza(nuestra)[0] != "500", True)

    del_canal = {"additionalFees": [
        {"feeCategory": "SECURITY_DEPOSIT", "baseAmount": 250,
         "feeHandledBy": "PARTNER"}]}
    comprobar("la gestiona el canal", sync.extraer_fianza(del_canal), ("250", "canal"))

    airbnb = {"securityDepositAmount": 500, "additionalFees": [
        {"feeCategory": "CLEANING", "baseAmount": 60}]}
    comprobar("Airbnb: sin fianza nuestra", sync.extraer_fianza(airbnb), ("", ""))

    comprobar("sin additionalFees", sync.extraer_fianza({}), ("", ""))
    comprobar("detalle que no es un dict", sync.extraer_fianza(None), ("", ""))


# --- formatear_importe -----------------------------------------------------

def pruebas_importe():
    print("\nformatear_importe()")
    comprobar("entero", sync.formatear_importe(500.0), "500")
    comprobar("con decimales", sync.formatear_importe(12.5), "12.5")
    comprobar("None", sync.formatear_importe(None), "")
    comprobar("texto que no es numero", sync.formatear_importe("pendiente"), "pendiente")


# --- has_booking_changed ---------------------------------------------------

def pruebas_cambios():
    print("\nhas_booking_changed()")

    guardada = {
        "id_reserva": "123", "nombre_huesped": "Ana", "fianza": "250",
        "fianza_gestiona": "nosotros", "canal": "BOOKINGCOM",
        "link_formulario": "https://ejemplo/abc", "formulario": "Enviado",
        "fianza_estado": "pagada",
    }

    igual = {"id_reserva": "123", "nombre_huesped": "Ana", "fianza": "250"}
    comprobar("sin cambios", sync.has_booking_changed(igual, guardada), False)

    distinta = {"id_reserva": "123", "nombre_huesped": "Ana Maria"}
    comprobar("nombre distinto", sync.has_booking_changed(distinta, guardada), True)

    comprobar("250 y 250.0 son el mismo importe",
              sync.has_booking_changed({"fianza": "250.0"}, guardada), False)

    # LO IMPORTANTE. Cuando falla la peticion del detalle, el sync NO mete esas
    # claves en el diccionario. Si no estan, no se comparan y no se mandan, asi
    # que lo que hay guardado sobrevive. Si alguien vuelve a meterlas siempre
    # (aunque sea vacias), esta prueba se cae.
    sin_detalle = {"id_reserva": "123", "nombre_huesped": "Ana"}
    comprobar("sin detalle: no se detecta cambio",
              sync.has_booking_changed(sin_detalle, guardada), False)
    comprobar("sin detalle: la fianza no viaja en el PATCH",
              "fianza" in sin_detalle, False)

    # Y el reves: si se mandaran vacias, SI se veria como un cambio, que es
    # exactamente el borrado silencioso que hubo que arreglar.
    como_estaba_antes = {"id_reserva": "123", "nombre_huesped": "Ana",
                         "fianza": "", "fianza_gestiona": "", "canal": "",
                         "link_formulario": ""}
    comprobar("mandarlas vacias SI las pisaria",
              sync.has_booking_changed(como_estaba_antes, guardada), True)

    # Los campos manuales del panel nunca se mapean, asi que tampoco se tocan.
    comprobar("los campos manuales no estan en el mapeo",
              [c for c in sync.CAMPOS_MANUALES if c in guardada and c in igual], [])


def main():
    print("Pruebas en frio del sync (no tocan Holidu ni PocketBase)")
    pruebas_fianza()
    pruebas_importe()
    pruebas_cambios()

    print()
    if fallos:
        print(f"❌ {len(fallos)} prueba(s) mal: " + ", ".join(fallos))
        return 1
    print("✅ Todo bien.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
