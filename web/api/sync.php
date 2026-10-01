<?php
/**
 * API: Sincronizar reservas Holidu → PocketBase
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

// El asistente (agente/) llama aquí desde otro contenedor, donde no hay sesión
// ni cookie que valga: se identifica con la clave que ya comparte con n8n. El
// panel sigue entrando por sesión, exactamente igual que antes.
//
// El sync tiene que vivir en ESTE contenedor porque necesita Chrome y la sesión
// de Holidu guardada en chrome_profile/, que solo están aquí.
$claveAgente = getenv('AGENTE_API_KEY');
$claveRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';
$esAgente = !empty($claveAgente) && hash_equals($claveAgente, $claveRecibida);

if (!$esAgente) {
    requireAuth();
    // Solo para el panel: el agente se identifica con la clave y no
    // tiene sesion de la que aprovecharse un tercero.
    requireCsrf();
}

header('Content-Type: application/json');
set_time_limit(300);

// exec() en vez de shell_exec() para tener el CÓDIGO DE SALIDA. Antes el éxito
// se decidía solo con un strpos('Creadas:') sobre la salida, así que cualquier
// cosa que imprimiera ese texto contaba como bien y un fallo del script no se
// distinguía de un sync sin novedades. El script ya sale con 1 cuando Holidu no
// devuelve nada (lo normal cuando caduca la sesión), así que ahora tienen que
// cumplirse las dos cosas.
$lineas = [];
$codigo = 1;
exec('cd /var/www/html && python3 -W ignore sync_holidu_pocketbase.py 2>&1', $lineas, $codigo);
$output = implode("\n", $lineas);

$success = ($codigo === 0) && preg_match('/Creadas:\s*\d+/', $output) === 1;

echo json_encode([
    'success' => $success,
    'output' => $output
]);
