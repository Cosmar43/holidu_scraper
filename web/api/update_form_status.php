<?php
/**
 * API: Alternar estado de formulario manualmente
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();
requireCsrf();  // escribe: hace falta el token, no solo la cookie

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$bookings = $input['bookings'] ?? [];

if (empty($bookings)) {
    echo json_encode(['success' => false, 'error' => 'No hay reservas seleccionadas']);
    exit;
}

$POCKETBASE_URL = getenv('POCKETBASE_URL');
$POCKETBASE_ADMIN_EMAIL = getenv('POCKETBASE_ADMIN_EMAIL');
$POCKETBASE_ADMIN_PASSWORD = getenv('POCKETBASE_ADMIN_PASSWORD');

require_once __DIR__ . '/../includes/pocketbase.php';
$pbToken = getPocketbaseAuthToken($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);

$updatedCount = 0;

if ($pbToken) {
    foreach ($bookings as $booking) {
        $reservaId = $booking['id'] ?? '';
        if (!$reservaId) continue;

        $searchUrl = "{$POCKETBASE_URL}/api/collections/reservas/records?filter=" .
                     rawurlencode("(id_reserva='" . valorFiltroSeguro($reservaId) . "')");
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: {$pbToken}\r\n"
            ]
        ]);

        $response = @file_get_contents($searchUrl, false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['items']) && count($data['items']) > 0) {
                $row = $data['items'][0];
                $rowId = $row['id'];
                $currentState = $row['formulario'] ?? '';

                $newState = ($currentState === 'Enviado') ? '' : 'Enviado';

                $updateUrl = "{$POCKETBASE_URL}/api/collections/reservas/records/{$rowId}";
                $updateData = json_encode(['formulario' => $newState]);

                $updateContext = stream_context_create([
                    'http' => [
                        'method' => 'PATCH',
                        'header' => "Authorization: {$pbToken}\r\nContent-Type: application/json\r\n",
                        'content' => $updateData
                    ]
                ]);

                $updateResponse = @file_get_contents($updateUrl, false, $updateContext);
                if ($updateResponse !== false) {
                    $updatedCount++;
                }
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => "Se ha alternado el estado de $updatedCount reserva(s)"
]);
