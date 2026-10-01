<?php
/**
 * Carga de variables de entorno.
 * Primero intenta getenv() (Docker), luego .env local.
 */

function loadEnv($path) {
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // Mismas comillas que acepta docker compose ('valor' o "valor")
            if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"') && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/../.env');

$POCKETBASE_URL = getenv('POCKETBASE_URL');
$POCKETBASE_ADMIN_EMAIL = getenv('POCKETBASE_ADMIN_EMAIL');
$POCKETBASE_ADMIN_PASSWORD = getenv('POCKETBASE_ADMIN_PASSWORD');
$ADMIN_PASSWORD = getenv('ADMIN_PASSWORD');

// Personalizacion del panel. El mensaje admite {nombre} (del huesped),
// {alojamiento} y {enlace} (el del formulario de check-in de Holidu).
$NOMBRE_ALOJAMIENTO = getenv('NOMBRE_ALOJAMIENTO') ?: 'Mi alojamiento';
$MENSAJE_FORMULARIO = getenv('MENSAJE_FORMULARIO') ?: 'Hola {nombre}, ¡estamos prontos para tu estancia en {alojamiento}! Para garantizar que la entrada sea fluida y sin complicaciones, asegúrate de rellenar nuestro formulario de registro antes de tu llegada. Puedes acceder al formulario haciendo clic en el siguiente enlace. Gracias y ¡hasta pronto! {enlace}';

if (!$ADMIN_PASSWORD) {
    error_log("CRITICAL: ADMIN_PASSWORD no configurada. Acceso bloqueado.");
}
