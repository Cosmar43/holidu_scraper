/**
 * Panel de reservas de Holidu - JavaScript
 */

// El token CSRF que index.php deja en un <meta>. Va en TODO lo que cambia algo;
// las llamadas de solo lectura (get_data, notas_listar, login_status) no lo
// necesitan. Sin esto, la cookie de sesion era la unica barrera.
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

/** Cabeceras de una llamada que escribe: JSON + el token. */
function cabecerasEscritura() {
    return { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF };
}

// ---- Utilidades ----
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === '-') return '-';
    const date = new Date(dateStr);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}

// ---- Alertas ----
function showAlert(message, type) {
    const alertContainer = document.getElementById('alertContainer');
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';

    alertContainer.innerHTML = `<div class="alert ${alertClass}">${message}</div>`;

    setTimeout(() => { alertContainer.innerHTML = ''; }, 5000);
}

// ---- Loading ----
function showLoading(title, subtitle) {
    const overlay = document.getElementById('loadingOverlay');
    overlay.querySelector('h2').textContent = title || 'Procesando...';
    overlay.querySelector('p').textContent = subtitle || 'Esto puede tardar unos segundos';
    overlay.classList.add('active');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}

// ---- Logs ----
let lastSyncOutput = '';

function toggleLogs() {
    const panel = document.getElementById('logsPanel');

    if (panel.classList.contains('active')) {
        panel.classList.remove('active');
        return;
    }

    if (!lastSyncOutput) {
        panel.querySelector('pre').textContent = 'No hay logs disponibles. Ejecuta una sincronizacion primero.';
    } else {
        panel.querySelector('pre').textContent = lastSyncOutput;
    }

    panel.classList.add('active');
}

function closeLogs() {
    document.getElementById('logsPanel').classList.remove('active');
}

// ---- Sincronizacion ----
function syncData() {
    const syncBtn = document.getElementById('syncBtn');

    showLoading('Sincronizando...', 'Conectando con Holidu y PocketBase');
    syncBtn.disabled = true;
    document.getElementById('alertContainer').innerHTML = '';

    fetch('api/sync.php', { headers: { 'X-CSRF-Token': CSRF } })
        .then(response => response.json())
        .then(data => {
            lastSyncOutput = data.output || '';

            if (data.success) {
                showAlert('✅ Sincronizacion completada correctamente', 'success');
                refreshTable();
            } else {
                let msg = '❌ Error en la sincronizacion';
                if (data.output) msg += ': ' + data.output.substring(0, 200);
                showAlert(msg, 'error');
            }
        })
        .catch(error => {
            showAlert('❌ Error: ' + error.message, 'error');
        })
        .finally(() => {
            hideLoading();
            syncBtn.disabled = false;
        });
}

// ---- Refrescar tabla ----
function refreshTable() {
    fetch('api/get_data.php')
        .then(response => response.json())
        .then(bookings => {
            const tbody = document.getElementById('tableBody');

            if (bookings.error) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:red;">❌ ${bookings.error}</td></tr>`;
                return;
            }

            RESERVAS = bookings;
            if (VISTA === 'calendario') pintarCalendario();

            if (bookings.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:#666;">No hay reservas disponibles</td></tr>`;
                return;
            }

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const active = [], past = [], cancelled = [];

            bookings.forEach(b => {
                const estado = b['Estado'] || '';
                const checkOut = new Date(b['Check-Out'] || '9999-12-31');

                if (estado.includes('CANCEL')) cancelled.push(b);
                else if (checkOut < today) past.push(b);
                else active.push(b);
            });

            const sortFn = (a, b) => new Date(a['Check-in'] || '9999-12-31') - new Date(b['Check-in'] || '9999-12-31');
            active.sort(sortFn);
            past.sort(sortFn);
            cancelled.sort(sortFn);

            const sorted = [...active, ...past, ...cancelled];
            let nextFound = false, pastShown = false, cancelledShown = false;
            let html = '';

            sorted.forEach(b => {
                const estado = b['Estado'] || 'UNKNOWN';
                const checkIn = new Date(b['Check-in'] || '9999-12-31');
                const checkOut = new Date(b['Check-Out'] || '9999-12-31');
                const isCancelled = estado.includes('CANCEL');
                const isPast = !isCancelled && checkOut < today;
                const isActive = !isCancelled && !isPast;
                const isNext = isActive && !nextFound && checkIn >= today;
                if (isNext) nextFound = true;

                // Separadores de seccion
                if (isPast && !pastShown) {
                    pastShown = true;
                    html += `<tr class="section-row"><td colspan="10">📦 RESERVAS PASADAS (${past.length})</td></tr>`;
                } else if (isCancelled && !cancelledShown) {
                    cancelledShown = true;
                    html += `<tr class="section-row"><td colspan="10">❌ RESERVAS CANCELADAS (${cancelled.length})</td></tr>`;
                }

                let badgeClass = 'badge-active';
                if (isCancelled) badgeClass = 'badge-cancelled';
                else if (estado.includes('CONFIRMED')) badgeClass = 'badge-confirmed';

                const rowClass = isNext ? 'row-highlight' : (isPast || isCancelled ? 'row-faded' : '');
                const nextBadge = isNext ? '<span class="next-badge">🔜 PROXIMA</span>' : '';
                const celdaFianza = pintarFianza(b);
                const celdaHuesped = pintarHuesped(b);
                const celdaFecha = pintarFechaReserva(b);

                html += `
                    <tr class="${rowClass}">
                        <td data-label="ID">${escapeHtml(b['ID_Reserva'] || '-')} ${nextBadge}${celdaFecha}</td>
                        <td data-label="Huesped">${celdaHuesped}</td>
                        <td data-label="Telefono">${escapeHtml(b['Número de teléfono'] || '-')}</td>
                        <td data-label="Check-in">${formatDate(b['Check-in'])}</td>
                        <td data-label="Check-out">${formatDate(b['Check-Out'])}</td>
                        <td data-label="Pagado">${escapeHtml(b['Pagado'] || '-')} €</td>
                        <td data-label="Ganancia">${escapeHtml(b['Ganancia'] || '-')} €</td>
                        <td data-label="Estado"><span class="badge ${badgeClass}">${escapeHtml(estado)}</span></td>
                        <td data-label="Fianza">${celdaFianza}</td>
                        <td data-label="Formulario">
                            <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                <button type="button" class="celda-boton" title="Marcar como enviado / no enviado"
                                    data-id="${escapeHtml(b['ID_Reserva'] || '')}" onclick="alternarFormulario(this)">
                                    <span class="badge ${b['Formulario'] === 'Enviado' ? 'badge-confirmed' : 'badge-active'}">${escapeHtml(b['Formulario'] || 'No enviado')}</span>
                                </button>
                                <button type="button" class="btn btn-secondary" style="padding: 2px 8px; font-size: 0.75rem; margin-left: 5px;" title="Copiar mensaje" data-nombre="${escapeHtml(b['Nombre_huesped'] || 'Cliente').replace(/"/g, '&quot;')}" data-link="${escapeHtml(b['Link formulario'] || '').replace(/"/g, '&quot;')}" onclick="copyFormText(this)">📋</button>
                            </div>
                        </td>
                    </tr>`;
            });

            tbody.innerHTML = html;
        });
}

// ---- Copiar texto Formulario ----
function copyFormText(btn) {
    const nombre = btn.getAttribute('data-nombre');
    const link = btn.getAttribute('data-link');
    if (!link) {
        showAlert('❌ Esta reserva no tiene un enlace de formulario', 'error');
        return;
    }
    // La plantilla y el nombre los pone index.php (variables MENSAJE_FORMULARIO
    // y NOMBRE_ALOJAMIENTO del entorno).
    const plantilla = document.querySelector('meta[name="mensaje-formulario"]')?.content || '{enlace}';
    const alojamiento = document.querySelector('meta[name="alojamiento"]')?.content || '';
    const text = plantilla
        .replaceAll('{nombre}', nombre)
        .replaceAll('{alojamiento}', alojamiento)
        .replaceAll('{enlace}', link);
    
    // Fallback for non-secure contexts if clipboard API fails
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showAlert('✅ Texto copiado al portapapeles', 'success');
        }).catch(err => {
            showAlert('❌ Error al copiar: ' + err, 'error');
        });
    } else {
        // Fallback for older browsers or local http
        let textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showAlert('✅ Texto copiado al portapapeles', 'success');
        } catch (err) {
            showAlert('❌ Error al copiar: ' + err, 'error');
        }
        textArea.remove();
    }
}


// ---- Cambiar Estado Formulario ----
// Se pulsa la insignia de la propia fila, igual que en la columna de fianza.
function alternarFormulario(btn) {
    btn.disabled = true;

    fetch('api/update_form_status.php', {
        method: 'POST',
        headers: cabecerasEscritura(),
        body: JSON.stringify({ bookings: [{ id: btn.dataset.id }] })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(`✅ ${data.message}`, 'success');
            refreshTable();
        } else {
            showAlert('❌ Error: ' + data.error, 'error');
        }
    })
    .catch(error => showAlert('❌ Error: ' + error.message, 'error'))
    .finally(() => { btn.disabled = false; });
}


// ---- Fianza ----
// Ojo: esta logica esta duplicada a proposito en includes/fianza.php, que es
// quien pinta la tabla en la carga inicial. Si cambia una, cambia la otra.
const FIANZA_CLASES = {
    pendiente: 'badge-warning',
    pagada: 'badge-confirmed',
    devuelta: 'badge-active',
    retenida: 'badge-cancelled'
};

function fianzaAplica(b) {
    const importe = parseFloat(String(b['Fianza'] || '0').replace(',', '.'));
    return b['Fianza gestiona'] === 'nosotros' && importe > 0;
}

// Se guarda con punto, se enseña con coma.
function fianzaImporte(valor) {
    return String(valor).replace('.', ',');
}

function pintarFianza(b) {
    if (!fianzaAplica(b)) {
        // Si no la cobramos nosotros, lo util es decir de donde viene la
        // reserva: en las de Airbnb la gestiona el propio canal.
        const canal = b['Canal'] || '';
        const detalle = (b['Fianza gestiona'] !== 'nosotros' && canal)
            ? `<div class="celda-detalle">la gestiona ${escapeHtml(canal.toLowerCase())}</div>`
            : '';
        return `<span class="badge badge-neutral">No aplica</span>${detalle}`;
    }

    const estado = FIANZA_CLASES[b['Fianza estado']] ? b['Fianza estado'] : 'pendiente';
    const retenido = b['Fianza retenido'] || '';
    const detalle = (estado === 'retenida' && retenido)
        ? `<div class="celda-detalle">retenidos ${escapeHtml(fianzaImporte(retenido))} €</div>`
        : '';

    const atributos = [
        ['id', b['ID_Reserva'] || ''],
        ['nombre', b['Nombre_huesped'] || ''],
        ['importe', b['Fianza'] || ''],
        ['estado', estado],
        ['retenido', retenido],
        ['nota', b['Fianza nota'] || '']
    ].map(([k, v]) => `data-${k}="${escapeHtml(String(v)).replace(/"/g, '&quot;')}"`).join(' ');

    return `<button type="button" class="celda-boton" title="Editar fianza" ${atributos} onclick="abrirFianza(this)">
                <span class="badge ${FIANZA_CLASES[estado]}">${escapeHtml(fianzaImporte(b['Fianza']))} € · ${estado}</span>
            </button>${detalle}`;
}

let fianzaReservaActual = null;

function setFianzaStatus(msg, type) {
    const el = document.getElementById('fianzaStatus');
    el.textContent = msg;
    el.className = 'holidu-status' + (type ? ' holidu-status-' + type : '');
}

function abrirFianza(btn) {
    fianzaReservaActual = btn.dataset.id;

    document.getElementById('fianzaDesc').textContent =
        `${btn.dataset.nombre} · reserva ${btn.dataset.id} · ${btn.dataset.importe} €`;
    document.getElementById('fianzaEstadoSel').value = btn.dataset.estado || 'pendiente';
    document.getElementById('fianzaRetenidoInput').value = btn.dataset.retenido || '';
    document.getElementById('fianzaNotaInput').value = btn.dataset.nota || '';

    setFianzaStatus('', '');
    alternarRetenido();
    document.getElementById('fianzaModal').classList.add('active');
}

function cerrarFianza() {
    document.getElementById('fianzaModal').classList.remove('active');
    fianzaReservaActual = null;
}

function alternarRetenido() {
    const esRetenida = document.getElementById('fianzaEstadoSel').value === 'retenida';
    document.getElementById('fianzaRetenidoBox').style.display = esRetenida ? 'block' : 'none';
}

function guardarFianza() {
    if (!fianzaReservaActual) return;

    const boton = document.getElementById('fianzaGuardarBtn');
    boton.disabled = true;
    setFianzaStatus('⏳ Guardando...', 'info');

    fetch('api/update_fianza.php', {
        method: 'POST',
        headers: cabecerasEscritura(),
        body: JSON.stringify({
            id_reserva: fianzaReservaActual,
            estado: document.getElementById('fianzaEstadoSel').value,
            retenido: document.getElementById('fianzaRetenidoInput').value,
            nota: document.getElementById('fianzaNotaInput').value
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            cerrarFianza();
            showAlert(`✅ ${data.message}`, 'success');
            refreshTable();
        } else {
            setFianzaStatus('❌ ' + (data.error || 'No se pudo guardar'), 'error');
        }
    })
    .catch(error => setFianzaStatus('❌ ' + error.message, 'error'))
    .finally(() => { boton.disabled = false; });
}

// ---- Fecha de la reserva ----
// Cuando se hizo la reserva, debajo del ID. Se destaca si entro en los ultimos
// 7 dias: asi se ve de un vistazo lo que acaba de caer. Duplicada en index.php.
function pintarFechaReserva(b) {
    const cruda = b['Fecha reserva'];
    if (!cruda) return '';
    const fecha = new Date(cruda);
    if (isNaN(fecha)) return '';

    const hace7 = new Date();
    hace7.setHours(0, 0, 0, 0);
    hace7.setDate(hace7.getDate() - 7);
    const reciente = fecha >= hace7;

    return `<div class="celda-detalle${reciente ? ' reciente' : ''}" title="Cuando se hizo la reserva">
                ${reciente ? '\u{1F195}' : '\u{1F4C5}'} reservada el ${formatDate(cruda)}
            </div>`;
}

// ---- Notas del huesped ----
// Ojo: pintarHuesped() esta duplicada a proposito en index.php, que es quien
// pinta la tabla en la carga inicial. Si cambia una, cambia la otra.
//
// Las notas van pegadas al huesped (su telefono normalizado, campo
// 'Huesped clave' que calcula includes/notas.php), no a la reserva: por eso la
// insignia sale igual en todas las reservas de la misma persona.
function pintarHuesped(b) {
    const nombre = b['Nombre_huesped'] || '-';
    const cuantas = parseInt(b['Notas'] || 0, 10);
    const insignia = cuantas > 0
        ? ` <span class="badge badge-nota">📝 ${cuantas}</span>`
        : '';
    // Solo si se alojó de verdad en otra reserva: una cancelada anterior no
    // significa que haya estado aquí. Lo calcula anotarNotas() en notas.php.
    const alojado = b['Se alojó antes']
        ? '<div class="celda-detalle">🔁 ya se alojó antes</div>'
        : '';

    const atributos = [
        ['id', b['ID_Reserva'] || ''],
        ['clave', b['Huesped clave'] || ''],
        ['nombre', b['Nombre_huesped'] || '']
    ].map(([k, v]) => `data-${k}="${escapeHtml(String(v)).replace(/"/g, '&quot;')}"`).join(' ');

    return `<button type="button" class="celda-boton celda-huesped" title="Notas de este huésped" ${atributos} onclick="abrirNotas(this)">
                <span class="huesped-nombre">${escapeHtml(nombre)}</span>${insignia}
            </button>${alojado}`;
}

let notasHuespedActual = null;   // { id, clave, nombre }
let notasEditando = null;        // id de la nota que se esta editando, o null

function setNotasStatus(msg, type) {
    const el = document.getElementById('notasStatus');
    el.textContent = msg;
    el.className = 'holidu-status' + (type ? ' holidu-status-' + type : '');
}

function abrirNotas(btn) {
    notasHuespedActual = {
        id: btn.dataset.id,
        clave: btn.dataset.clave,
        nombre: btn.dataset.nombre
    };
    notasEditando = null;

    document.getElementById('notasDesc').textContent =
        `${btn.dataset.nombre} · reserva ${btn.dataset.id}`;
    document.getElementById('notasTexto').value = '';
    document.getElementById('notasGuardarBtn').textContent = 'Añadir nota';
    setNotasStatus('⏳ Cargando notas...', 'info');
    document.getElementById('notasLista').innerHTML = '';
    document.getElementById('notasModal').classList.add('active');

    cargarNotas();
}

function cerrarNotas() {
    document.getElementById('notasModal').classList.remove('active');
    notasHuespedActual = null;
    notasEditando = null;
}

function cargarNotas() {
    if (!notasHuespedActual) return;

    fetch('api/notas_listar.php?clave=' + encodeURIComponent(notasHuespedActual.clave))
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                setNotasStatus('❌ ' + (data.error || 'No se pudieron leer las notas'), 'error');
                return;
            }
            setNotasStatus('', '');
            pintarListaNotas(data.notas || []);
        })
        .catch(error => setNotasStatus('❌ ' + error.message, 'error'));
}

function pintarListaNotas(notas) {
    const cont = document.getElementById('notasLista');

    if (notas.length === 0) {
        cont.innerHTML = '<p class="notas-vacio">Aún no hay notas de este huésped.</p>';
        return;
    }

    // Las mas recientes arriba, que es lo que se quiere leer primero.
    cont.innerHTML = notas.slice().reverse().map(n => {
        // De que estancia viene la nota. Si es de la reserva que se esta
        // mirando no se dice nada; lo interesante es cuando viene de otra.
        const deOtra = n.id_reserva && n.id_reserva !== notasHuespedActual.id
            ? `<span class="notas-otra">de la reserva ${escapeHtml(n.id_reserva)}</span>`
            : '';
        const origen = n.origen === 'asistente'
            ? '<span class="badge badge-asistente">asistente</span>'
            : '';

        return `<div class="notas-item">
                    <div class="notas-item-texto">${escapeHtml(n.texto)}</div>
                    <div class="notas-item-pie">
                        <span class="notas-fecha">${escapeHtml(n.fecha)}</span>
                        ${origen}
                        ${deOtra}
                        <span class="notas-acciones">
                            <button type="button" class="btn btn-secondary notas-mini" title="Editar"
                                data-id="${escapeHtml(n.id)}"
                                data-texto="${escapeHtml(n.texto).replace(/"/g, '&quot;')}"
                                onclick="editarNota(this)">✏️</button>
                            <button type="button" class="btn btn-secondary notas-mini" title="Borrar"
                                data-id="${escapeHtml(n.id)}" onclick="borrarNota(this)">🗑️</button>
                        </span>
                    </div>
                </div>`;
    }).join('');
}

function editarNota(btn) {
    notasEditando = btn.dataset.id;
    const campo = document.getElementById('notasTexto');
    campo.value = btn.dataset.texto;
    campo.focus();
    document.getElementById('notasGuardarBtn').textContent = 'Guardar cambios';
    setNotasStatus('✏️ Editando una nota. Vacía el texto y recarga para cancelar.', 'info');
}

function guardarNota() {
    if (!notasHuespedActual) return;

    const texto = document.getElementById('notasTexto').value.trim();
    if (!texto) {
        setNotasStatus('❌ Escribe algo antes de guardar', 'error');
        return;
    }

    const boton = document.getElementById('notasGuardarBtn');
    boton.disabled = true;
    setNotasStatus('⏳ Guardando...', 'info');

    // Con id_nota edita; sin el, crea una nueva sobre esta reserva.
    const cuerpo = notasEditando
        ? { id_nota: notasEditando, texto: texto }
        : { id_reserva: notasHuespedActual.id, texto: texto };

    fetch('api/notas_guardar.php', {
        method: 'POST',
        headers: cabecerasEscritura(),
        body: JSON.stringify(cuerpo)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            notasEditando = null;
            document.getElementById('notasTexto').value = '';
            boton.textContent = 'Añadir nota';
            setNotasStatus(`✅ ${data.message}`, 'success');
            cargarNotas();
            refreshTable();
        } else {
            setNotasStatus('❌ ' + (data.error || 'No se pudo guardar'), 'error');
        }
    })
    .catch(error => setNotasStatus('❌ ' + error.message, 'error'))
    .finally(() => { boton.disabled = false; });
}

function borrarNota(btn) {
    // Borrar una nota no se puede deshacer desde el panel, asi que se pregunta.
    if (!confirm('¿Borrar esta nota? No se puede deshacer.')) return;

    btn.disabled = true;
    setNotasStatus('⏳ Borrando...', 'info');

    fetch('api/notas_borrar.php', {
        method: 'POST',
        headers: cabecerasEscritura(),
        body: JSON.stringify({ id_nota: btn.dataset.id })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setNotasStatus(`✅ ${data.message}`, 'success');
            cargarNotas();
            refreshTable();
        } else {
            setNotasStatus('❌ ' + (data.error || 'No se pudo borrar'), 'error');
            btn.disabled = false;
        }
    })
    .catch(error => {
        setNotasStatus('❌ ' + error.message, 'error');
        btn.disabled = false;
    });
}


// ---- Login Holidu (modal) ----
let holiduPollTimer = null;

function setHoliduStatus(msg, type) {
    const el = document.getElementById('holiduStatus');
    el.textContent = msg;
    el.className = 'holidu-status' + (type ? ' holidu-status-' + type : '');
}

function openHoliduLogin() {
    document.getElementById('holiduModal').classList.add('active');
    document.getElementById('holiduStartBox').style.display = 'block';
    document.getElementById('holidu2faBox').style.display = 'none';
    document.getElementById('holiduStartBtn').disabled = false;
    document.getElementById('holiduCodeInput').value = '';
    setHoliduStatus('', '');
}

function closeHoliduLogin() {
    document.getElementById('holiduModal').classList.remove('active');
    if (holiduPollTimer) { clearInterval(holiduPollTimer); holiduPollTimer = null; }
}

function startHoliduLogin() {
    document.getElementById('holiduStartBtn').disabled = true;
    setHoliduStatus('⏳ Conectando con Holidu...', 'info');

    fetch('api/holidu_login_start.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                pollHoliduStatus();
            } else {
                setHoliduStatus('❌ ' + (data.error || 'No se pudo iniciar'), 'error');
                document.getElementById('holiduStartBtn').disabled = false;
            }
        })
        .catch(err => {
            setHoliduStatus('❌ Error: ' + err.message, 'error');
            document.getElementById('holiduStartBtn').disabled = false;
        });
}

function pollHoliduStatus() {
    if (holiduPollTimer) clearInterval(holiduPollTimer);

    holiduPollTimer = setInterval(() => {
        fetch('api/holidu_login_status.php')
            .then(r => r.json())
            .then(data => {
                const state = data.state;
                const startBox = document.getElementById('holiduStartBox');
                const faBox = document.getElementById('holidu2faBox');

                if (state === 'running') {
                    setHoliduStatus('⏳ ' + (data.message || 'Procesando...'), 'info');
                    startBox.style.display = 'none';
                    faBox.style.display = 'none';
                } else if (state === 'waiting_2fa') {
                    setHoliduStatus('📧 ' + (data.message || 'Introduce el código enviado a tu correo.'), 'info');
                    startBox.style.display = 'none';
                    faBox.style.display = 'block';
                    document.getElementById('holiduCodeBtn').disabled = false;
                    document.getElementById('holiduCodeInput').focus();
                } else if (state === 'success') {
                    clearInterval(holiduPollTimer); holiduPollTimer = null;
                    setHoliduStatus('✅ ' + (data.message || 'Sesión iniciada correctamente.'), 'success');
                    startBox.style.display = 'none';
                    faBox.style.display = 'none';
                } else if (state === 'error') {
                    clearInterval(holiduPollTimer); holiduPollTimer = null;
                    setHoliduStatus('❌ ' + (data.message || 'Error al iniciar sesión.'), 'error');
                    startBox.style.display = 'block';
                    document.getElementById('holiduStartBtn').disabled = false;
                    faBox.style.display = 'none';
                }
            })
            .catch(() => { /* reintentar en el siguiente ciclo */ });
    }, 2000);
}

function submitHoliduCode() {
    const code = document.getElementById('holiduCodeInput').value.trim();
    if (!code) { setHoliduStatus('❌ Introduce el código', 'error'); return; }

    document.getElementById('holiduCodeBtn').disabled = true;
    fetch('api/holidu_login_code.php', {
        method: 'POST',
        headers: cabecerasEscritura(),
        body: JSON.stringify({ code: code })
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                setHoliduStatus('⏳ Verificando código...', 'info');
                document.getElementById('holidu2faBox').style.display = 'none';
                // el polling seguirá mostrando success/error
            } else {
                setHoliduStatus('❌ ' + (data.error || 'Código incorrecto'), 'error');
                document.getElementById('holiduCodeBtn').disabled = false;
            }
        })
        .catch(err => {
            setHoliduStatus('❌ Error: ' + err.message, 'error');
            document.getElementById('holiduCodeBtn').disabled = false;
        });
}


// ---- Calendario de ocupacion ----
// Usa las mismas reservas que la tabla (api/get_data.php), sin las canceladas.
// Las fechas se comparan como texto 'AAAA-MM-DD', que ordena igual que las
// fechas y evita los saltos de zona horaria de new Date('AAAA-MM-DD').
let RESERVAS = null;     // ultima respuesta de get_data.php
let VISTA = 'tabla';
let MES_CAL = null;      // {anio, mes} con mes 0-11

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
    'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const CANALES_CONOCIDOS = ['BOOKINGCOM', 'AIRBNB', 'HOLIDU'];

/** Para valores dentro de un atributo: escapeHtml no escapa las comillas. */
function escAttr(texto) {
    return escapeHtml(String(texto)).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function isoDia(anio, mes, dia) {
    // mes 0-11; Date normaliza los desbordes (dia 0 = ultimo del mes anterior)
    const d = new Date(anio, mes, dia);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function cambiarVista(vista) {
    VISTA = vista;
    try { localStorage.setItem('vistaReservas', vista); } catch (e) { /* sin almacenamiento */ }
    const esCal = vista === 'calendario';
    document.getElementById('vistaCalendario').hidden = !esCal;
    document.getElementById('vistaTabla').hidden = esCal;
    document.getElementById('vistaBtnTabla').classList.toggle('activa', !esCal);
    document.getElementById('vistaBtnCalendario').classList.toggle('activa', esCal);
    if (esCal) {
        if (RESERVAS) pintarCalendario();
        else fetch('api/get_data.php').then(r => r.json()).then(datos => { RESERVAS = datos; pintarCalendario(); });
    }
}

function moverMes(paso) {
    const hoy = new Date();
    if (paso === 0 || !MES_CAL) {
        MES_CAL = { anio: hoy.getFullYear(), mes: hoy.getMonth() };
    } else {
        const d = new Date(MES_CAL.anio, MES_CAL.mes + paso, 1);
        MES_CAL = { anio: d.getFullYear(), mes: d.getMonth() };
    }
    pintarCalendario();
}

/** Estancia que ocupa la noche que empieza en 'dia' (o null). */
function estanciaDeLaNoche(estancias, dia) {
    return estancias.find(b => b['Check-in'] <= dia && dia < b['Check-Out']) || null;
}

function pintarCalendario() {
    if (!MES_CAL) { const h = new Date(); MES_CAL = { anio: h.getFullYear(), mes: h.getMonth() }; }
    const rejilla = document.getElementById('calRejilla');
    if (!RESERVAS || RESERVAS.error) {
        rejilla.innerHTML = `<p class="cal-vacio">❌ ${escapeHtml(RESERVAS?.error || 'Sin datos')}</p>`;
        return;
    }
    const estancias = RESERVAS.filter(b => !(b['Estado'] || '').includes('CANCEL')
        && b['Check-in'] && b['Check-Out']);

    const { anio, mes } = MES_CAL;
    const diasMes = new Date(anio, mes + 1, 0).getDate();
    const hoy = isoDia(new Date().getFullYear(), new Date().getMonth(), new Date().getDate());
    document.getElementById('calTitulo').textContent = `${MESES[mes]} ${anio}`;

    let html = ['L', 'M', 'X', 'J', 'V', 'S', 'D'].map(d => `<div class="cal-dsem">${d}</div>`).join('');
    // Huecos hasta el lunes: getDay() da 0 = domingo
    const huecos = (new Date(anio, mes, 1).getDay() + 6) % 7;
    html += '<div class="cal-dia cal-fuera"></div>'.repeat(huecos);

    let noches = 0, llegadas = 0;
    for (let dia = 1; dia <= diasMes; dia++) {
        const iso = isoDia(anio, mes, dia);
        const antes = estanciaDeLaNoche(estancias, isoDia(anio, mes, dia - 1));
        const ahora = estanciaDeLaNoche(estancias, iso);
        if (ahora) noches++;
        if (ahora && ahora['Check-in'] === iso) llegadas++;

        const mitad = (b, lado) => {
            if (!b) return `<span class="cal-mitad ${lado}"></span>`;
            const canal = CANALES_CONOCIDOS.includes(b['Canal']) ? b['Canal'] : 'OTRO';
            const llega = lado === 'der' && b['Check-in'] === iso;
            // El nombre va el dia que llega, o el 1 si viene del mes anterior
            const nombre = (llega || (lado === 'der' && dia === 1))
                ? `<em>${escapeHtml(b['Nombre_huesped'] || '')}</em>` : '';
            const titulo = `${b['Nombre_huesped'] || ''} · ${b['Check-in']} → ${b['Check-Out']}`;
            return `<span class="cal-mitad ${lado} canal-${canal}${llega ? ' inicio' : ''}" title="${escAttr(titulo)}" data-id="${escAttr(b['ID_Reserva'] || '')}">${nombre}</span>`;
        };

        html += `<div class="cal-dia${iso === hoy ? ' cal-hoy-dia' : ''}">
            <span class="cal-num">${dia}</span>${mitad(antes, 'izq')}${mitad(ahora, 'der')}
        </div>`;
    }
    rejilla.innerHTML = html;

    const pct = Math.round(noches * 100 / diasMes);
    document.getElementById('calResumen').innerHTML =
        `<strong>${noches}</strong> de ${diasMes} noches ocupadas (<strong>${pct}%</strong>) · ` +
        `<strong>${llegadas}</strong> llegada${llegadas === 1 ? '' : 's'}`;
}

function verEstancia(id) {
    const b = (RESERVAS || []).find(r => String(r['ID_Reserva']) === String(id));
    if (!b) return;
    const noches = Math.round((new Date(b['Check-Out']) - new Date(b['Check-in'])) / 86400000);
    showAlert(`🛏️ ${escapeHtml(b['Nombre_huesped'] || '')} · ${formatDate(b['Check-in'])} → ${formatDate(b['Check-Out'])} ` +
        `(${noches} noche${noches === 1 ? '' : 's'}) · ${escapeHtml(b['Canal'] || 'canal desconocido')}` +
        (b['Ganancia'] ? ` · ganancia ${escapeHtml(String(b['Ganancia']))} €` : ''), 'success');
}

document.getElementById('calRejilla')?.addEventListener('click', ev => {
    const mitad = ev.target.closest('.cal-mitad[data-id]');
    if (mitad) verEstancia(mitad.dataset.id);
});

// Recordar la ultima vista elegida
try {
    if (localStorage.getItem('vistaReservas') === 'calendario') cambiarVista('calendario');
} catch (e) { /* sin almacenamiento: se queda la tabla */ }
