<?php
// router.php - Usado con php -S (desarrollo local)

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// 1. Bloquear archivos sensibles
$blocked_patterns = [
    '/^\./',              // .env, .git, etc
    '/^chrome_profile\//',
    '/^__pycache__\//',
    '/^includes\//',      // PHP includes
    '/\.py$/',            // Scripts Python
    '/^debug_/',          // Logs debug
    '/^entrypoint\.sh$/',
];

foreach ($blocked_patterns as $pattern) {
    if (preg_match($pattern, ltrim($path, '/')) || preg_match($pattern, basename($path))) {
        http_response_code(403);
        echo "403 Forbidden";
        exit;
    }
}

// 2. Servir archivos estaticos (assets/, api/, etc)
if (file_exists(__DIR__ . $path) && $path != '/' && is_file(__DIR__ . $path)) {
    return false;
}

// 3. Redirigir todo a index.php
include __DIR__ . '/index.php';
