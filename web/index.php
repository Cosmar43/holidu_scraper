<?php
/**
 * Panel de reservas de Holidu
 * Vista principal: pantalla de login o la estructura del panel. Las reservas
 * las pinta SOLO app.js (antes las pintaban PHP y JS por duplicado): aqui se
 * dejan incrustadas en un <script type="application/json"> para que salgan sin
 * esperar a una peticion.
 */
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pocketbase.php';
require_once __DIR__ . '/includes/notas.php';

// Manejar logout/login
handleLogout();
$error = handleLogin($ADMIN_PASSWORD);

$autenticado = isAuthenticated();
$bookings = [];
$ultimoSync = null;
if ($autenticado) {
    $bookings = getPocketbaseData($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD);
    $bookings = categorizeBookings($bookings);
    // Cuántas notas tiene cada huésped y si repite. Igual que api/get_data.php.
    if (!isset($bookings['error'])) {
        $bookings = anotarNotas($bookings, notasPorHuesped($POCKETBASE_URL, $POCKETBASE_ADMIN_EMAIL, $POCKETBASE_ADMIN_PASSWORD));
    }
    $marca = __DIR__ . '/includes/.ultimo_sync.json';
    if (is_readable($marca)) {
        $ultimoSync = json_decode((string) file_get_contents($marca), true);
    }
}

// JSON seguro dentro de <script>: sin </script>, & ni comillas que escapen.
$jsonSeguro = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$nombre = htmlspecialchars($NOMBRE_ALOJAMIENTO);
$version = fn($f) => @filemtime(__DIR__ . '/assets/' . $f);

/** Icono del sprite. */
function ic($id, $clase = '') {
    return '<svg class="icono ' . $clase . '" aria-hidden="true"><use href="#i-' . $id . '"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $nombre ?> · Reservas</title>
    <meta name="theme-color" content="#f4f6f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0e1116" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <!-- Lo usa app.js: CSRF en todo lo que escribe, nombre y plantilla del mensaje -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <meta name="alojamiento" content="<?= $nombre ?>">
    <meta name="mensaje-formulario" content="<?= htmlspecialchars($MENSAJE_FORMULARIO) ?>">
    <script>
        // Tema elegido, antes de pintar nada (sin parpadeo).
        try { const t = localStorage.getItem('tema'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
    </script>
    <link rel="stylesheet" href="assets/style.css?v=<?= $version('style.css') ?>">
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-casa" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></symbol>
    <symbol id="i-sync" viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></symbol>
    <symbol id="i-calendario" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
    <symbol id="i-lista" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
    <symbol id="i-buscar" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
    <symbol id="i-sol" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></symbol>
    <symbol id="i-luna" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
    <symbol id="i-mas" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></symbol>
    <symbol id="i-llave" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/></symbol>
    <symbol id="i-registro" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></symbol>
    <symbol id="i-salir" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
    <symbol id="i-copiar" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
    <symbol id="i-whatsapp" viewBox="0 0 24 24"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M9 10.5c.5 1.6 1.9 3 3.5 3.5l1-1 2 .8v1.2a1 1 0 0 1-1 1A6.5 6.5 0 0 1 8 9.5a1 1 0 0 1 1-1h1.2l.8 2Z"/></symbol>
    <symbol id="i-nota" viewBox="0 0 24 24"><path d="M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3Z"/><path d="M15 3v6h6"/></symbol>
    <symbol id="i-telefono" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>
    <symbol id="i-correo" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></symbol>
    <symbol id="i-cerrar" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
    <symbol id="i-ok" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
    <symbol id="i-izq" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
    <symbol id="i-der" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
    <symbol id="i-enlace" viewBox="0 0 24 24"><path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
    <symbol id="i-alerta" viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></symbol>
    <symbol id="i-repite" viewBox="0 0 24 24"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></symbol>
    <symbol id="i-ojo" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-cartera" viewBox="0 0 24 24"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></symbol>
    <symbol id="i-euro" viewBox="0 0 24 24"><path d="M4 10h12M4 14h9M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/></symbol>
    <symbol id="i-lapiz" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></symbol>
    <symbol id="i-papelera" viewBox="0 0 24 24"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
    <symbol id="i-candado" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
    <symbol id="i-cama" viewBox="0 0 24 24"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></symbol>
    <symbol id="i-tareas" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></symbol>
</svg>

<?php if (!$autenticado): ?>
    <main class="login-pagina">
        <div class="login-card">
            <div class="login-logo"><?= ic('casa') ?></div>
            <h1><?= $nombre ?></h1>
            <p class="sub">Panel de reservas</p>
            <form method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <?php if ($error): ?>
                    <div class="login-error" role="alert"><?= ic('alerta') ?><span><?= htmlspecialchars($error) ?></span></div>
                <?php endif; ?>
                <div class="campo">
                    <label for="password">Contraseña</label>
                    <div class="input-con-boton">
                        <input class="input" type="password" id="password" name="password" required autofocus autocomplete="current-password">
                        <button type="button" class="btn-icono" aria-label="Mostrar contraseña"
                            onclick="const i=document.getElementById('password'); i.type = i.type === 'password' ? 'text' : 'password'; this.setAttribute('aria-pressed', i.type === 'text');"><?= ic('ojo') ?></button>
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="remember"> Recordarme 30 días en este dispositivo</label>
                <button type="submit" name="login" class="btn btn-primary btn-block" style="height:44px">Entrar</button>
            </form>
        </div>
    </main>

<?php else: ?>
    <header class="topbar">
        <div class="topbar-in">
            <div class="marca">
                <div class="marca-logo"><?= ic('casa') ?></div>
                <div class="marca-texto">
                    <h1><?= $nombre ?></h1>
                    <div class="estado-sync" id="estadoSync"><span class="punto"></span><span>…</span></div>
                </div>
            </div>
            <button type="button" class="btn btn-primary" id="btnSync" onclick="sincronizar()" title="Traer las reservas de Holidu">
                <?= ic('sync') ?><span class="btn-sync-texto">Sincronizar</span>
            </button>
            <button type="button" class="btn-icono" id="btnTema" onclick="cambiarTema()" aria-label="Cambiar tema" title="Cambiar tema claro / oscuro">
                <?= ic('luna') ?>
            </button>
            <div class="menu">
                <button type="button" class="btn-icono" id="btnMenu" aria-haspopup="true" aria-expanded="false" aria-label="Más opciones" onclick="alternarMenu()">
                    <?= ic('mas') ?>
                </button>
                <div class="menu-lista" id="menuLista" hidden role="menu">
                    <button type="button" role="menuitem" onclick="abrirHolidu()"><?= ic('llave') ?>Sesión de Holidu</button>
                    <button type="button" role="menuitem" onclick="abrirRegistro()"><?= ic('registro') ?>Registro de la última sincronización</button>
                    <div class="menu-sep"></div>
                    <a href="?logout=1" role="menuitem" class="peligro"><?= ic('salir') ?>Cerrar sesión</a>
                </div>
            </div>
        </div>
    </header>

    <main class="contenido">
        <section class="resumen" id="resumen" aria-label="Resumen"></section>

        <div class="herramientas">
            <div class="pestanas" role="tablist" id="pestanas" aria-label="Qué reservas ver">
                <button type="button" role="tab" class="pestana" data-filtro="proximas">Próximas <span class="contador" data-cuenta="proximas"></span></button>
                <button type="button" role="tab" class="pestana" data-filtro="pendientes">Por hacer <span class="contador alerta" data-cuenta="pendientes"></span></button>
                <button type="button" role="tab" class="pestana" data-filtro="pasadas">Pasadas <span class="contador" data-cuenta="pasadas"></span></button>
                <button type="button" role="tab" class="pestana" data-filtro="canceladas">Canceladas <span class="contador" data-cuenta="canceladas"></span></button>
            </div>
            <div class="buscador">
                <?= ic('buscar') ?>
                <label for="buscar" class="sr-only">Buscar reservas</label>
                <input class="input" type="search" id="buscar" placeholder="Buscar huésped, teléfono o ID" autocomplete="off">
                <kbd>/</kbd>
                <button type="button" class="btn-icono limpiar" aria-label="Borrar búsqueda" onclick="limpiarBusqueda()"><?= ic('cerrar', 'icono-sm') ?></button>
            </div>
            <div class="vistas" role="tablist" aria-label="Vista">
                <button type="button" role="tab" class="pestana" data-vista="lista" title="Lista"><?= ic('lista') ?><span class="sr-only">Lista</span></button>
                <button type="button" role="tab" class="pestana" data-vista="calendario" title="Calendario"><?= ic('calendario') ?><span class="sr-only">Calendario</span></button>
            </div>
        </div>

        <section class="panel" id="vistaLista" aria-live="polite">
            <table class="reservas">
                <thead>
                    <tr>
                        <th>Huésped</th>
                        <th>Estancia</th>
                        <th>Canal</th>
                        <th class="derecha">Ganancia</th>
                        <th>Fianza</th>
                        <th>Formulario</th>
                        <th><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody id="cuerpoTabla"></tbody>
            </table>
        </section>

        <section class="panel calendario" id="vistaCalendario" hidden>
            <div class="cal-cabecera">
                <button type="button" class="btn-icono" onclick="moverMes(-1)" aria-label="Mes anterior"><?= ic('izq') ?></button>
                <h2 id="calTitulo"></h2>
                <button type="button" class="btn-icono" onclick="moverMes(1)" aria-label="Mes siguiente"><?= ic('der') ?></button>
                <button type="button" class="btn" style="height:32px" onclick="moverMes(0)">Hoy</button>
                <div class="cal-resumen" id="calResumen"></div>
            </div>
            <div class="cal-rejilla" id="calRejilla"></div>
            <div class="cal-leyenda">
                <span><i class="punto fondo-BOOKINGCOM"></i>Booking.com</span>
                <span><i class="punto fondo-AIRBNB"></i>Airbnb</span>
                <span><i class="punto fondo-HOLIDU"></i>Holidu</span>
                <span><i class="punto fondo-OTRO"></i>Otros</span>
                <span class="cal-ayuda">Cada día: mitad izquierda = noche anterior · derecha = esa noche</span>
            </div>
        </section>
    </main>

    <div class="avisos" id="avisos" aria-live="polite"></div>

    <!-- Ficha de una reserva -->
    <dialog class="dialogo dialogo-ancho" id="dlgFicha" aria-labelledby="fichaNombre">
        <div class="dialogo-cab">
            <div>
                <h2 class="nombre" id="fichaNombre"></h2>
                <p id="fichaSub"></p>
            </div>
            <button type="button" class="btn-icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><?= ic('cerrar') ?></button>
        </div>
        <div class="dialogo-cuerpo" id="fichaCuerpo"></div>
        <div class="dialogo-pie" id="fichaPie"></div>
    </dialog>

    <!-- Fianza -->
    <dialog class="dialogo" id="dlgFianza" aria-labelledby="fianzaTitulo">
        <form method="dialog" onsubmit="guardarFianza(event)">
            <div class="dialogo-cab">
                <div>
                    <h2 id="fianzaTitulo">Fianza</h2>
                    <p id="fianzaDesc"></p>
                </div>
                <button type="button" class="btn-icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><?= ic('cerrar') ?></button>
            </div>
            <div class="dialogo-cuerpo">
                <div class="estado-msg" id="fianzaEstado" role="status"></div>
                <fieldset class="opciones">
                    <legend class="sr-only">Estado de la fianza</legend>
                    <label class="opcion"><input type="radio" name="fianza" value="pendiente"><div><strong>Pendiente</strong><span>Aún no la ha pagado</span></div></label>
                    <label class="opcion"><input type="radio" name="fianza" value="pagada"><div><strong>Pagada</strong><span>La tenemos nosotros</span></div></label>
                    <label class="opcion"><input type="radio" name="fianza" value="devuelta"><div><strong>Devuelta</strong><span>Se le devolvió entera</span></div></label>
                    <label class="opcion"><input type="radio" name="fianza" value="retenida"><div><strong>Retenida</strong><span>Nos quedamos con una parte o con todo</span></div></label>
                </fieldset>
                <div class="fila-campos" id="fianzaRetenida" hidden>
                    <div class="campo">
                        <label for="fianzaRetenido">No se devuelve (€)</label>
                        <input class="input num" type="number" id="fianzaRetenido" min="0" step="0.01" placeholder="120" inputmode="decimal">
                    </div>
                    <div class="campo">
                        <label for="fianzaNota">Motivo</label>
                        <input class="input" type="text" id="fianzaNota" maxlength="200" placeholder="Rotura de la mampara del baño">
                    </div>
                </div>
            </div>
            <div class="dialogo-pie">
                <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="fianzaGuardar">Guardar</button>
            </div>
        </form>
    </dialog>

    <!-- Notas del huesped -->
    <dialog class="dialogo" id="dlgNotas" aria-labelledby="notasTitulo">
        <div class="dialogo-cab">
            <div>
                <h2 id="notasTitulo">Notas del huésped</h2>
                <p id="notasDesc"></p>
            </div>
            <button type="button" class="btn-icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><?= ic('cerrar') ?></button>
        </div>
        <div class="dialogo-cuerpo">
            <div class="estado-msg" id="notasEstado" role="status"></div>
            <div class="notas-lista" id="notasLista"></div>
            <div class="campo">
                <label for="notasTexto" id="notasEtiqueta">Nueva nota</label>
                <textarea class="input" id="notasTexto" maxlength="1000" rows="3" placeholder="Muy cuidadoso, dejó todo impecable"></textarea>
            </div>
        </div>
        <div class="dialogo-pie">
            <button type="button" class="btn" id="notasCancelar" onclick="cancelarEdicionNota()" hidden>Cancelar edición</button>
            <button type="button" class="btn btn-primary" id="notasGuardar" onclick="guardarNota()">Añadir nota</button>
        </div>
    </dialog>

    <!-- Login de Holidu -->
    <dialog class="dialogo" id="dlgHolidu" aria-labelledby="holiduTitulo">
        <div class="dialogo-cab">
            <div>
                <h2 id="holiduTitulo">Sesión de Holidu</h2>
                <p>Solo hace falta cuando la sesión caduca y la sincronización falla.</p>
            </div>
            <button type="button" class="btn-icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><?= ic('cerrar') ?></button>
        </div>
        <div class="dialogo-cuerpo">
            <ol class="pasos">
                <li>Pulsa «Iniciar sesión»: el servidor entra en Holidu con las credenciales configuradas.</li>
                <li>Holidu te enviará un código a tu correo.</li>
                <li>Escríbelo aquí y listo.</li>
            </ol>
            <div class="estado-msg" id="holiduEstado" role="status"></div>
            <div class="campo" id="holidu2fa" hidden>
                <label for="holiduCodigo">Código de verificación</label>
                <input class="input codigo-2fa" type="text" id="holiduCodigo" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="······"
                       onkeydown="if (event.key === 'Enter') enviarCodigoHolidu();">
            </div>
        </div>
        <div class="dialogo-pie">
            <button type="button" class="btn btn-primary" id="holiduIniciar" onclick="iniciarHolidu()"><?= ic('llave') ?>Iniciar sesión</button>
            <button type="button" class="btn btn-primary" id="holiduEnviar" onclick="enviarCodigoHolidu()" hidden>Verificar código</button>
        </div>
    </dialog>

    <!-- Registro de la ultima sincronizacion -->
    <dialog class="dialogo dialogo-ancho" id="dlgRegistro" aria-labelledby="registroTitulo">
        <div class="dialogo-cab">
            <div>
                <h2 id="registroTitulo">Registro de la sincronización</h2>
                <p>Salida de la última sincronización lanzada desde este navegador.</p>
            </div>
            <button type="button" class="btn-icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><?= ic('cerrar') ?></button>
        </div>
        <div class="dialogo-cuerpo">
            <pre class="registro" id="registroTexto"></pre>
        </div>
    </dialog>

    <script type="application/json" id="datosIniciales"><?= json_encode(['reservas' => $bookings, 'ultimoSync' => $ultimoSync], $jsonSeguro) ?></script>
    <script src="assets/app.js?v=<?= $version('app.js') ?>"></script>
<?php endif; ?>

</body>
</html>
