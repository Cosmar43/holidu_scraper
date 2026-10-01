<?php
/**
 * API: Guardar una nota de huésped (nueva o editada).
 *
 * Con 'id_nota' edita esa nota; sin él crea una nueva sobre el huésped de
 * 'id_reserva'. Crear nunca pisa lo que ya había: cada nota es un registro.
 *
 * La clave del huésped y su nombre se copian de la reserva en el momento de
 * crearla, y al editar no se tocan: una nota no cambia de dueño.
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pocketbase.php';
require_once __DIR__ . '/../includes/notas.php';

requireAuth();
requireCsrf();  // escribe: hace falta el token, no solo la cookie

header('Content-Type: application/json');

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$idNota    = trim($input['id_nota'] ?? '');
$idReserva = trim($input['id_reserva'] ?? '');
$texto     = notaTextoLimpio($input['texto'] ?? '');

if ($texto === null) {
    responder(['success' => false, 'error' => 'La nota está vacía: escribe algo antes de guardarla'], 400);
}
if ($idNota === '' && $idReserva === '') {
    responder(['success' => false, 'error' => 'Falta la reserva de la que es la nota'], 400);
}

$pbToken = getPocketbaseAuthToken($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
if (!$pbToken) {
    responder(['success' => false, 'error' => 'No se pudo autenticar con PocketBase'], 500);
}

// --- Editar una nota que ya existe ------------------------------------
if ($idNota !== '') {
    list($codigo, $antes) = notasPeticion($POCKETBASE_URL, $pbToken, 'GET',
        '/api/collections/notas/records/' . rawurlencode($idNota));
    if ($codigo === 404) {
        responder(['success' => false, 'error' => 'Esa nota ya no existe'], 404);
    }
    if ($codigo !== 200 || !is_array($antes)) {
        responder(['success' => false, 'error' => 'No se pudo leer la nota'], 500);
    }

    list($codigo, $datos) = notasPeticion($POCKETBASE_URL, $pbToken, 'PATCH',
        '/api/collections/notas/records/' . rawurlencode($idNota),
        ['texto' => $texto]);
    if ($codigo !== 200) {
        responder(['success' => false, 'error' => 'No se pudo guardar la nota'], 500);
    }

    error_log(sprintf(
        'nota %s de %s: %s -> %s',
        $idNota, $antes['huesped_nombre'] ?? '?', $antes['texto'] ?? '', $texto
    ));

    responder([
        'success' => true,
        'message' => 'Nota guardada',
        'id_nota' => $idNota,
        'texto' => $texto,
    ]);
}

// --- Crear una nota nueva --------------------------------------------
$buscarUrl = '/api/collections/reservas/records?perPage=1&filter=' .
             rawurlencode("(id_reserva='" . valorFiltroSeguro($idReserva) . "')");
list($codigo, $datos) = notasPeticion($POCKETBASE_URL, $pbToken, 'GET', $buscarUrl);
if ($codigo !== 200 || !is_array($datos)) {
    responder(['success' => false, 'error' => 'No se pudo consultar la reserva'], 500);
}
if (empty($datos['items'])) {
    responder(['success' => false, 'error' => 'Reserva no encontrada'], 404);
}

$reserva = $datos['items'][0];
$clave = claveHuesped($reserva['numero_de_telefono'] ?? '', $idReserva);

list($codigo, $creada) = notasPeticion($POCKETBASE_URL, $pbToken, 'POST',
    '/api/collections/notas/records', [
        'huesped_clave' => $clave,
        'huesped_nombre' => $reserva['nombre_huesped'] ?? '',
        'id_reserva' => $idReserva,
        'texto' => $texto,
        'origen' => 'panel',
    ]);
if ($codigo !== 200 || !is_array($creada)) {
    responder(['success' => false, 'error' => 'No se pudo guardar la nota'], 500);
}

error_log(sprintf(
    'nota nueva (panel) sobre %s [%s], reserva %s: %s',
    $reserva['nombre_huesped'] ?? '?', $clave, $idReserva, $texto
));

responder([
    'success' => true,
    'message' => 'Nota apuntada',
    'id_nota' => $creada['id'] ?? '',
    'texto' => $texto,
]);
