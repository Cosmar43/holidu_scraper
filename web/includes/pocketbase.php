<?php
/**
 * Cliente PocketBase
 */

/**
 * Deja un valor en algo que se puede meter entre comillas en un filtro de
 * PocketBase.
 *
 * Los filtros se construyen concatenando strings —"(id_reserva='$id')"— así que
 * una comilla suelta en $id cambia la cláusula. No es solo un 500: puede hacer
 * que una escritura (marcar una fianza, cambiar el formulario, guardar una
 * nota) caiga sobre OTRA reserva. Los ids de Holidu son números y las claves de
 * huésped son dígitos o 'r:{id}', así que esto no recorta nada legítimo.
 */
function valorFiltroSeguro($valor) {
    return preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $valor);
}

function getPocketbaseAuthToken($url, $email, $password) {
    // Caché para lo que dure la petición. index.php y api/get_data.php piden
    // primero las reservas y luego las notas, y cada una autenticaba por su
    // cuenta: dos POST /auth-with-password bloqueantes en cada carga de la
    // página y en cada refresco de la tabla. El token vale para toda la
    // petición, así que basta con no volver a pedirlo.
    static $cache = [];
    $clave = md5($url . '|' . $email);
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }

    $authUrl = "$url/api/collections/_superusers/auth-with-password";

    $data = json_encode([
        'identity' => $email,
        'password' => $password
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $data,
            'timeout' => 30
        ]
    ]);
    
    $response = @file_get_contents($authUrl, false, $context);
    
    if ($response === false) {
        return null;
    }
    
    $responseData = json_decode($response, true);
    $token = $responseData['token'] ?? null;
    if ($token) {
        $cache[$clave] = $token;
    }
    return $token;
}

function getPocketbaseData($url, $email, $password) {
    $token = getPocketbaseAuthToken($url, $email, $password);
    
    if (!$token) {
        return ['error' => 'No se pudo autenticar con PocketBase'];
    }

    $allRows = [];
    $page = 1;
    $perPage = 500;
    
    while (true) {
        $nextUrl = "$url/api/collections/reservas/records?page=$page&perPage=$perPage";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: $token\r\n",
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($nextUrl, false, $context);
        
        if ($response === false) {
            $err = error_get_last();
            return ['error' => "PocketBase Error: " . ($err['message'] ?? 'Unknown') . " (URL: $nextUrl)"];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['items'])) {
            return ['error' => "Error al decodificar respuesta de PocketBase"];
        }
        
        $items = $data['items'];
        
        // Mapear campos de PocketBase a la estructura antigua del frontend
        foreach ($items as $item) {
            $allRows[] = [
                'id' => $item['id'], // ID interno de PocketBase
                'ID_Reserva' => $item['id_reserva'] ?? '',
                'Nombre_huesped' => $item['nombre_huesped'] ?? '',
                'Correo electrónico' => $item['correo_electronico'] ?? '',
                'Número de teléfono' => $item['numero_de_telefono'] ?? '',
                'Check-in' => $item['check_in'] ?? '',
                'Check-Out' => $item['check_out'] ?? '',
                // Cuando se hizo la reserva (ISO con huso, tal cual la da
                // Holidu). Sirve para ver de un vistazo cuáles son las más
                // recientes.
                'Fecha reserva' => $item['fecha_reserva'] ?? '',
                'Pagado' => $item['pagado'] ?? '',
                'Ganancia' => $item['ganancia'] ?? '',
                'Estado' => $item['estado'] ?? '',
                'Link formulario' => $item['link_formulario'] ?? '',
                'Formulario' => $item['formulario'] ?? '',
                // Fianza: el importe y quién la gestiona vienen de Holidu; el
                // estado, lo retenido y la nota se rellenan a mano en el panel.
                'Fianza' => $item['fianza'] ?? '',
                'Fianza gestiona' => $item['fianza_gestiona'] ?? '',
                'Canal' => $item['canal'] ?? '',
                'Fianza estado' => $item['fianza_estado'] ?? '',
                'Fianza retenido' => $item['fianza_retenido'] ?? '',
                'Fianza nota' => $item['fianza_nota'] ?? ''
            ];
        }
        
        if ($page >= ($data['totalPages'] ?? 1) || count($items) == 0) {
            break;
        }
        $page++;
    }
    
    return $allRows;
}

/**
 * Categoriza y ordena reservas: activas, pasadas, canceladas.
 */
function categorizeBookings($bookings) {
    if (isset($bookings['error']) || empty($bookings)) {
        return $bookings;
    }
    
    $today = strtotime('today');
    $active = [];
    $past = [];
    $cancelled = [];
    
    foreach ($bookings as $booking) {
        $estado = $booking['Estado'] ?? '';
        $checkOut = strtotime($booking['Check-Out'] ?? '9999-12-31');
        
        if (strpos($estado, 'CANCEL') !== false) {
            $cancelled[] = $booking;
        } elseif ($checkOut < $today) {
            $past[] = $booking;
        } else {
            $active[] = $booking;
        }
    }
    
    $sortByCheckIn = function($a, $b) {
        $dateA = strtotime($a['Check-in'] ?? '9999-12-31');
        $dateB = strtotime($b['Check-in'] ?? '9999-12-31');
        return $dateA - $dateB;
    };
    
    usort($active, $sortByCheckIn);
    usort($past, $sortByCheckIn);
    usort($cancelled, $sortByCheckIn);
    
    return array_merge($active, $past, $cancelled);
}