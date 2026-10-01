<?php
/**
 * API: Notas de un huésped.
 *
 * Se piden por la clave del huésped (su teléfono normalizado), no por reserva:
 * lo que interesa ver son TODAS sus notas, incluidas las que se escribieron en
 * estancias anteriores. Es el sentido de que existan.
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pocketbase.php';
require_once __DIR__ . '/../includes/notas.php';

requireAuth();

header('Content-Type: application/json');

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
}

$clave = claveSegura(trim($_GET['clave'] ?? ''));
if ($clave === '') {
    responder(['success' => false, 'error' => 'Falta la clave del huésped'], 400);
}

$pbToken = getPocketbaseAuthToken($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
if (!$pbToken) {
    responder(['success' => false, 'error' => 'No se pudo autenticar con PocketBase'], 500);
}

$ruta = '/api/collections/notas/records?perPage=500&sort=creado&filter=' .
        rawurlencode("(huesped_clave='{$clave}')");
list($codigo, $datos) = notasPeticion($POCKETBASE_URL, $pbToken, 'GET', $ruta);

if ($codigo === 404) {
    // La colección la crea el sync; hasta entonces, simplemente no hay notas.
    responder(['success' => true, 'notas' => []]);
}
if ($codigo !== 200 || !is_array($datos)) {
    responder(['success' => false, 'error' => 'No se pudieron leer las notas'], 500);
}

$notas = [];
foreach ($datos['items'] ?? [] as $n) {
    $notas[] = [
        'id' => $n['id'] ?? '',
        'texto' => $n['texto'] ?? '',
        'fecha' => substr($n['creado'] ?? '', 0, 10),
        'origen' => $n['origen'] ?? '',
        'id_reserva' => $n['id_reserva'] ?? '',
        'huesped' => $n['huesped_nombre'] ?? '',
    ];
}

responder(['success' => true, 'notas' => $notas]);
