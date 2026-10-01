<?php
/**
 * Panel de reservas de Holidu
 * Vista principal (solo HTML + includes)
 */
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pocketbase.php';
require_once __DIR__ . '/includes/fianza.php';
require_once __DIR__ . '/includes/notas.php';

// Manejar logout/login
handleLogout();
$error = handleLogin($ADMIN_PASSWORD);

// Cargar datos si autenticado
$bookings = [];
if (isAuthenticated()) {
    $bookings = getPocketbaseData($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
    $bookings = categorizeBookings($bookings);
    // Cuántas notas tiene cada huésped y si repite. Va aquí y en
    // api/get_data.php, que es lo que consume el refresco por JS.
    $bookings = anotarNotas($bookings, notasPorHuesped($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($NOMBRE_ALOJAMIENTO) ?> - Panel Admin</title>
    <!-- Para que app.js pueda mandarlo en X-CSRF-Token en todo lo que escribe -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <!-- Lo usa app.js para componer el mensaje del formulario -->
    <meta name="alojamiento" content="<?= htmlspecialchars($NOMBRE_ALOJAMIENTO) ?>">
    <meta name="mensaje-formulario" content="<?= htmlspecialchars($MENSAJE_FORMULARIO) ?>">
    <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>

<?php if (!isAuthenticated()): ?>
    <div class="login-card">
        <h1>🔐 <?= htmlspecialchars($NOMBRE_ALOJAMIENTO) ?></h1>
        <p style="color: #666; margin-bottom: 2rem;">Panel de Administracion</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="password" name="password" placeholder="Contraseña" required autofocus>
            <label class="remember-me">
                <input type="checkbox" name="remember"> Recordarme por 30 días
            </label>
            <button type="submit" name="login" class="btn btn-primary">Acceder</button>
        </form>
        <?php if($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📅 Reservas <?= htmlspecialchars($NOMBRE_ALOJAMIENTO) ?></h1>
            <div class="header-actions">
                <button onclick="openHoliduLogin()" class="btn-holidu-mini" title="Iniciar sesión en Holidu (necesario cuando caduca la sesión para poder sincronizar)">🔑 Holidu</button>
                <button onclick="syncData()" id="syncBtn" class="btn btn-success">🔄 Sincronizar</button>
                <button onclick="toggleLogs()" class="btn btn-logs">📋 Logs</button>
                <a href="?logout=1" class="btn btn-danger">🚪 Salir</a>
            </div>
        </div>
        
        <div id="alertContainer"></div>
        
        <!-- Logs Panel -->
        <div class="logs-panel" id="logsPanel">
            <button class="logs-close" onclick="closeLogs()">✕</button>
            <pre>No hay logs disponibles.</pre>
        </div>

        <!-- Selector de vista -->
        <div class="vista-selector" role="tablist">
            <button type="button" class="vista-btn activa" id="vistaBtnTabla" onclick="cambiarVista('tabla')">📋 Tabla</button>
            <button type="button" class="vista-btn" id="vistaBtnCalendario" onclick="cambiarVista('calendario')">📅 Calendario</button>
        </div>

        <!-- Calendario de ocupacion (lo pinta app.js) -->
        <div class="calendario-container" id="vistaCalendario" hidden>
            <div class="cal-cabecera">
                <button type="button" class="cal-nav" onclick="moverMes(-1)" aria-label="Mes anterior">‹</button>
                <h2 id="calTitulo"></h2>
                <button type="button" class="cal-nav" onclick="moverMes(1)" aria-label="Mes siguiente">›</button>
                <button type="button" class="cal-hoy" onclick="moverMes(0)">Hoy</button>
            </div>
            <div class="cal-resumen" id="calResumen"></div>
            <div class="cal-rejilla" id="calRejilla"></div>
            <div class="cal-leyenda">
                <span><i class="canal-BOOKINGCOM"></i>Booking</span>
                <span><i class="canal-AIRBNB"></i>Airbnb</span>
                <span><i class="canal-HOLIDU"></i>Holidu</span>
                <span><i class="canal-OTRO"></i>Otros</span>
                <span class="cal-ayuda">Cada día: mitad izquierda = noche anterior, mitad derecha = esa noche</span>
            </div>
        </div>

        <!-- Tabla de reservas -->
        <div class="table-container" id="vistaTabla">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Huesped</th>
                        <th>Telefono</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Pagado</th>
                        <th>Ganancia</th>
                        <th>Estado</th>
                        <th>Fianza</th>
                        <th>Formulario</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (isset($bookings['error'])): ?>
                        <tr><td colspan="10" style="text-align:center;color:red;">❌ <?= htmlspecialchars($bookings['error']) ?></td></tr>
                    <?php elseif (empty($bookings)): ?>
                        <tr><td colspan="10" style="text-align:center;color:#666;">No hay reservas disponibles</td></tr>
                    <?php else: ?>
                        <?php 
                        $today = strtotime('today');
                        $nextFound = false;
                        $pastShown = false;
                        $cancelledShown = false;
                        
                        $pastCount = count(array_filter($bookings, function($b) use ($today) {
                            return strtotime($b['Check-Out'] ?? '9999-12-31') < $today && strpos($b['Estado'] ?? '', 'CANCEL') === false;
                        }));
                        $cancelledCount = count(array_filter($bookings, function($b) {
                            return strpos($b['Estado'] ?? '', 'CANCEL') !== false;
                        }));
                        
                        foreach ($bookings as $b):
                            $checkIn = strtotime($b['Check-in'] ?? '9999-12-31');
                            $checkOut = strtotime($b['Check-Out'] ?? '9999-12-31');
                            $estado = $b['Estado'] ?? '';
                            $isCancelled = strpos($estado, 'CANCEL') !== false;
                            $isPast = !$isCancelled && $checkOut < $today;
                            $isActive = !$isCancelled && !$isPast;
                            $isNext = $isActive && !$nextFound && $checkIn >= $today;
                            if ($isNext) $nextFound = true;
                            
                            if ($isPast && !$pastShown):
                                $pastShown = true;
                        ?>
                            <tr class="section-row"><td colspan="10">📦 RESERVAS PASADAS (<?= $pastCount ?>)</td></tr>
                        <?php elseif ($isCancelled && !$cancelledShown):
                                $cancelledShown = true;
                        ?>
                            <tr class="section-row"><td colspan="10">❌ RESERVAS CANCELADAS (<?= $cancelledCount ?>)</td></tr>
                        <?php endif; ?>
                        
                        <?php
                            $rowClass = $isNext ? 'row-highlight' : ($isPast || $isCancelled ? 'row-faded' : '');
                            $badgeClass = $isCancelled ? 'badge-cancelled' : (strpos($estado, 'CONFIRMED') !== false ? 'badge-confirmed' : 'badge-active');
                            $formClass = ($b['Formulario'] ?? '') === 'Enviado' ? 'badge-confirmed' : 'badge-active';
                            $fianza = fianzaPresentacion($b);
                            // Cuando se hizo la reserva. Se marca 'reciente' si
                            // entro en los ultimos 7 dias, que es lo que hace
                            // falta para ver de un vistazo lo que acaba de caer.
                            $fechaReserva = !empty($b['Fecha reserva']) ? strtotime($b['Fecha reserva']) : 0;
                            $reservaReciente = $fechaReserva && $fechaReserva >= strtotime('-7 days', $today);
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <td data-label="ID">
                                    <?= htmlspecialchars($b['ID_Reserva'] ?? '-') ?>
                                    <?php if ($isNext): ?><span class="next-badge">🔜 PROXIMA</span><?php endif; ?>
                                    <?php if ($fechaReserva): ?>
                                        <div class="celda-detalle<?= $reservaReciente ? ' reciente' : '' ?>" title="Cuando se hizo la reserva">
                                            <?= $reservaReciente ? '🆕' : '📅' ?> reservada el <?= date('d/m/Y', $fechaReserva) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Huesped">
                                    <button type="button" class="celda-boton celda-huesped"
                                        title="Notas de este huésped"
                                        data-id="<?= htmlspecialchars($b['ID_Reserva'] ?? '', ENT_QUOTES) ?>"
                                        data-clave="<?= htmlspecialchars($b['Huesped clave'] ?? '', ENT_QUOTES) ?>"
                                        data-nombre="<?= htmlspecialchars($b['Nombre_huesped'] ?? '', ENT_QUOTES) ?>"
                                        onclick="abrirNotas(this)">
                                        <span class="huesped-nombre"><?= htmlspecialchars($b['Nombre_huesped'] ?: '-') ?></span>
                                        <?php if (($b['Notas'] ?? 0) > 0): ?>
                                            <span class="badge badge-nota">📝 <?= (int) $b['Notas'] ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <?php if (!empty($b['Se alojó antes'])): ?>
                                        <div class="celda-detalle">🔁 ya se alojó antes</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Telefono"><?= htmlspecialchars($b['Número de teléfono'] ?? '-') ?></td>
                                <td data-label="Check-in"><?= date('d/m/Y', strtotime($b['Check-in'] ?? 'now')) ?></td>
                                <td data-label="Check-out"><?= date('d/m/Y', strtotime($b['Check-Out'] ?? 'now')) ?></td>
                                <td data-label="Pagado"><?= htmlspecialchars($b['Pagado'] ?? '-') ?> €</td>
                                <td data-label="Ganancia"><?= htmlspecialchars($b['Ganancia'] ?? '-') ?> €</td>
                                <td data-label="Estado"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($estado) ?></span></td>
                                <td data-label="Fianza">
                                    <?php if ($fianza['aplica']): ?>
                                        <button type="button" class="celda-boton"
                                            title="Editar fianza"
                                            data-id="<?= htmlspecialchars($b['ID_Reserva'] ?? '', ENT_QUOTES) ?>"
                                            data-nombre="<?= htmlspecialchars($b['Nombre_huesped'] ?? '', ENT_QUOTES) ?>"
                                            data-importe="<?= htmlspecialchars($b['Fianza'] ?? '', ENT_QUOTES) ?>"
                                            data-estado="<?= htmlspecialchars(fianzaEstado($b), ENT_QUOTES) ?>"
                                            data-retenido="<?= htmlspecialchars($b['Fianza retenido'] ?? '', ENT_QUOTES) ?>"
                                            data-nota="<?= htmlspecialchars($b['Fianza nota'] ?? '', ENT_QUOTES) ?>"
                                            onclick="abrirFianza(this)">
                                            <span class="badge <?= $fianza['clase'] ?>"><?= htmlspecialchars($fianza['texto']) ?></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="badge <?= $fianza['clase'] ?>"><?= htmlspecialchars($fianza['texto']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($fianza['detalle'] !== ''): ?>
                                        <div class="celda-detalle"><?= htmlspecialchars($fianza['detalle']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Formulario">
                                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                        <button type="button" class="celda-boton"
                                            title="Marcar como enviado / no enviado"
                                            data-id="<?= htmlspecialchars($b['ID_Reserva'] ?? '', ENT_QUOTES) ?>"
                                            onclick="alternarFormulario(this)">
                                            <span class="badge <?= $formClass ?>"><?= htmlspecialchars($b['Formulario'] ?: 'No enviado') ?></span>
                                        </button>
                                        <button type="button" class="btn btn-secondary" style="padding: 2px 8px; font-size: 0.75rem; margin-left: 5px;" title="Copiar mensaje" data-nombre="<?= htmlspecialchars($b['Nombre_huesped'] ?? 'Cliente', ENT_QUOTES) ?>" data-link="<?= htmlspecialchars($b['Link formulario'] ?? '', ENT_QUOTES) ?>" onclick="copyFormText(this)">📋</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Modal Login Holidu -->
    <div class="holidu-overlay" id="holiduModal">
        <div class="holidu-modal">
            <button class="holidu-close" onclick="closeHoliduLogin()">✕</button>
            <h2>🔑 Sesión de Holidu</h2>
            <p class="holidu-desc">Inicia sesión en Holidu para poder sincronizar reservas. Solo hace falta cuando la sesión ha caducado.</p>

            <div id="holiduStatus" class="holidu-status"></div>

            <div id="holiduStartBox">
                <button onclick="startHoliduLogin()" id="holiduStartBtn" class="btn btn-primary">Iniciar sesión</button>
            </div>

            <div id="holidu2faBox" style="display:none;">
                <label for="holiduCodeInput">Código de verificación (enviado a tu correo):</label>
                <input type="text" id="holiduCodeInput" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="Ej: 123456"
                       onkeydown="if(event.key==='Enter'){submitHoliduCode();}">
                <button onclick="submitHoliduCode()" id="holiduCodeBtn" class="btn btn-primary">Verificar código</button>
            </div>
        </div>
    </div>

    <!-- Modal Fianza -->
    <div class="holidu-overlay" id="fianzaModal">
        <div class="holidu-modal">
            <button class="holidu-close" onclick="cerrarFianza()">✕</button>
            <h2>💶 Fianza</h2>
            <p class="holidu-desc" id="fianzaDesc"></p>

            <div id="fianzaStatus" class="holidu-status"></div>

            <label for="fianzaEstadoSel">Estado</label>
            <select id="fianzaEstadoSel" onchange="alternarRetenido()">
                <option value="pendiente">Pendiente — aún no la ha pagado</option>
                <option value="pagada">Pagada — la tenemos nosotros</option>
                <option value="devuelta">Devuelta — se le devolvió entera</option>
                <option value="retenida">Retenida — nos quedamos con una parte o con todo</option>
            </select>

            <div id="fianzaRetenidoBox" style="display:none;">
                <label for="fianzaRetenidoInput">Importe que NO se devuelve (€)</label>
                <input type="number" id="fianzaRetenidoInput" min="0" step="0.01" placeholder="Ej: 120">

                <label for="fianzaNotaInput">Motivo</label>
                <input type="text" id="fianzaNotaInput" maxlength="200" placeholder="Ej: rotura de la mampara del baño">
            </div>

            <button onclick="guardarFianza()" id="fianzaGuardarBtn" class="btn btn-primary">Guardar</button>
        </div>
    </div>

    <!-- Modal Notas del huesped -->
    <div class="holidu-overlay" id="notasModal">
        <div class="holidu-modal notas-modal">
            <button class="holidu-close" onclick="cerrarNotas()">✕</button>
            <h2>📝 Notas</h2>
            <p class="holidu-desc" id="notasDesc"></p>

            <div id="notasStatus" class="holidu-status"></div>

            <div id="notasLista" class="notas-lista"></div>

            <label for="notasTexto">Apuntar una nota nueva</label>
            <textarea id="notasTexto" class="notas-texto" maxlength="1000" rows="3"
                      placeholder="Ej: muy cuidadoso, dejó todo impecable"></textarea>
            <button onclick="guardarNota()" id="notasGuardarBtn" class="btn btn-primary">Añadir nota</button>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <h2>Sincronizando...</h2>
            <p>Esto puede tardar unos segundos</p>
        </div>
    </div>
    
    <!-- filemtime y no time(): con el reloj el navegador se baja app.js entero
         en cada visita aunque no haya cambiado nada. Igual que el CSS. -->
    <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
<?php endif; ?>

</body>
</html>