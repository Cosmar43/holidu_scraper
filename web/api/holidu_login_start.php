<?php
/**
 * API: Iniciar login de Holidu (lanza proceso en segundo plano como www-data).
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();
requireCsrf();  // escribe: hace falta el token, no solo la cookie
header('Content-Type: application/json');

$stateDir = '/tmp/holidu_login';
if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }

$statusFile = "$stateDir/status.json";

// Evitar lanzar dos procesos a la vez (Chrome no admite dos instancias con el mismo perfil)
if (file_exists($statusFile)) {
    $prev = json_decode(@file_get_contents($statusFile), true);
    if ($prev && in_array($prev['state'] ?? '', ['running', 'waiting_2fa'], true)) {
        if ((time() - (int)($prev['ts'] ?? 0)) < 360) {
            echo json_encode(['success' => true, 'message' => 'Ya hay un login en curso', 'resumed' => true]);
            exit;
        }
    }
}

// Limpiar estado previo
@unlink("$stateDir/code.txt");
file_put_contents($statusFile, json_encode(['state' => 'running', 'message' => 'Iniciando...', 'ts' => time()]));

// Lanzar el proceso en segundo plano (hereda el usuario de apache: www-data)
shell_exec('cd /var/www/html && nohup python3 -W ignore web_holidu_login.py >> /tmp/holidu_login/proc.log 2>&1 &');

echo json_encode(['success' => true, 'message' => 'Proceso de login iniciado']);
