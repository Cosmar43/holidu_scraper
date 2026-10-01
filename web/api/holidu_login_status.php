<?php
/**
 * API: Estado actual del login de Holidu.
 */
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();
header('Content-Type: application/json');

$statusFile = '/tmp/holidu_login/status.json';
if (!file_exists($statusFile)) {
    echo json_encode(['state' => 'idle', 'message' => '']);
    exit;
}
$data = json_decode(@file_get_contents($statusFile), true);
if (!$data) { $data = ['state' => 'idle', 'message' => '']; }
echo json_encode($data);
