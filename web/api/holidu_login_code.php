<?php
/**
 * API: Recibe el código 2FA de la web y lo deja para el proceso de login.
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();
requireCsrf();  // escribe: hace falta el token, no solo la cookie
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$code = trim($input['code'] ?? '');

if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'Codigo vacio']);
    exit;
}
if (!preg_match('/^[0-9]{3,8}$/', $code)) {
    echo json_encode(['success' => false, 'error' => 'Codigo invalido (deben ser 3-8 digitos)']);
    exit;
}

$stateDir = '/tmp/holidu_login';
if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }
file_put_contents("$stateDir/code.txt", $code);

echo json_encode(['success' => true]);
