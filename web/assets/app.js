/**
 * Panel de reservas de Holidu - JavaScript
 *
 * Es el UNICO que pinta las reservas: index.php solo deja los datos incrustados
 * en #datosIniciales. Todo sale de modelo() -> pintar*().
 */
'use strict';

// ============================================================
// Utilidades
// ============================================================

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const meta = nombre => document.querySelector(`meta[name="${nombre}"]`)?.content || '';

// El token CSRF va en TODO lo que cambia algo; las lecturas no lo necesitan.
const CSRF = meta('csrf-token');
const ALOJAMIENTO = meta('alojamiento');
const PLANTILLA = meta('mensaje-formulario') || '{enlace}';

/** Escapa texto para HTML, comillas incluidas (vale tambien en atributos). */
function esc(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const ic = (id, clase = '') => `<svg class="icono ${clase}" aria-hidden="true"><use href="#i-${id}"/></svg>`;

function guardarPref(clave, valor) { try { localStorage.setItem(clave, valor); } catch (e) { /* sin almacenamiento */ } }
function leerPref(clave) { try { return localStorage.getItem(clave); } catch (e) { return null; } }

const capitalizar = t => String(t || '').toLowerCase().replace(/(^|\s|-)(\p{L})/gu, (m, a, b) => a + b.toUpperCase());

// ---- Fechas ----
// Se trabaja con 'AAAA-MM-DD' en hora local: comparar como texto ordena igual
// que las fechas y evita los saltos de zona horaria de new Date('AAAA-MM-DD').
function isoLocal(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const hoyIso = () => isoLocal(new Date());
function aFecha(iso) {
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    return new Date(a, m - 1, d);
}
const diasEntre = (desde, hasta) => Math.round((aFecha(hasta) - aFecha(desde)) / 86400000);

const FMT = {
    diaSemana: new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric', month: 'short' }),
    dia: new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' }),
    diaAnio: new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }),
    largo: new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
    mesAnio: new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' }),
    mes: new Intl.DateTimeFormat('es-ES', { month: 'long' }),
    relativo: new Intl.RelativeTimeFormat('es', { numeric: 'auto' }),
};
const fecha = (iso, fmt = FMT.dia) => iso ? fmt.format(aFecha(iso)).replace(/\./g, '') : '—';

/** «hoy», «mañana», «en 5 días», «hace 3 días»... */
function relativo(dias) {
    if (Math.abs(dias) < 14) return FMT.relativo.format(dias, 'day');
    if (Math.abs(dias) < 60) return FMT.relativo.format(Math.round(dias / 7), 'week');
    return FMT.relativo.format(Math.round(dias / 30), 'month');
}

// ---- Dinero ----
const FMT_EUR = new Intl.NumberFormat('es-ES', {
    style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: 'always',
});
const numero = v => { const n = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(n) ? null : n; };
const euros = v => { const n = numero(v); return n === null ? '—' : FMT_EUR.format(n); };
/** Sin decimales si es un importe redondo (fianzas: «250 €» y no «250,00 €»). */
const eurosCorto = v => { const n = numero(v); return n === null ? '—' : (Number.isInteger(n) ? eurosRedondo(n) : FMT_EUR.format(n)); };
const eurosRedondo = n => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0, useGrouping: 'always' }).format(n);

// ---- Canales, telefono, estados ----
const CANALES = { BOOKINGCOM: 'Booking.com', AIRBNB: 'Airbnb', HOLIDU: 'Holidu' };
const canalClave = c => CANALES[c] ? c : 'OTRO';
const canalNombre = c => CANALES[c] || (c ? capitalizar(c) : 'Sin canal');

function telefonoBonito(t) {
    const s = String(t || '').replace(/\s/g, '');
    const m = s.match(/^\+34(\d{3})(\d{3})(\d{3})$/);
    return m ? `+34 ${m[1]} ${m[2]} ${m[3]}` : s;
}
/** Numero para wa.me: solo digitos, con prefijo de pais. */
function telefonoWhatsapp(t) {
    const s = String(t || '').trim();
    const digitos = s.replace(/\D/g, '');
    if (!digitos) return '';
    return s.startsWith('+') || s.startsWith('00') ? digitos.replace(/^00/, '') : digitos;
}

// ============================================================
// Avisos (toasts)
// ============================================================

function aviso(texto, tipo = 'ok', opciones = {}) {
    const caja = $('#avisos');
    const el = document.createElement('div');
    el.className = `aviso ${tipo}`;
    el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    const icono = { ok: 'ok', error: 'alerta', info: 'info' }[tipo] || 'info';
    el.innerHTML = `${ic(icono)}<div class="aviso-texto">${esc(texto)}${opciones.accion
        ? `<button type="button" class="aviso-accion">${esc(opciones.accion.texto)}</button>` : ''}</div>
        <button type="button" class="btn-icono" aria-label="Cerrar aviso">${ic('cerrar', 'icono-sm')}</button>`;

    const cerrar = () => {
        if (!el.isConnected) return;
        el.classList.add('saliendo');
        setTimeout(() => el.remove(), 180);
    };
    el.querySelector('.btn-icono').onclick = cerrar;
    if (opciones.accion) {
        el.querySelector('.aviso-accion').onclick = () => { cerrar(); opciones.accion.fn(); };
    }
    caja.appendChild(el);
    // Los errores se quedan mas tiempo: hay que leerlos
    setTimeout(cerrar, opciones.duracion || (tipo === 'error' ? 9000 : 4500));
    while (caja.children.length > 4) caja.firstElementChild.remove();
}

// ============================================================
// Red
// ============================================================

/** fetch + JSON, con la sesion caducada tratada en un solo sitio. */
async function pedir(url, opciones = {}) {
    const resp = await fetch(url, opciones);
    if (resp.status === 401) {
        aviso('La sesión ha caducado. Vuelve a entrar.', 'error');
        setTimeout(() => location.reload(), 1800);
        throw new Error('Sesión caducada');
    }
    let datos;
    try { datos = await resp.json(); } catch (e) { throw new Error(`Respuesta no válida del servidor (HTTP ${resp.status})`); }
    return datos;
}

const escribir = (url, cuerpo) => pedir(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(cuerpo),
});

// ============================================================
// Modelo
// ============================================================

let DATOS = { reservas: [], ultimoSync: null };
let RESERVAS = [];          // modelo ya preparado
let ultimaCarga = Date.now();
let registroSync = '';

const ESTADO = {
    filtro: leerPref('filtroReservas') || 'proximas',
    vista: leerPref('vistaReservas') === 'calendario' ? 'calendario' : 'lista',
    busqueda: '',
    mes: null,              // {anio, mes} del calendario
};

const FIANZA_ESTADOS = ['pendiente', 'pagada', 'devuelta', 'retenida'];
const FIANZA_CHIP = { pendiente: 'chip-aviso', pagada: 'chip-ok', devuelta: 'chip-info', retenida: 'chip-mal' };

/** Convierte una reserva de la API en lo que necesita la interfaz. */
function preparar(b, hoy) {
    const ci = String(b['Check-in'] || '').slice(0, 10);
    const co = String(b['Check-Out'] || '').slice(0, 10);
    const cancelada = String(b['Estado'] || '').includes('CANCEL');
    const pasada = !cancelada && co !== '' && co < hoy;
    const enCasa = !cancelada && ci !== '' && ci <= hoy && hoy < co;
    const saleHoy = !cancelada && co === hoy;

    // "No aplica" se deduce, no se guarda: solo si la gestionamos nosotros y hay
    // importe. Misma regla que includes/fianza.php.
    const importeFianza = numero(b['Fianza']) || 0;
    const fianzaAplica = b['Fianza gestiona'] === 'nosotros' && importeFianza > 0;
    const fianzaEstado = FIANZA_ESTADOS.includes(b['Fianza estado']) ? b['Fianza estado'] : 'pendiente';

    const reservadaEl = b['Fecha reserva'] ? isoLocal(new Date(b['Fecha reserva'])) : '';

    const r = {
        b,
        id: String(b['ID_Reserva'] || ''),
        // Llegan en MAYUSCULAS o en minusculas segun el canal: se normalizan
        nombre: capitalizar(b['Nombre_huesped'] || '') || 'Sin nombre',
        ci, co,
        noches: ci && co ? diasEntre(ci, co) : 0,
        cancelada, pasada, enCasa, saleHoy,
        categoria: cancelada ? 'canceladas' : (pasada ? 'pasadas' : 'proximas'),
        canal: b['Canal'] || '',
        ganancia: numero(b['Ganancia']),
        pagado: numero(b['Pagado']),
        telefono: b['Número de teléfono'] || '',
        correo: b['Correo electrónico'] || '',
        enlace: b['Link formulario'] || '',
        formularioEnviado: b['Formulario'] === 'Enviado',
        fianza: {
            aplica: fianzaAplica,
            importe: b['Fianza'] || '',
            estado: fianzaEstado,
            retenido: b['Fianza retenido'] || '',
            nota: b['Fianza nota'] || '',
            gestiona: b['Fianza gestiona'] || '',
        },
        notas: parseInt(b['Notas'] || 0, 10) || 0,
        clave: b['Huesped clave'] || '',
        repite: !!b['Se alojó antes'],
        reservadaEl,
        reciente: reservadaEl !== '' && diasEntre(reservadaEl, hoy) <= 7,
        tareas: [],
    };

    // Lo que hay que hacer con esta reserva
    if (!cancelada && !pasada) {
        const faltan = diasEntre(hoy, ci);
        if (!r.formularioEnviado) {
            r.tareas.push({ tipo: 'formulario', texto: 'Formulario sin enviar', urgente: faltan <= 3 });
        }
        if (fianzaAplica && fianzaEstado === 'pendiente') {
            r.tareas.push({ tipo: 'cobrar', texto: 'Fianza por cobrar', urgente: faltan <= 3 });
        }
    }
    if (pasada && fianzaAplica && fianzaEstado === 'pagada') {
        r.tareas.push({ tipo: 'devolver', texto: 'Fianza por devolver', urgente: diasEntre(co, hoy) >= 7 });
    }
    return r;
}

function modelo() {
    const hoy = hoyIso();
    const lista = Array.isArray(DATOS.reservas) ? DATOS.reservas : [];
    RESERVAS = lista.map(b => preparar(b, hoy));
}

const porId = id => RESERVAS.find(r => r.id === String(id));

// ============================================================
// Pintado: resumen
// ============================================================

function pintarResumen() {
    const hoy = hoyIso();
    const estancias = RESERVAS.filter(r => !r.cancelada && r.ci && r.co);
    const enCasa = estancias.find(r => r.enCasa);
    const saliendo = estancias.find(r => r.saleHoy);
    const proxima = estancias.filter(r => r.ci > hoy).sort((a, b) => a.ci.localeCompare(b.ci))[0];

    // 1. Ahora / proxima llegada
    let tarjeta1;
    const btnNombre = r => `<button type="button" class="tarjeta-valor nombre" data-ficha="${esc(r.id)}" title="Ver ficha">${esc(r.nombre)}</button>`;
    if (enCasa) {
        const sale = diasEntre(hoy, enCasa.co);
        tarjeta1 = `<div class="tarjeta-titulo">${ic('cama')}En casa ahora</div>
            ${btnNombre(enCasa)}
            <div class="tarjeta-sub">Sale ${esc(fecha(enCasa.co, FMT.diaSemana))} · <strong>${esc(relativo(sale))}</strong></div>
            ${proxima ? `<div class="tarjeta-sub">Después: ${esc(capitalizar(proxima.nombre))}, ${esc(fecha(proxima.ci))}</div>` : ''}`;
    } else if (proxima) {
        const faltan = diasEntre(hoy, proxima.ci);
        tarjeta1 = `<div class="tarjeta-titulo">${ic('calendario')}Próxima llegada</div>
            ${btnNombre(proxima)}
            <div class="tarjeta-sub">${esc(fecha(proxima.ci, FMT.diaSemana))} · <strong>${esc(relativo(faltan))}</strong> · ${proxima.noches} noche${proxima.noches === 1 ? '' : 's'}</div>
            ${saliendo ? `<div class="tarjeta-sub">Hoy sale ${esc(capitalizar(saliendo.nombre))}</div>` : '<div class="tarjeta-sub">La casa está libre</div>'}`;
    } else {
        tarjeta1 = `<div class="tarjeta-titulo">${ic('casa')}Ocupación</div>
            <div class="tarjeta-valor">Libre</div><div class="tarjeta-sub">No hay llegadas previstas</div>`;
    }

    // 2. Ocupacion del mes en curso
    const ahora = new Date();
    const occ = ocupacionMes(estancias, ahora.getFullYear(), ahora.getMonth());
    const tarjeta2 = `<div class="tarjeta-titulo">${ic('casa')}Ocupación de ${esc(FMT.mes.format(ahora))}</div>
        <div class="tarjeta-valor num">${occ.pct} %</div>
        <div class="barra" role="img" aria-label="${occ.pct} % ocupado"><span style="width:${occ.pct}%"></span></div>
        <div class="tarjeta-sub"><strong>${occ.noches}</strong> de ${occ.dias} noches · ${occ.llegadas} llegada${occ.llegadas === 1 ? '' : 's'}</div>`;

    // 3. Ganancia del año (por fecha de entrada)
    const anio = String(ahora.getFullYear());
    const delAnio = estancias.filter(r => r.ci.startsWith(anio));
    const ganancia = delAnio.reduce((s, r) => s + (r.ganancia || 0), 0);
    const nochesAnio = delAnio.reduce((s, r) => s + r.noches, 0);
    const tarjeta3 = `<div class="tarjeta-titulo">${ic('euro')}Ganancia ${anio}</div>
        <div class="tarjeta-valor num">${esc(eurosRedondo(ganancia))}</div>
        <div class="tarjeta-sub">${delAnio.length} estancia${delAnio.length === 1 ? '' : 's'} · ${nochesAnio} noches${nochesAnio ? ` · ${esc(eurosRedondo(ganancia / nochesAnio))}/noche` : ''}</div>`;

    // 4. Por hacer
    const cuenta = tipo => RESERVAS.filter(r => r.tareas.some(t => t.tipo === tipo));
    const urgente = tipo => RESERVAS.some(r => r.tareas.some(t => t.tipo === tipo && t.urgente));
    const filas = [
        ['formulario', 'formulario sin enviar', 'formularios sin enviar'],
        ['cobrar', 'fianza por cobrar', 'fianzas por cobrar'],
        ['devolver', 'fianza por devolver', 'fianzas por devolver'],
    ].map(([tipo, uno, varios]) => {
        const n = cuenta(tipo).length;
        return n ? `<button type="button" class="pendiente${urgente(tipo) ? ' urgente' : ''}" data-ir="pendientes">
            <span class="punto"></span><strong>${n}</strong>${n === 1 ? uno : varios}</button>` : '';
    }).join('');
    const tarjeta4 = `<div class="tarjeta-titulo">${ic('tareas')}Por hacer</div>
        ${filas ? `<div class="pendientes">${filas}</div>` : `<div class="todo-ok">${ic('ok')}Todo al día</div>`}`;

    $('#resumen').innerHTML = [tarjeta1, tarjeta2, tarjeta3, tarjeta4].map(t => `<div class="tarjeta">${t}</div>`).join('');
}

function ocupacionMes(estancias, anio, mes) {
    const dias = new Date(anio, mes + 1, 0).getDate();
    let noches = 0, llegadas = 0, ganancia = 0;
    for (let d = 1; d <= dias; d++) {
        const iso = isoLocal(new Date(anio, mes, d));
        const r = estancias.find(e => e.ci <= iso && iso < e.co);
        if (r) noches++;
        if (r && r.ci === iso) { llegadas++; ganancia += r.ganancia || 0; }
    }
    return { dias, noches, llegadas, ganancia, pct: Math.round(noches * 100 / dias) };
}

// ============================================================
// Pintado: lista
// ============================================================

function coincide(r, q) {
    if (!q) return true;
    const texto = `${r.nombre} ${r.id} ${r.correo} ${canalNombre(r.canal)}`.toLowerCase()
        .normalize('NFD').replace(/\p{Diacritic}/gu, '');
    const qn = q.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
    const digitos = q.replace(/\D/g, '');
    return texto.includes(qn) || (digitos.length >= 3 && r.telefono.replace(/\D/g, '').includes(digitos));
}

function reservasVisibles() {
    const q = ESTADO.busqueda.trim();
    if (q) {
        // La busqueda mira en TODAS: activas primero, luego pasadas y canceladas
        const orden = { proximas: 0, pasadas: 1, canceladas: 2 };
        return RESERVAS.filter(r => coincide(r, q))
            .sort((a, b) => orden[a.categoria] - orden[b.categoria] || ordenar(a, b, a.categoria));
    }
    const lista = ESTADO.filtro === 'pendientes'
        ? RESERVAS.filter(r => r.tareas.length)
        : RESERVAS.filter(r => r.categoria === ESTADO.filtro);
    return lista.sort((a, b) => ordenar(a, b, ESTADO.filtro));
}

function ordenar(a, b, filtro) {
    // Proximas y por hacer: la mas cercana arriba. Pasadas y canceladas: la ultima arriba.
    const asc = filtro === 'proximas' || filtro === 'pendientes';
    return asc ? a.ci.localeCompare(b.ci) : b.ci.localeCompare(a.ci);
}

function grupoDe(r) {
    if (ESTADO.busqueda.trim()) {
        return { proximas: 'Próximas y en curso', pasadas: 'Pasadas', canceladas: 'Canceladas' }[r.categoria];
    }
    if (ESTADO.filtro === 'pendientes') return null;
    if (r.enCasa || r.saleHoy) return 'Ahora';
    return r.ci ? FMT.mesAnio.format(aFecha(r.ci)) : 'Sin fecha';
}

function textoEstancia(r) {
    const hoy = hoyIso();
    const anioActual = String(new Date().getFullYear());
    const conAnio = !r.co.startsWith(anioActual) || !r.ci.startsWith(anioActual);
    const fechas = `${fecha(r.ci, FMT.diaSemana)} → ${fecha(r.co, conAnio ? FMT.diaAnio : FMT.diaSemana)}`;

    let cuando = '';
    if (r.cancelada) cuando = 'cancelada';
    else if (r.enCasa) cuando = `<span class="ahora">En casa · sale ${esc(relativo(diasEntre(hoy, r.co)))}</span>`;
    else if (r.saleHoy) cuando = '<span class="ahora">Sale hoy</span>';
    else if (r.pasada) cuando = `terminó ${esc(relativo(-diasEntre(r.co, hoy)))}`;
    else {
        const faltan = diasEntre(hoy, r.ci);
        cuando = `<span class="${faltan <= 7 ? 'pronto' : ''}">llega ${esc(relativo(faltan))}</span>`;
    }
    return { fechas, meta: `${r.noches} noche${r.noches === 1 ? '' : 's'} · ${cuando}` };
}

function htmlFianza(r) {
    const f = r.fianza;
    if (r.cancelada) return '<span class="chip chip-neutro">—</span>';
    if (!f.aplica) {
        const quien = f.gestiona !== 'nosotros' && r.canal ? `la gestiona ${canalNombre(r.canal)}` : '';
        return `<div class="celda-chip"><span class="chip chip-neutro">No aplica</span>${quien ? `<span class="detalle">${esc(quien)}</span>` : ''}</div>`;
    }
    const detalle = f.estado === 'retenida' && f.retenido ? `<span class="detalle">retenidos ${esc(euros(f.retenido))}</span>` : '';
    return `<div class="celda-chip"><button type="button" class="chip ${FIANZA_CHIP[f.estado]}" data-accion="fianza" title="Cambiar estado de la fianza">
        ${esc(eurosCorto(f.importe))} · ${esc(f.estado)}</button>${detalle}</div>`;
}

function htmlFormulario(r) {
    if (r.cancelada) return '<span class="chip chip-neutro">—</span>';
    const chip = r.formularioEnviado
        ? `<button type="button" class="chip chip-ok" data-accion="formulario" title="Marcar como no enviado">${ic('ok', 'icono-sm')}Enviado</button>`
        : `<button type="button" class="chip chip-aviso" data-accion="formulario" title="Marcar como enviado">Sin enviar</button>`;
    return `<div class="celda-chip">${chip}${r.enlace ? '' : '<span class="detalle">sin enlace de Holidu</span>'}</div>`;
}

function htmlAcciones(r) {
    const wa = telefonoWhatsapp(r.telefono);
    const enlaceWa = wa ? `https://wa.me/${wa}${!r.cancelada && r.enlace ? `?text=${encodeURIComponent(mensajeFormulario(r))}` : ''}` : '';
    return `<div class="acciones">
        ${enlaceWa
            ? `<a class="btn-icono whatsapp" href="${esc(enlaceWa)}" target="_blank" rel="noopener" title="${r.enlace && !r.cancelada ? 'Abrir WhatsApp con el mensaje del formulario' : 'Abrir chat de WhatsApp'}" aria-label="WhatsApp">${ic('whatsapp')}</a>`
            : `<span class="btn-icono" aria-disabled="true" title="Sin teléfono">${ic('whatsapp')}</span>`}
        <button type="button" class="btn-icono" data-accion="copiar" title="Copiar el mensaje del formulario" aria-label="Copiar mensaje"${r.enlace ? '' : ' disabled'}>${ic('copiar')}</button>
        <button type="button" class="btn-icono" data-accion="notas" title="Notas del huésped" aria-label="Notas">${ic('nota')}</button>
        <button type="button" class="btn-icono" data-accion="ficha" title="Ver ficha completa" aria-label="Ficha">${ic('ojo')}</button>
    </div>`;
}

function htmlFila(r, primeraProxima) {
    const { fechas, meta: metaEstancia } = textoEstancia(r);
    const chips = [
        r.reciente && !r.cancelada ? '<span class="chip chip-sm chip-acento">Nueva</span>' : '',
        r.repite ? `<span class="chip chip-sm chip-violeta" title="Ya se alojó antes">${ic('repite', 'icono-sm')}Repite</span>` : '',
        r.notas ? `<span class="chip chip-sm chip-info" title="Notas del huésped">${ic('nota', 'icono-sm')}${r.notas}</span>` : '',
        ESTADO.filtro === 'pendientes' && !ESTADO.busqueda
            ? r.tareas.map(t => `<span class="chip chip-sm ${t.urgente ? 'chip-mal' : 'chip-aviso'}">${esc(t.texto)}</span>`).join('') : '',
    ].join('');
    const clases = ['fila', r.enCasa ? 'en-casa' : '', primeraProxima ? 'proxima' : ''].join(' ');

    return `<tr class="${clases}" data-id="${esc(r.id)}">
        <td class="c-huesped"><div class="huesped">
            <button type="button" class="huesped-nombre" data-accion="ficha" title="Ver ficha">${esc(r.nombre)}</button>
            <div class="huesped-meta"><span class="num">#${esc(r.id)}</span>${r.reservadaEl ? `<span>· reservada ${esc(fecha(r.reservadaEl))}</span>` : ''}${chips}</div>
        </div></td>
        <td class="c-estancia"><div class="estancia-fechas">${esc(fechas)}</div><div class="estancia-meta">${metaEstancia}</div></td>
        <td class="c-canal"><span class="canal"><i class="punto fondo-${canalClave(r.canal)}"></i>${esc(canalNombre(r.canal))}</span></td>
        <td class="c-importe derecha"><div class="importe num">${esc(euros(r.ganancia))}</div>${r.pagado !== null ? `<div class="importe-sub num">pagado ${esc(euros(r.pagado))}</div>` : ''}</td>
        <td class="c-fianza">${htmlFianza(r)}</td>
        <td class="c-formulario">${htmlFormulario(r)}</td>
        <td class="c-acciones">${htmlAcciones(r)}</td>
    </tr>`;
}

function pintarLista() {
    const cuerpo = $('#cuerpoTabla');
    if (DATOS.reservas && DATOS.reservas.error) {
        cuerpo.innerHTML = `<tr><td colspan="7"><div class="vacio">${ic('alerta')}<strong>No se pudieron cargar las reservas</strong>${esc(DATOS.reservas.error)}</div></td></tr>`;
        return;
    }
    const lista = reservasVisibles();
    if (!lista.length) {
        const q = ESTADO.busqueda.trim();
        const [titulo, texto] = q
            ? ['Sin resultados', `Ninguna reserva coincide con «${q}».`]
            : {
                proximas: ['No hay reservas próximas', 'Cuando entre una reserva nueva aparecerá aquí.'],
                pendientes: ['Todo al día', 'No hay formularios ni fianzas pendientes.'],
                pasadas: ['Sin reservas pasadas', ''],
                canceladas: ['Sin cancelaciones', ''],
            }[ESTADO.filtro];
        cuerpo.innerHTML = `<tr><td colspan="7"><div class="vacio">${ic(q ? 'buscar' : 'ok')}<strong>${esc(titulo)}</strong>${esc(texto)}</div></td></tr>`;
        return;
    }

    const hoy = hoyIso();
    const idProxima = (RESERVAS.filter(r => !r.cancelada && r.ci > hoy).sort((a, b) => a.ci.localeCompare(b.ci))[0] || {}).id;
    let html = '';
    let grupoActual;
    const cuentaGrupo = {};
    lista.forEach(r => { const g = grupoDe(r); cuentaGrupo[g] = (cuentaGrupo[g] || 0) + 1; });
    for (const r of lista) {
        const g = grupoDe(r);
        if (g && g !== grupoActual) {
            grupoActual = g;
            html += `<tr class="grupo"><td colspan="7">${esc(g)}<span>${cuentaGrupo[g]}</span></td></tr>`;
        }
        html += htmlFila(r, r.id === idProxima && ESTADO.filtro === 'proximas');
    }
    cuerpo.innerHTML = html;
}

function pintarContadores() {
    const n = {
        proximas: RESERVAS.filter(r => r.categoria === 'proximas').length,
        pendientes: RESERVAS.filter(r => r.tareas.length).length,
        pasadas: RESERVAS.filter(r => r.categoria === 'pasadas').length,
        canceladas: RESERVAS.filter(r => r.categoria === 'canceladas').length,
    };
    $$('[data-cuenta]').forEach(el => {
        const v = n[el.dataset.cuenta];
        el.textContent = v;
        el.hidden = el.dataset.cuenta === 'pendientes' && !v;
    });
    const buscando = !!ESTADO.busqueda.trim();
    $$('#pestanas .pestana').forEach(p => p.setAttribute('aria-selected', String(!buscando && p.dataset.filtro === ESTADO.filtro)));
}

// ============================================================
// Pintado: calendario
// ============================================================

function pintarCalendario() {
    if (!ESTADO.mes) { const h = new Date(); ESTADO.mes = { anio: h.getFullYear(), mes: h.getMonth() }; }
    const { anio, mes } = ESTADO.mes;
    const estancias = RESERVAS.filter(r => !r.cancelada && r.ci && r.co);
    const q = ESTADO.busqueda.trim();
    const diasMes = new Date(anio, mes + 1, 0).getDate();
    const hoy = hoyIso();
    const noche = iso => estancias.find(r => r.ci <= iso && iso < r.co) || null;

    const titulo = FMT.mesAnio.format(new Date(anio, mes, 1));
    $('#calTitulo').textContent = titulo.charAt(0).toUpperCase() + titulo.slice(1);

    let html = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'].map(d => `<div class="cal-dsem">${d}</div>`).join('');
    const huecos = (new Date(anio, mes, 1).getDay() + 6) % 7;  // getDay(): 0 = domingo
    html += '<div class="cal-dia cal-fuera"></div>'.repeat(huecos);

    for (let dia = 1; dia <= diasMes; dia++) {
        const iso = isoLocal(new Date(anio, mes, dia));
        const antes = noche(isoLocal(new Date(anio, mes, dia - 1)));
        const ahora = noche(iso);

        const mitad = (r, lado) => {
            if (!r) return `<span class="cal-mitad ${lado}"></span>`;
            const llega = lado === 'der' && r.ci === iso;
            const nombre = (llega || (lado === 'der' && dia === 1)) ? `<em>${esc(r.nombre)}</em>` : '';
            const atenuada = q && !coincide(r, q) ? ' style="opacity:.25"' : '';
            const titulo = `${capitalizar(r.nombre)} · ${fecha(r.ci)} → ${fecha(r.co)} · ${canalNombre(r.canal)}`;
            return `<span class="cal-mitad ${lado} fondo-${canalClave(r.canal)}${llega ? ' inicio' : ''}"${atenuada}
                title="${esc(titulo)}" data-id="${esc(r.id)}">${nombre}</span>`;
        };
        html += `<div class="cal-dia${iso === hoy ? ' cal-hoy-dia' : ''}${antes ? ' ocupado' : ''}">
            <span class="cal-num">${dia}</span>${mitad(antes, 'izq')}${mitad(ahora, 'der')}</div>`;
    }
    $('#calRejilla').innerHTML = html;

    const occ = ocupacionMes(estancias, anio, mes);
    $('#calResumen').innerHTML = `<span><strong>${occ.pct} %</strong> ocupado</span>
        <span><strong>${occ.noches}</strong> de ${occ.dias} noches</span>
        <span><strong>${occ.llegadas}</strong> llegada${occ.llegadas === 1 ? '' : 's'}</span>
        <span><strong>${esc(eurosRedondo(occ.ganancia))}</strong> de ganancia</span>`;
}

function moverMes(paso) {
    const h = new Date();
    if (paso === 0 || !ESTADO.mes) ESTADO.mes = { anio: h.getFullYear(), mes: h.getMonth() };
    else {
        const d = new Date(ESTADO.mes.anio, ESTADO.mes.mes + paso, 1);
        ESTADO.mes = { anio: d.getFullYear(), mes: d.getMonth() };
    }
    pintarCalendario();
}

// ============================================================
// Pintado general
// ============================================================

function pintarTodo() {
    modelo();
    pintarResumen();
    pintarContadores();
    if (ESTADO.vista === 'calendario') pintarCalendario(); else pintarLista();
}

function cambiarVista(vista) {
    ESTADO.vista = vista;
    guardarPref('vistaReservas', vista);
    const cal = vista === 'calendario';
    $('#vistaCalendario').hidden = !cal;
    $('#vistaLista').hidden = cal;
    $$('.vistas .pestana').forEach(p => p.setAttribute('aria-selected', String(p.dataset.vista === vista)));
    if (cal) pintarCalendario(); else pintarLista();
}

function cambiarFiltro(filtro) {
    ESTADO.filtro = filtro;
    guardarPref('filtroReservas', filtro);
    if (ESTADO.busqueda) { ESTADO.busqueda = ''; $('#buscar').value = ''; }
    if (ESTADO.vista !== 'lista') cambiarVista('lista');
    pintarContadores();
    pintarLista();
}

function limpiarBusqueda() {
    $('#buscar').value = '';
    ESTADO.busqueda = '';
    pintarContadores();
    ESTADO.vista === 'calendario' ? pintarCalendario() : pintarLista();
    $('#buscar').focus();
}

// ============================================================
// Datos: recarga y sincronizacion
// ============================================================

async function recargar(silencioso = false) {
    try {
        const [reservas, sync] = await Promise.all([
            pedir('api/get_data.php'),
            pedir('api/sync_estado.php').catch(() => null),
        ]);
        DATOS.reservas = reservas;
        if (sync && sync.success) DATOS.ultimoSync = sync.cuando ? sync : null;
        ultimaCarga = Date.now();
        pintarTodo();
        pintarEstadoSync();
    } catch (e) {
        if (!silencioso) aviso('No se pudieron recargar las reservas: ' + e.message, 'error');
    }
}

function pintarEstadoSync() {
    const el = $('#estadoSync');
    const cuando = DATOS.ultimoSync && DATOS.ultimoSync.cuando ? new Date(DATOS.ultimoSync.cuando) : null;
    if (!cuando || isNaN(cuando)) {
        el.className = 'estado-sync viejo';
        el.innerHTML = '<span class="punto"></span><span>Sin sincronizar todavía</span>';
        return;
    }
    const minutos = Math.round((Date.now() - cuando) / 60000);
    let texto;
    if (minutos < 1) texto = 'ahora mismo';
    else if (minutos < 60) texto = FMT.relativo.format(-minutos, 'minute');
    else if (minutos < 48 * 60) texto = FMT.relativo.format(-Math.round(minutos / 60), 'hour');
    else texto = FMT.relativo.format(-Math.round(minutos / 1440), 'day');
    // El cron pasa cada 6 h: mas de 30 h sin exito casi seguro es la sesion de Holidu
    el.className = 'estado-sync' + (minutos > 30 * 60 ? ' viejo' : '');
    el.title = `Última sincronización correcta: ${cuando.toLocaleString('es-ES')}`;
    el.innerHTML = `<span class="punto"></span><span>Sincronizado ${esc(texto)}</span>`;
}

async function sincronizar() {
    const boton = $('#btnSync');
    if (boton.disabled) return;
    boton.disabled = true;
    boton.classList.add('girando');
    aviso('Sincronizando con Holidu…', 'info', { duracion: 3000 });
    try {
        const datos = await pedir('api/sync.php', { headers: { 'X-CSRF-Token': CSRF } });
        registroSync = datos.output || '';
        if (datos.success) {
            const m = registroSync.match(/Creadas:\s*(\d+)\s*\|\s*Actualizadas:\s*(\d+)/);
            const [nuevas, cambiadas] = m ? [Number(m[1]), Number(m[2])] : [0, 0];
            const detalle = nuevas || cambiadas
                ? [nuevas ? `${nuevas} nueva${nuevas === 1 ? '' : 's'}` : '', cambiadas ? `${cambiadas} actualizada${cambiadas === 1 ? '' : 's'}` : ''].filter(Boolean).join(', ')
                : 'sin cambios';
            aviso(`Sincronizado: ${detalle}.`, 'ok');
            await recargar();
        } else if (/sesi[oó]n de Holidu/i.test(registroSync)) {
            aviso('La sesión de Holidu ha caducado.', 'error', { accion: { texto: 'Iniciar sesión en Holidu', fn: abrirHolidu } });
        } else {
            aviso('La sincronización ha fallado.', 'error', { accion: { texto: 'Ver registro', fn: abrirRegistro } });
        }
    } catch (e) {
        aviso('Error al sincronizar: ' + e.message, 'error');
    } finally {
        boton.disabled = false;
        boton.classList.remove('girando');
    }
}

// ============================================================
// Mensaje del formulario: copiar / WhatsApp
// ============================================================

/** La plantilla (MENSAJE_FORMULARIO) y el nombre (NOMBRE_ALOJAMIENTO) los pone index.php. */
function mensajeFormulario(r) {
    return PLANTILLA
        .replaceAll('{nombre}', r.nombre)
        .replaceAll('{alojamiento}', ALOJAMIENTO)
        .replaceAll('{enlace}', r.enlace);
}

async function copiarTexto(texto) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(texto);
        return;
    }
    // Sin HTTPS no hay API de portapapeles: el truco del textarea
    const area = document.createElement('textarea');
    area.value = texto;
    area.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand('copy');
    area.remove();
    if (!ok) throw new Error('el navegador no lo permite');
}

async function copiarMensaje(r) {
    if (!r.enlace) { aviso('Esta reserva no tiene enlace de formulario.', 'error'); return; }
    try {
        await copiarTexto(mensajeFormulario(r));
        aviso(r.formularioEnviado ? 'Mensaje copiado.' : 'Mensaje copiado. ¿Lo has enviado?', 'ok',
            r.formularioEnviado ? {} : { accion: { texto: 'Marcar formulario como enviado', fn: () => alternarFormulario(r.id) } });
    } catch (e) {
        aviso('No se pudo copiar: ' + e.message, 'error');
    }
}

// ============================================================
// Formulario enviado / no enviado
// ============================================================

async function alternarFormulario(id) {
    const r = porId(id);
    if (!r) return;
    // Optimista: se pinta ya y se deshace si falla
    const original = r.b['Formulario'];
    r.b['Formulario'] = r.formularioEnviado ? 'No enviado' : 'Enviado';
    pintarTodo();
    try {
        const datos = await escribir('api/update_form_status.php', { bookings: [{ id: r.id }] });
        if (!datos.success) throw new Error(datos.error || 'no se pudo guardar');
        aviso(`Formulario de ${capitalizar(r.nombre)}: ${r.b['Formulario'] === 'Enviado' ? 'enviado' : 'sin enviar'}.`, 'ok');
        if ($('#dlgFicha').open) abrirFicha(r.id);
    } catch (e) {
        r.b['Formulario'] = original;
        pintarTodo();
        aviso('No se pudo cambiar el formulario: ' + e.message, 'error');
    }
}

// ============================================================
// Ficha de la reserva
// ============================================================

function abrirFicha(id) {
    const r = porId(id);
    if (!r) return;
    const estado = r.cancelada ? '<span class="chip chip-sm chip-mal">Cancelada</span>' : '<span class="chip chip-sm chip-ok">Confirmada</span>';
    $('#fichaNombre').textContent = r.nombre;
    $('#fichaSub').innerHTML = `#${esc(r.id)} · <span class="canal"><i class="punto fondo-${canalClave(r.canal)}"></i>${esc(canalNombre(r.canal))}</span> · ${estado}`;

    const wa = telefonoWhatsapp(r.telefono);
    const { meta: metaEstancia } = textoEstancia(r);
    const dato = (titulo, valor) => `<div class="dato"><dt>${esc(titulo)}</dt><dd>${valor}</dd></div>`;

    $('#fichaCuerpo').innerHTML = `
        <dl class="ficha-datos">
            ${dato('Entrada', esc(fecha(r.ci, FMT.largo)))}
            ${dato('Salida', esc(fecha(r.co, FMT.largo)))}
            ${dato('Estancia', metaEstancia)}
            ${dato('Ganancia', `<span class="num">${esc(euros(r.ganancia))}</span>`)}
            ${dato('Pagado por el huésped', `<span class="num">${esc(euros(r.pagado))}</span>`)}
            ${dato('Reservada el', esc(r.reservadaEl ? fecha(r.reservadaEl, FMT.diaAnio) : '—'))}
        </dl>
        <section class="ficha-seccion">
            <h3>Contacto</h3>
            <div class="contacto">
                ${r.telefono ? `<a class="btn" href="tel:${esc(r.telefono.replace(/\s/g, ''))}">${ic('telefono')}${esc(telefonoBonito(r.telefono))}</a>` : '<span class="chip chip-neutro">Sin teléfono</span>'}
                ${wa ? `<a class="btn" href="https://wa.me/${esc(wa)}${r.enlace && !r.cancelada ? `?text=${encodeURIComponent(mensajeFormulario(r))}` : ''}" target="_blank" rel="noopener">${ic('whatsapp')}WhatsApp</a>` : ''}
                ${r.correo ? `<a class="btn" href="mailto:${esc(r.correo)}" title="${esc(r.correo)}">${ic('correo')}Correo</a>` : ''}
            </div>
        </section>
        ${r.cancelada ? '' : `<section class="ficha-seccion">
            <h3>Gestión</h3>
            <div class="ficha-estados">
                <div><span class="etiqueta">Formulario</span>${htmlFormulario(r)}</div>
                <div><span class="etiqueta">Fianza</span>${htmlFianza(r)}</div>
            </div>
            <div class="contacto">
                <button type="button" class="btn" data-accion="copiar"${r.enlace ? '' : ' disabled'}>${ic('copiar')}Copiar mensaje</button>
                ${r.enlace ? `<a class="btn" href="${esc(r.enlace)}" target="_blank" rel="noopener">${ic('enlace')}Abrir formulario</a>` : ''}
            </div>
        </section>`}`;

    $('#fichaPie').innerHTML = `
        ${r.repite ? `<span class="chip chip-violeta" style="margin-right:auto">${ic('repite', 'icono-sm')}Ya se alojó antes</span>` : ''}
        <button type="button" class="btn" data-accion="notas">${ic('nota')}Notas${r.notas ? ` (${r.notas})` : ''}</button>
        <button type="button" class="btn btn-primary" onclick="this.closest('dialog').close()">Cerrar</button>`;
    $('#dlgFicha').dataset.id = r.id;
    if (!$('#dlgFicha').open) $('#dlgFicha').showModal();
}

// ============================================================
// Fianza
// ============================================================

let fianzaActual = null;

function abrirFianza(id) {
    const r = porId(id);
    if (!r || !r.fianza.aplica) return;
    fianzaActual = r.id;
    $('#fianzaDesc').textContent = `${capitalizar(r.nombre)} · ${euros(r.fianza.importe)} · reserva #${r.id}`;
    $$('#dlgFianza input[name="fianza"]').forEach(i => { i.checked = i.value === r.fianza.estado; });
    $('#fianzaRetenido').value = r.fianza.retenido;
    $('#fianzaNota').value = r.fianza.nota;
    estadoMsg('#fianzaEstado', '');
    alternarRetenida();
    $('#dlgFianza').showModal();
}

function alternarRetenida() {
    const estado = $('#dlgFianza input[name="fianza"]:checked')?.value;
    $('#fianzaRetenida').hidden = estado !== 'retenida';
}

async function guardarFianza(ev) {
    ev.preventDefault();
    if (!fianzaActual) return;
    const boton = $('#fianzaGuardar');
    boton.disabled = true;
    estadoMsg('#fianzaEstado', 'Guardando…', 'info');
    try {
        const datos = await escribir('api/update_fianza.php', {
            id_reserva: fianzaActual,
            estado: $('#dlgFianza input[name="fianza"]:checked')?.value || 'pendiente',
            retenido: $('#fianzaRetenido').value,
            nota: $('#fianzaNota').value,
        });
        if (!datos.success) throw new Error(datos.error || 'No se pudo guardar');
        $('#dlgFianza').close();
        aviso(datos.message || 'Fianza guardada.', 'ok');
        await recargar();
        if ($('#dlgFicha').open) abrirFicha($('#dlgFicha').dataset.id);
    } catch (e) {
        estadoMsg('#fianzaEstado', e.message, 'error');
    } finally {
        boton.disabled = false;
    }
}

function estadoMsg(sel, texto, tipo = '') {
    const el = $(sel);
    el.textContent = texto;
    el.className = 'estado-msg' + (tipo ? ' ' + tipo : '');
}

// ============================================================
// Notas del huesped
// ============================================================
// Van pegadas al huesped (su telefono normalizado, 'Huesped clave'), no a la
// reserva: por eso salen igual en todas las reservas de la misma persona.

let notasActual = null;     // { id, clave, nombre }
let notaEditando = null;    // id de la nota en edicion

function abrirNotas(id) {
    const r = porId(id);
    if (!r) return;
    notasActual = { id: r.id, clave: r.clave, nombre: r.nombre };
    cancelarEdicionNota();
    $('#notasDesc').textContent = `${capitalizar(r.nombre)} · se ven en todas sus reservas`;
    $('#notasLista').innerHTML = '';
    estadoMsg('#notasEstado', 'Cargando notas…', 'info');
    $('#dlgNotas').showModal();
    cargarNotas();
}

async function cargarNotas() {
    if (!notasActual) return;
    try {
        const datos = await pedir('api/notas_listar.php?clave=' + encodeURIComponent(notasActual.clave));
        if (!datos.success) throw new Error(datos.error || 'No se pudieron leer las notas');
        estadoMsg('#notasEstado', '');
        pintarNotas(datos.notas || []);
    } catch (e) {
        estadoMsg('#notasEstado', e.message, 'error');
    }
}

function pintarNotas(notas) {
    const caja = $('#notasLista');
    if (!notas.length) {
        caja.innerHTML = '<p class="notas-vacio">Aún no hay notas de este huésped.</p>';
        return;
    }
    // Las mas recientes arriba
    caja.innerHTML = notas.slice().reverse().map(n => `
        <div class="nota" data-nota="${esc(n.id)}">
            <div class="nota-texto">${esc(n.texto)}</div>
            <div class="nota-pie">
                <span>${esc(n.fecha)}</span>
                ${n.origen === 'asistente' ? '<span class="chip chip-sm chip-info">asistente</span>' : ''}
                ${n.id_reserva && n.id_reserva !== notasActual.id ? `<span>· de la reserva #${esc(n.id_reserva)}</span>` : ''}
                <span class="acciones">
                    <button type="button" class="btn-icono" data-nota-accion="editar" title="Editar" aria-label="Editar nota">${ic('lapiz', 'icono-sm')}</button>
                    <button type="button" class="btn-icono" data-nota-accion="borrar" title="Borrar" aria-label="Borrar nota">${ic('papelera', 'icono-sm')}</button>
                </span>
            </div>
        </div>`).join('');
    caja._notas = notas;
}

function cancelarEdicionNota() {
    notaEditando = null;
    $('#notasTexto').value = '';
    $('#notasEtiqueta').textContent = 'Nueva nota';
    $('#notasGuardar').textContent = 'Añadir nota';
    $('#notasCancelar').hidden = true;
}

async function guardarNota() {
    if (!notasActual) return;
    const texto = $('#notasTexto').value.trim();
    if (!texto) { estadoMsg('#notasEstado', 'Escribe algo antes de guardar.', 'error'); $('#notasTexto').focus(); return; }
    const boton = $('#notasGuardar');
    boton.disabled = true;
    estadoMsg('#notasEstado', 'Guardando…', 'info');
    // Con id_nota edita; sin el, crea una nueva sobre esta reserva
    const cuerpo = notaEditando ? { id_nota: notaEditando, texto } : { id_reserva: notasActual.id, texto };
    try {
        const datos = await escribir('api/notas_guardar.php', cuerpo);
        if (!datos.success) throw new Error(datos.error || 'No se pudo guardar');
        cancelarEdicionNota();
        await cargarNotas();
        estadoMsg('#notasEstado', datos.message || 'Nota guardada', 'ok');
        recargar(true);
    } catch (e) {
        estadoMsg('#notasEstado', e.message, 'error');
    } finally {
        boton.disabled = false;
    }
}

async function accionNota(boton) {
    const caja = boton.closest('.nota');
    const nota = ($('#notasLista')._notas || []).find(n => String(n.id) === caja.dataset.nota);
    if (!nota) return;
    if (boton.dataset.notaAccion === 'editar') {
        notaEditando = nota.id;
        $('#notasTexto').value = nota.texto;
        $('#notasEtiqueta').textContent = 'Editando nota';
        $('#notasGuardar').textContent = 'Guardar cambios';
        $('#notasCancelar').hidden = false;
        $('#notasTexto').focus();
        return;
    }
    // Borrar no se puede deshacer desde el panel
    if (!confirm('¿Borrar esta nota? No se puede deshacer.')) return;
    boton.disabled = true;
    try {
        const datos = await escribir('api/notas_borrar.php', { id_nota: nota.id });
        if (!datos.success) throw new Error(datos.error || 'No se pudo borrar');
        await cargarNotas();
        estadoMsg('#notasEstado', datos.message || 'Nota borrada', 'ok');
        recargar(true);
    } catch (e) {
        estadoMsg('#notasEstado', e.message, 'error');
        boton.disabled = false;
    }
}

// ============================================================
// Login de Holidu
// ============================================================

let holiduTemporizador = null;

function abrirHolidu() {
    cerrarMenu();
    $('#holiduIniciar').hidden = false;
    $('#holiduIniciar').disabled = false;
    $('#holiduEnviar').hidden = true;
    $('#holidu2fa').hidden = true;
    $('#holiduCodigo').value = '';
    estadoMsg('#holiduEstado', '');
    $('#dlgHolidu').showModal();
}

async function iniciarHolidu() {
    $('#holiduIniciar').disabled = true;
    estadoMsg('#holiduEstado', 'Conectando con Holidu…', 'info');
    try {
        const datos = await pedir('api/holidu_login_start.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
        if (!datos.success) throw new Error(datos.error || 'No se pudo iniciar');
        vigilarHolidu();
    } catch (e) {
        estadoMsg('#holiduEstado', e.message, 'error');
        $('#holiduIniciar').disabled = false;
    }
}

function vigilarHolidu() {
    clearInterval(holiduTemporizador);
    holiduTemporizador = setInterval(async () => {
        let datos;
        try { datos = await pedir('api/holidu_login_status.php'); } catch (e) { return; }
        const estado = datos.state;
        if (estado === 'running') {
            estadoMsg('#holiduEstado', datos.message || 'Procesando…', 'info');
            $('#holiduIniciar').hidden = true;
        } else if (estado === 'waiting_2fa') {
            estadoMsg('#holiduEstado', datos.message || 'Introduce el código enviado a tu correo.', 'info');
            $('#holiduIniciar').hidden = true;
            if ($('#holidu2fa').hidden) {
                $('#holidu2fa').hidden = false;
                $('#holiduEnviar').hidden = false;
                $('#holiduEnviar').disabled = false;
                $('#holiduCodigo').focus();
            }
        } else if (estado === 'success') {
            clearInterval(holiduTemporizador);
            estadoMsg('#holiduEstado', datos.message || 'Sesión iniciada.', 'ok');
            $('#holidu2fa').hidden = true;
            $('#holiduEnviar').hidden = true;
            aviso('Sesión de Holidu iniciada.', 'ok', { accion: { texto: 'Sincronizar ahora', fn: sincronizar } });
        } else if (estado === 'error') {
            clearInterval(holiduTemporizador);
            estadoMsg('#holiduEstado', datos.message || 'Error al iniciar sesión.', 'error');
            $('#holiduIniciar').hidden = false;
            $('#holiduIniciar').disabled = false;
            $('#holidu2fa').hidden = true;
            $('#holiduEnviar').hidden = true;
        }
    }, 2000);
}

async function enviarCodigoHolidu() {
    const codigo = $('#holiduCodigo').value.trim();
    if (!codigo) { estadoMsg('#holiduEstado', 'Escribe el código.', 'error'); return; }
    $('#holiduEnviar').disabled = true;
    try {
        const datos = await escribir('api/holidu_login_code.php', { code: codigo });
        if (!datos.success) throw new Error(datos.error || 'Código no válido');
        estadoMsg('#holiduEstado', 'Verificando código…', 'info');
        $('#holidu2fa').hidden = true;
        $('#holiduEnviar').hidden = true;
    } catch (e) {
        estadoMsg('#holiduEstado', e.message, 'error');
        $('#holiduEnviar').disabled = false;
    }
}

// ============================================================
// Registro, menu y tema
// ============================================================

function abrirRegistro() {
    cerrarMenu();
    $('#registroTexto').textContent = registroSync || 'Aún no se ha sincronizado desde este navegador. Pulsa «Sincronizar» y aquí verás todo lo que ha hecho.';
    $('#dlgRegistro').showModal();
}

function alternarMenu() {
    const lista = $('#menuLista');
    lista.hidden = !lista.hidden;
    $('#btnMenu').setAttribute('aria-expanded', String(!lista.hidden));
}
function cerrarMenu() {
    $('#menuLista').hidden = true;
    $('#btnMenu').setAttribute('aria-expanded', 'false');
}

const temaActual = () => document.documentElement.dataset.theme
    || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

function pintarBotonTema() {
    const oscuro = temaActual() === 'dark';
    $('#btnTema').innerHTML = ic(oscuro ? 'sol' : 'luna');
    $('#btnTema').title = oscuro ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro';
}
function cambiarTema() {
    const nuevo = temaActual() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = nuevo;
    guardarPref('tema', nuevo);
    pintarBotonTema();
}

// ============================================================
// Eventos
// ============================================================

// Acciones de las filas y de la ficha, con un solo manejador
document.addEventListener('click', ev => {
    const boton = ev.target.closest('[data-accion]');
    if (boton) {
        const id = boton.closest('[data-id]')?.dataset.id;
        const r = porId(id);
        if (!r) return;
        switch (boton.dataset.accion) {
            case 'ficha': abrirFicha(id); break;
            case 'fianza': abrirFianza(id); break;
            case 'formulario': alternarFormulario(id); break;
            case 'copiar': copiarMensaje(r); break;
            case 'notas': abrirNotas(id); break;
        }
        return;
    }
    const ficha = ev.target.closest('[data-ficha]');
    if (ficha) { abrirFicha(ficha.dataset.ficha); return; }
    if (ev.target.closest('[data-ir]')) { cambiarFiltro(ev.target.closest('[data-ir]').dataset.ir); return; }
    const notaBoton = ev.target.closest('[data-nota-accion]');
    if (notaBoton) { accionNota(notaBoton); return; }
    const mitad = ev.target.closest('.cal-mitad[data-id]');
    if (mitad) { abrirFicha(mitad.dataset.id); return; }
    if (!ev.target.closest('.menu')) cerrarMenu();
});

// Un clic en el fondo de un dialogo lo cierra
$$('dialog.dialogo').forEach(d => d.addEventListener('click', ev => { if (ev.target === d) d.close(); }));
$('#dlgHolidu').addEventListener('close', () => clearInterval(holiduTemporizador));
$('#dlgNotas').addEventListener('close', () => { notasActual = null; });
$$('#dlgFianza input[name="fianza"]').forEach(i => i.addEventListener('change', alternarRetenida));

$$('#pestanas .pestana').forEach(p => p.addEventListener('click', () => cambiarFiltro(p.dataset.filtro)));
$$('.vistas .pestana').forEach(p => p.addEventListener('click', () => cambiarVista(p.dataset.vista)));

let temporizadorBusqueda;
$('#buscar').addEventListener('input', ev => {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(() => {
        ESTADO.busqueda = ev.target.value;
        pintarContadores();
        ESTADO.vista === 'calendario' ? pintarCalendario() : pintarLista();
    }, 120);
});

document.addEventListener('keydown', ev => {
    const escribiendo = ev.target.closest('input, textarea, select, [contenteditable]');
    if (ev.key === '/' && !escribiendo && !document.querySelector('dialog[open]')) {
        ev.preventDefault();
        $('#buscar').focus();
    } else if (ev.key === 'Escape') {
        if (ev.target.id === 'buscar' && ev.target.value) limpiarBusqueda();
        cerrarMenu();
    }
});

// Al volver a la pestana tras un rato, datos frescos sin pedirlo
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && Date.now() - ultimaCarga > 5 * 60 * 1000) recargar(true);
});
setInterval(pintarEstadoSync, 60 * 1000);
matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', pintarBotonTema);

// ============================================================
// Arranque
// ============================================================

try {
    const iniciales = JSON.parse($('#datosIniciales').textContent);
    DATOS.reservas = iniciales.reservas;
    DATOS.ultimoSync = iniciales.ultimoSync && iniciales.ultimoSync.cuando ? iniciales.ultimoSync : null;
} catch (e) {
    DATOS.reservas = { error: 'Datos iniciales ilegibles' };
}
pintarBotonTema();
pintarEstadoSync();
modelo();
pintarResumen();
pintarContadores();
cambiarVista(ESTADO.vista);
