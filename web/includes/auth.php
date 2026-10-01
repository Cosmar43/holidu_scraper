<?php
/**
 * Autenticación: sesiones, CSRF, login/logout y "Recordarme".
 * Mejorado para máxima seguridad y comodidad.
 */

// Detectar HTTPS (considerando proxies/load balancers)
$is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
             (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Configuración de cookies de sesión para mayor seguridad
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', $is_secure ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas de vida en servidor

session_start();

// CSRF Token para prevenir ataques Cross-Site Request Forgery
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Si no está autenticado, intentar recuperar sesión con cookie "Recordarme"
if (!isAuthenticated() && isset($_COOKIE['remember_me'])) {
    checkRememberMe();
}

/**
 * Verifica si el usuario está autenticado y la sesión es válida.
 */
function isAuthenticated() {
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        // Protección contra inactividad prolongada (2 horas)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 7200)) {
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

/**
 * Valida el token persistente de la cookie "Recordarme".
 */
function checkRememberMe() {
    $token = $_COOKIE['remember_me'];
    $tokens = loadTokens();
    
    foreach ($tokens as $id => $stored) {
        if (hash_equals($stored['token_hash'], hash('sha256', $token))) {
            if (time() < $stored['expires']) {
                // Token válido: Regenerar ID por seguridad e iniciar sesión
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['last_activity'] = time();
                return true;
            } else {
                // Token expirado: Limpiar
                unset($tokens[$id]);
                saveTokens($tokens);
            }
        }
    }
    // Si llegamos aquí, el token no es válido o ha expirado
    setcookie('remember_me', '', time() - 3600, '/');
    return false;
}

/**
 * Carga los tokens desde el archivo protegido.
 */
function loadTokens() {
    $file = __DIR__ . '/.tokens.php';
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    // Extraer JSON tras el die() de seguridad
    $json = str_replace("<?php die(); ?>\n", "", $content);
    return json_decode($json, true) ?: [];
}

/**
 * Guarda los tokens en el archivo protegido.
 */
function saveTokens($tokens) {
    $file = __DIR__ . '/.tokens.php';
    // Limpiar tokens expirados antes de guardar
    $tokens = array_filter($tokens, function($t) { return $t['expires'] > time(); });
    $content = "<?php die(); ?>\n" . json_encode(array_values($tokens));
    file_put_contents($file, $content);
}

/**
 * Procesa logout.
 */
function handleLogout() {
    if (isset($_GET['logout'])) {
        // Eliminar token persistente si existe
        if (isset($_COOKIE['remember_me'])) {
            $token = $_COOKIE['remember_me'];
            $tokens = loadTokens();
            foreach ($tokens as $id => $stored) {
                if (hash_equals($stored['token_hash'], hash('sha256', $token))) {
                    unset($tokens[$id]);
                    break;
                }
            }
            saveTokens($tokens);
            setcookie('remember_me', '', time() - 3600, '/');
        }
        
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit;
    }
}

/**
 * Exige el token CSRF en las llamadas que CAMBIAN algo.
 *
 * Hasta ahora solo se validaba en el login: los endpoints de la API se
 * conformaban con requireAuth(), o sea con la cookie de sesión, que es
 * justamente lo que un sitio de terceros consigue que el navegador mande solo.
 * Hoy la cookie es SameSite=Lax y eso ya frena el caso normal, pero es una sola
 * barrera y depende del navegador. El token ya se generaba en sesión, solo
 * faltaba comprobarlo.
 *
 * El frontend lo manda en la cabecera X-CSRF-Token; index.php lo deja en un
 * <meta name="csrf-token"> para que app.js lo lea.
 */
function requireCsrf() {
    $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $enviado)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Token CSRF no válido']);
        exit;
    }
}

/**
 * Freno a la fuerza bruta.
 *
 * El panel tiene UNA contraseña, sin usuario ni segundo factor, y hasta ahora
 * un intento fallido costaba un sleep(1) fijo: a ese ritmo son ~86.000 pruebas
 * al día desde cualquier equipo de la red. Ahora la espera crece sola con cada
 * fallo seguido y se borra al acertar.
 *
 * La cuenta es GLOBAL, no por IP, a propósito: detrás del proxy la IP sale de
 * una cabecera que el cliente puede escribir, así que contar por IP sería
 * contar lo que diga el atacante. Y como la espera crece en vez de bloquear,
 * nadie puede dejar al dueño fuera machacando el formulario.
 *
 * Se guarda igual que los tokens de "Recordarme": un fichero en includes/,
 * que el .htaccess no sirve, con un die() delante por si acaso.
 */
define('LOGIN_FALLOS_GRATIS', 3);
define('LOGIN_ESPERA_MAXIMA', 30);

function loginFallos() {
    $file = __DIR__ . '/.fallos.php';
    if (!file_exists($file)) return 0;
    $json = str_replace("<?php die(); ?>\n", "", file_get_contents($file));
    $datos = json_decode($json, true) ?: [];
    return (int)($datos['seguidos'] ?? 0);
}

function loginApuntarIntento($acertado) {
    $file = __DIR__ . '/.fallos.php';
    $seguidos = $acertado ? 0 : loginFallos() + 1;
    file_put_contents($file, "<?php die(); ?>\n" . json_encode([
        'seguidos' => $seguidos,
        'ultimo'   => time(),
    ]));
}

function loginEspera() {
    $fallos = loginFallos();
    if ($fallos < LOGIN_FALLOS_GRATIS) return 0;
    return (int)min(pow(2, $fallos - LOGIN_FALLOS_GRATIS), LOGIN_ESPERA_MAXIMA);
}

/**
 * Procesa login.
 */
function handleLogin($adminPassword) {
    if (!isset($_POST['login'])) return "";
    
    if (!$adminPassword) {
        return "Sistema no configurado. Contacta al administrador.";
    }
    
    // Validar CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Error de validación CSRF");
    }

    // La espera va ANTES de comprobar nada, así el coste lo paga el intento
    // acierte o no, y el tiempo de respuesta no delata si iba bien encaminada.
    $espera = loginEspera();
    if ($espera > 0) sleep($espera);

    // Comprobar contraseña (soporta hash y texto plano para facilitar migración)
    $isValid = false;
    if (strpos($adminPassword, '$2y$') === 0) {
        $isValid = password_verify($_POST['password'], $adminPassword);
    } else {
        $isValid = ($_POST['password'] === $adminPassword);
    }

    loginApuntarIntento($isValid);

    if ($isValid) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        
        // Si marcó "Recordarme", crear token persistente
        if (isset($_POST['remember'])) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + (30 * 24 * 60 * 60); // 30 días
            
            $tokens = loadTokens();
            $tokens[] = [
                'token_hash' => hash('sha256', $token),
                'expires' => $expires,
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ];
            saveTokens($tokens);
            
            setcookie('remember_me', $token, [
                'expires' => $expires,
                'path' => '/',
                'secure' => (isset($_SERVER['HTTPS']) || isset($_SERVER['HTTP_X_FORWARDED_PROTO'])),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        header("Location: index.php");
        exit;
    } else {
        error_log("[auth] login fallido (" . loginFallos() . " seguidos)");
        return "Contraseña incorrecta";
    }
}

/**
 * Middleware para requerir autenticación en APIs.
 */
function requireAuth() {
    if (!isAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesión expirada o no autenticado']);
        exit;
    }
}
