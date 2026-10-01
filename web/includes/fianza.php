<?php
/**
 * Lógica de la fianza, compartida entre la vista y la API.
 *
 * Reparto de campos (ver también sync_holidu_pocketbase.py):
 *   - 'Fianza' y 'Fianza gestiona' vienen de Holidu, no se editan a mano.
 *   - 'Fianza estado', 'Fianza retenido' y 'Fianza nota' se rellenan aquí.
 *
 * El "no aplica" NO se guarda: se deduce. Solo nos toca cobrar la fianza si
 * Holidu dice que la gestionamos nosotros y el importe es mayor que cero. Las
 * reservas de Airbnb traen importe igual que las demás, pero las retiene el
 * canal, así que para nosotros no existen.
 */

const FIANZA_ESTADOS = ['pendiente', 'pagada', 'devuelta', 'retenida'];

/** ¿Nos toca cobrar la fianza de esta reserva? */
function fianzaAplica($booking) {
    $gestiona = $booking['Fianza gestiona'] ?? '';
    $importe = (float) str_replace(',', '.', $booking['Fianza'] ?? '0');
    return $gestiona === 'nosotros' && $importe > 0;
}

/** Importe para mostrar: se guarda con punto, se enseña con coma. */
function fianzaImporte($valor) {
    return str_replace('.', ',', (string) $valor);
}

/** Estado normalizado. Sin valor guardado, una fianza que aplica está pendiente. */
function fianzaEstado($booking) {
    $estado = $booking['Fianza estado'] ?? '';
    return in_array($estado, FIANZA_ESTADOS, true) ? $estado : 'pendiente';
}

/**
 * Cómo se pinta la celda: texto, clase de insignia y detalle bajo ella.
 */
function fianzaPresentacion($booking) {
    if (!fianzaAplica($booking)) {
        // Si no la cobramos nosotros, la explicación útil es de dónde viene la
        // reserva: Holidu no incluye la fianza en las de Airbnb porque la
        // gestiona el propio canal.
        $canal = $booking['Canal'] ?? '';
        $laGestionaOtro = ($booking['Fianza gestiona'] ?? '') !== 'nosotros';
        return [
            'aplica'  => false,
            'texto'   => 'No aplica',
            'clase'   => 'badge-neutral',
            'detalle' => ($laGestionaOtro && $canal !== '')
                ? 'la gestiona ' . ucfirst(strtolower($canal))
                : '',
        ];
    }

    $estado = fianzaEstado($booking);
    $importe = $booking['Fianza'] ?? '';
    $retenido = $booking['Fianza retenido'] ?? '';

    $clases = [
        'pendiente' => 'badge-warning',
        'pagada'    => 'badge-confirmed',
        'devuelta'  => 'badge-active',
        'retenida'  => 'badge-cancelled',
    ];

    $detalle = '';
    if ($estado === 'retenida' && $retenido !== '') {
        $detalle = 'retenidos ' . fianzaImporte($retenido) . ' €';
    }

    return [
        'aplica'  => true,
        'texto'   => fianzaImporte($importe) . ' € · ' . $estado,
        'clase'   => $clases[$estado],
        'detalle' => $detalle,
    ];
}
