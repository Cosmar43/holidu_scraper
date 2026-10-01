<?php
/**
 * API: Borrar una nota de huésped.
 *
 * El texto entero queda en el log del contenedor ANTES de borrarlo: es una
 * operación irreversible, y así una nota que se va por error se recupera con
 * `docker compose logs web`.
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
$idNota = trim($input['id_nota'] ?? '');

if ($idNota === '') {
    responder(['success' => false, 'error' => 'Falta el ID de la nota'], 400);
}

$pbToken = getPocketbaseAuthToken($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
if (!$pbToken) {
    responder(['success' => false, 'error' => 'No se pudo autenticar con PocketBase'], 500);
}

$ruta = '/api/collections/notas/records/' . rawurlencode($idNota);

list($codigo, $nota) = notasPeticion($POCKETBASE_URL, $pbToken, 'GET', $ruta);
if ($codigo === 404) {
    responder(['success' => false, 'error' => 'Esa nota ya no existe'], 404);
}
if ($codigo !== 200 || !is_array($nota)) {
    responder(['success' => false, 'error' => 'No se pudo leer la nota'], 500);
}

error_log(sprintf(
    'BORRANDO nota %s de %s [%s], reserva %s: %s',
    $idNota,
    $nota['huesped_nombre'] ?? '?',
    $nota['huesped_clave'] ?? '?',
    $nota['id_reserva'] ?? '?',
    $nota['texto'] ?? ''
));

list($codigo, ) = notasPeticion($POCKETBASE_URL, $pbToken, 'DELETE', $ruta);
if ($codigo !== 204 && $codigo !== 200) {
    responder(['success' => false, 'error' => 'No se pudo borrar la nota'], 500);
}

responder([
    'success' => true,
    'message' => 'Nota borrada',
]);
