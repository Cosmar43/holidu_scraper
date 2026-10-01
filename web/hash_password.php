<?php
/**
 * Utilidad para generar hash de contraseña seguro.
 * Uso: php hash_password.php "tu_contraseña"
 */
if (PHP_SAPI !== 'cli') {
    die("Este script solo puede ejecutarse desde la línea de comandos.");
}

if ($argc < 2) {
    die("Uso: php hash_password.php \"tu_contraseña\"\n");
}

$password = $argv[1];
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "\nTu hash para el archivo .env:\n";
// Entre comillas simples: sin ellas, docker compose toma cada $ del hash
// como una variable y lo rompe.
echo "ADMIN_PASSWORD='" . $hash . "'\n\n";
echo "Copia y pega la línea anterior en tu archivo .env para máxima seguridad.\n";
