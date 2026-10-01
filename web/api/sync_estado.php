<?php
/**
 * API: cuándo fue la última sincronización que salió bien.
 *
 * Existe para que el asistente pueda avisar de que hace días que no se
 * sincroniza, que es lo que pasa cuando caduca la sesión de Holidu: el cron de
 * cada 6 h sigue corriendo, falla, y el panel se queda enseñando datos viejos
 * sin que nadie se entere. Antes solo se descubría si alguien le pedía
 * sincronizar al bot.
 *
 * La marca la escribe sync_holidu_pocketbase.py al terminar bien
 * (includes/.ultimo_sync.json). Es de solo lectura, así que no pide CSRF.
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

// Igual que sync.php: el agente vive en otro contenedor y no tiene sesión.
$claveAgente = getenv('AGENTE_API_KEY');
$claveRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';
$esAgente = !empty($claveAgente) && hash_equals($claveAgente, $claveRecibida);
if (!$esAgente) {
    requireAuth();
}

header('Content-Type: application/json');

$marca = __DIR__ . '/../includes/.ultimo_sync.json';
if (!file_exists($marca)) {
    // Nunca ha terminado bien desde que existe la marca. Puede ser que acabe
    // de desplegarse, así que quien lo lea decide qué hacer con el null.
    echo json_encode(['success' => true, 'cuando' => null]);
    exit;
}

$datos = json_decode(file_get_contents($marca), true);
if (!is_array($datos)) {
    echo json_encode(['success' => true, 'cuando' => null]);
    exit;
}

echo json_encode(array_merge(['success' => true], $datos));
