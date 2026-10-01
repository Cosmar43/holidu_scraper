<?php
/**
 * Notas sobre huéspedes, compartidas entre la vista y la API.
 *
 * Las notas van pegadas al HUÉSPED, no a la estancia, así que viven en su
 * propia colección de PocketBase ('notas') y no en un campo de 'reservas': la
 * nota que se escribe hoy tiene que salir sola en la reserva que haga el mismo
 * huésped el año que viene.
 *
 * La identidad es el TELÉFONO normalizado. El correo no sirve: Booking.com da
 * un alias distinto por reserva (el mismo huésped llega como
 * 'nombre.123456@guest.booking.com' una vez y 'nombre.654321@...' la siguiente) y las reservas
 * de Airbnb llegan sin correo. El teléfono está en todas y coincide.
 *
 * ⚠️ claveHuesped() está duplicada en agente/app/notas.py, que es quien las lee
 * y escribe desde el asistente. Si cambia una, cambia la otra.
 */

require_once __DIR__ . '/pocketbase.php'; // getPocketbaseAuthToken()

// Tope de una nota. El mismo que TOPE_TEXTO en agente/app/notas.py.
const NOTA_TOPE_TEXTO = 1000;

/**
 * Llamada a la colección 'notas' de PocketBase.
 *
 * Existe para no repetir cuatro veces el stream_context_create en los tres
 * endpoints. Devuelve [$codigoHttp, $datos]; $datos es null si no hubo cuerpo
 * (el DELETE de PocketBase contesta 204 sin nada).
 */
function notasPeticion($url, $token, $metodo, $ruta, $cuerpo = null) {
    $cabeceras = "Authorization: $token\r\n";
    $opciones = [
        'method' => $metodo,
        'timeout' => 30,
        'ignore_errors' => true, // queremos leer el cuerpo de los 4xx
    ];
    if ($cuerpo !== null) {
        $cabeceras .= "Content-Type: application/json\r\n";
        $opciones['content'] = json_encode($cuerpo);
    }
    $opciones['header'] = $cabeceras;

    $respuesta = @file_get_contents("$url$ruta", false, stream_context_create(['http' => $opciones]));
    if ($respuesta === false) {
        return [0, null];
    }

    // El código HTTP sale de la primera línea de $http_response_header.
    $codigo = 0;
    foreach ($http_response_header ?? [] as $linea) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
            $codigo = (int) $m[1];
        }
    }
    return [$codigo, $respuesta === '' ? null : json_decode($respuesta, true)];
}

/** Texto de una nota listo para guardar, o null si no hay nota que guardar. */
function notaTextoLimpio($texto) {
    $limpio = trim((string) $texto);
    if ($limpio === '') {
        return null;
    }
    return mb_substr($limpio, 0, NOTA_TOPE_TEXTO);
}

/**
 * Identidad del huésped de una reserva: su teléfono normalizado.
 *
 * Misma regla que clave_huesped() en agente/app/notas.py.
 *
 * Sin teléfono utilizable cae a 'r:{id_reserva}': la nota se guarda igual, pero
 * solo se verá en esa reserva. Hoy no pasa en ninguna de las reservas.
 */
function claveHuesped($telefono, $idReserva = '') {
    $digitos = preg_replace('/\D/', '', (string) $telefono);
    if (strlen($digitos) >= 9) {
        return substr($digitos, -9);
    }
    return 'r:' . $idReserva;
}

/** La clave, lista para meterla en un filtro de PocketBase sin romperlo. */
function claveSegura($clave) {
    return valorFiltroSeguro($clave); // vive en pocketbase.php, ya incluido arriba
}

/**
 * Todas las notas repartidas por clave de huésped.
 *
 * Son pocas, así que se traen de una vez y se agrupan aquí en vez de hacer una
 * consulta por reserva. Si la colección aún no existe (la crea el sync) se
 * devuelve un array vacío: el panel tiene que seguir pintando la tabla.
 */
function notasPorHuesped($url, $email, $password) {
    $token = getPocketbaseAuthToken($url, $email, $password);
    if (!$token) {
        return [];
    }
    return notasPorHuespedConToken($url, $token);
}

/** Igual que la anterior, cuando ya se tiene el token (las API lo reusan). */
function notasPorHuespedConToken($url, $token) {
    $notasUrl = "$url/api/collections/notas/records?perPage=500&sort=creado";
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: $token\r\n",
            'timeout' => 30,
            'ignore_errors' => true, // para poder distinguir un 404 de un fallo de red
        ]
    ]);

    $response = @file_get_contents($notasUrl, false, $context);
    if ($response === false) {
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['items'])) {
        // Colección todavía sin crear, o respuesta ilegible: ninguna nota.
        return [];
    }

    $porHuesped = [];
    foreach ($data['items'] as $nota) {
        $clave = $nota['huesped_clave'] ?? '';
        $porHuesped[$clave][] = $nota;
    }
    return $porHuesped;
}

/**
 * ¿Cuenta esta reserva como una estancia de verdad?
 *
 * Hace falta que NO esté cancelada y que el check-in ya haya llegado. Una
 * cancelada no cuenta por mucho que exista: si se canceló, el huésped no vino.
 * En estos datos importa bastante, porque la mayoría de los huéspedes que
 * aparecen dos veces son una reserva cancelada y rehecha, no un retorno.
 *
 * Misma regla que _es_estancia() en agente/app/notas.py.
 */
function esEstancia($booking, $hoy = null) {
    $hoy = $hoy ?? strtotime('today');
    $cancelada = strpos($booking['Estado'] ?? '', 'CANCEL') !== false;
    $empezada = strtotime($booking['Check-in'] ?? '9999-12-31') < $hoy;
    return !$cancelada && $empezada;
}

/**
 * Añade a cada reserva lo que hace falta para pintar la celda del huésped.
 *
 *   'Huesped clave' → identidad, la que abre el modal
 *   'Notas'         → cuántas hay de ese huésped (de cualquier reserva suya)
 *   'Se alojó antes' → si el huésped YA SE ALOJÓ en otra reserva
 *
 * Lo de 'Se alojó antes' sale de agrupar las propias reservas, sin más
 * consultas. Solo cuentan las estancias de verdad (ver esEstancia): una
 * cancelada anterior no significa que el huésped haya estado aquí.
 *
 * Las notas, en cambio, sí se cuentan vengan de donde vengan: una nota escrita
 * en una reserva que luego se canceló sigue siendo información sobre esa
 * persona.
 */
function anotarNotas($bookings, $porHuesped) {
    if (isset($bookings['error']) || empty($bookings)) {
        return $bookings;
    }

    $hoy = strtotime('today');

    // En qué reservas se alojó de verdad cada huésped.
    $estanciasPorClave = [];
    foreach ($bookings as $b) {
        $clave = claveHuesped($b['Número de teléfono'] ?? '', $b['ID_Reserva'] ?? '');
        $estanciasPorClave[$clave] = $estanciasPorClave[$clave] ?? [];
        if (esEstancia($b, $hoy)) {
            $estanciasPorClave[$clave][] = $b['ID_Reserva'] ?? '';
        }
    }

    foreach ($bookings as &$b) {
        $clave = claveHuesped($b['Número de teléfono'] ?? '', $b['ID_Reserva'] ?? '');
        $b['Huesped clave'] = $clave;
        $b['Notas'] = count($porHuesped[$clave] ?? []);
        // "antes" = en OTRA reserva. La actual no cuenta como antecedente de
        // sí misma, aunque sea una estancia ya pasada.
        $otras = array_diff($estanciasPorClave[$clave], [$b['ID_Reserva'] ?? '']);
        $b['Se alojó antes'] = !empty($otras);
    }
    unset($b);

    return $bookings;
}
