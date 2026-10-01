<?php
/**
 * API: Actualizar el estado de la fianza de una reserva
 *
 * El importe y quién la gestiona vienen de Holidu y no se tocan aquí; esto
 * solo escribe los campos manuales (estado, retenido y nota).
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pocketbase.php';
require_once __DIR__ . '/../includes/fianza.php';

requireAuth();
requireCsrf();  // escribe: hace falta el token, no solo la cookie

header('Content-Type: application/json');

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$idReserva = trim($input['id_reserva'] ?? '');
$estado    = trim($input['estado'] ?? '');
$retenido  = trim((string) ($input['retenido'] ?? ''));
$nota      = trim($input['nota'] ?? '');

if ($idReserva === '') {
    responder(['success' => false, 'error' => 'Falta el ID de la reserva'], 400);
}

if (!in_array($estado, FIANZA_ESTADOS, true)) {
    responder(['success' => false, 'error' => 'Estado de fianza no válido'], 400);
}

// Lo retenido y el motivo solo tienen sentido cuando nos quedamos con algo.
if ($estado !== 'retenida') {
    $retenido = '';
    $nota = '';
} else {
    if ($retenido === '' || !is_numeric(str_replace(',', '.', $retenido))) {
        responder(['success' => false, 'error' => 'Indica cuánto se retiene'], 400);
    }
    $retenido = (string) (float) str_replace(',', '.', $retenido);
    if ((float) $retenido <= 0) {
        responder(['success' => false, 'error' => 'El importe retenido debe ser mayor que cero'], 400);
    }
}

$pbToken = getPocketbaseAuthToken($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
if (!$pbToken) {
    responder(['success' => false, 'error' => 'No se pudo autenticar con PocketBase'], 500);
}

// Buscar la reserva
$searchUrl = "{$POCKETBASE_URL}/api/collections/reservas/records?filter=" .
             rawurlencode("(id_reserva='" . valorFiltroSeguro($idReserva) . "')");
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: {$pbToken}\r\n",
        'timeout' => 30
    ]
]);

$response = @file_get_contents($searchUrl, false, $context);
if ($response === false) {
    responder(['success' => false, 'error' => 'No se pudo consultar la reserva'], 500);
}

$data = json_decode($response, true);
if (empty($data['items'])) {
    responder(['success' => false, 'error' => 'Reserva no encontrada'], 404);
}

$row = $data['items'][0];

// No dejar marcar estados en una reserva cuya fianza gestiona el canal.
$comoBooking = [
    'Fianza' => $row['fianza'] ?? '',
    'Fianza gestiona' => $row['fianza_gestiona'] ?? '',
];
if (!fianzaAplica($comoBooking)) {
    $canal = $row['canal'] ?? 'el canal';
    responder([
        'success' => false,
        'error' => "Esta reserva no lleva fianza nuestra (la gestiona {$canal})"
    ], 400);
}

// Actualizar
$updateUrl = "{$POCKETBASE_URL}/api/collections/reservas/records/{$row['id']}";
$updateData = json_encode([
    'fianza_estado'   => $estado,
    'fianza_retenido' => $retenido,
    'fianza_nota'     => $nota,
]);

$updateContext = stream_context_create([
    'http' => [
        'method' => 'PATCH',
        'header' => "Authorization: {$pbToken}\r\nContent-Type: application/json\r\n",
        'content' => $updateData,
        'timeout' => 30
    ]
]);

$updateResponse = @file_get_contents($updateUrl, false, $updateContext);
if ($updateResponse === false) {
    responder(['success' => false, 'error' => 'No se pudo guardar la fianza'], 500);
}

// Deja rastro en el log del contenedor de quién cambió qué.
error_log(sprintf(
    'fianza %s: %s -> %s (retenido: %s)',
    $idReserva,
    $row['fianza_estado'] ?: 'pendiente',
    $estado,
    $retenido !== '' ? $retenido : '-'
));

responder([
    'success' => true,
    'message' => "Fianza de la reserva {$idReserva} marcada como {$estado}",
    'estado' => $estado,
    'retenido' => $retenido,
    'nota' => $nota,
]);
