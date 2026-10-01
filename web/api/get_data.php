<?php
/**
 * API: Obtener datos de reservas desde PocketBase
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pocketbase.php';
require_once __DIR__ . '/../includes/notas.php';

requireAuth();

header('Content-Type: application/json');

try {
    $bookings = getPocketbaseData($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
    $bookings = categorizeBookings($bookings);
    // Mismo añadido que en index.php: sin esto, el refresco por JS pintaría la
    // celda del huésped sin la insignia de notas.
    $bookings = anotarNotas($bookings, notasPorHuesped($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD));

    echo json_encode($bookings);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
