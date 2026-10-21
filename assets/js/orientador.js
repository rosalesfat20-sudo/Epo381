"use strict";

let ORI_USER = null;
let ORI_GRUPOS = [];
let ORI_GRUPO_ID = null;
let ORI_ALUMNOS_CACHE = [];
let ORI_MATERIAS_CACHE = [];
let ORI_CALIF_CACHE = [];
let ORI_REP_TIPO = 'concentrado';

async function oriInit() {
    try {
        const r = await fetch('api/session_check.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok || d.user.rol !== 'orientador') { window.location.href = 'index.html'; return; }
        ORI_USER = d.user;
    } catch (e) { window.location.href = 'index.html'; return; }

    document.getElementById('ori-nombre-top').innerText = ORI_USER.nombre + ' ' + ORI_USER.apellido_paterno;
    document.getElementById('oc-fecha').value = new Date().toISOString().slice(0, 10);

    const r = await fetch('api/orientador/grupos.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { oriAlert(d.error, 'err'); return; }
    ORI_GRUPOS = d.grupos;

    const sel = document.getElementById('ori-grupo-actual');
    if (!ORI_GRUPOS.length) {
        sel.innerHTML = '<option>Sin grupos asignados</option>';
        oriAlert('El administrador aún no te ha asignado ningún grupo.', 'err');
        return;
    }
    sel.innerHTML = ORI_GRUPOS.map(g => `<option value="${g.grupo_id}">${g.nombre} (${g.turno})</option>`).join('');
    ORI_GRUPO_ID = ORI_GRUPOS[0].grupo_id;
    await oriCambioGrupo();

    // Actualiza el historial de conducta y los reportes académicos automáticamente
    // (sin recargar la página). No se toca la tabla de captura de calificaciones
    // para no perder cambios que el orientador esté escribiendo en ese momento.
    setInterval(async () => {
        if (!ORI_GRUPO_ID) return;
        const panelActivo = document.querySelector('.pw-tab-content.active')?.id;
        if (panelActivo === 'panel-conducta' && !ORI_EDITANDO_REPORTE) await oriCargarConducta();
        if (panelActivo === 'panel-reportes') await oriCargarReporte();
    }, 20000);
}

async function oriCambioGrupo() {
    ORI_GRUPO_ID = document.getElementById('ori-grupo-actual').value;
    await oriCargarAlumnos();
    await oriCargarMateriasYCalif();
    await oriCargarConducta();
    if (document.getElementById('panel-reportes').classList.contains('active')) await oriCargarReporte();
}

/* ───────────── ALUMNOS Y FICHAS ───────────── */
async function oriCargarAlumnos() {
    const r = await fetch(`api/orientador/alumnos.php?grupo_id=${ORI_GRUPO_ID}`, { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('ori-tbody-alumnos');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="5" class="pw-empty">${d.error}</td></tr>`; return; }
    ORI_ALUMNOS_CACHE = d.alumnos;
    document.getElementById('ori-count-alumnos').innerText = d.alumnos.length;

    tbody.innerHTML = d.alumnos.length ? d.alumnos.map(a => `<tr>
        <td>${a.matricula}</td><td>${a.nombre}</td>
        <td><span class="pw-pill ${a.perfil_completo ? 'green' : 'gray'}">${a.perfil_completo ? 'Completo' : 'Pendiente'}</span></td>
        <td><span class="pw-pill ${a.estatus === 'activo' ? 'green' : 'red'}">${a.estatus}</span></td>
        <td><button class="pw-btn pw-btn-blue" onclick="oriVerFicha(${a.alumno_id})">👁️ Ver ficha</button></td>
    </tr>`).join('') : '<tr><td colspan="5" class="pw-empty">No hay alumnos en este grupo.</td></tr>';

    // Refrescar selects de alumno en otras pestañas
    const opciones = d.alumnos.map(a => `<option value="${a.alumno_id}">${a.nombre} (${a.matricula})</option>`).join('');
    document.getElementById('rep-alumno-sel').innerHTML = opciones;
    document.getElementById('oc-alumno').innerHTML = opciones;
}

async function oriVerFicha(alumnoId) {
    const modal = document.getElementById('modal-ficha');
    const body = document.getElementById('modal-ficha-body');
    body.innerHTML = 'Cargando...';
    modal.classList.add('open');
    const r = await fetch(`api/orientador/alumnos.php?alumno_id=${alumnoId}&grupo_id=${ORI_GRUPO_ID}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { body.innerHTML = `<p>${d.error}</p>`; return; }
    const f = d.ficha;
    const fila = (lbl, val) => `<div class="pw-fgrp" style="margin-bottom:8px;"><span class="pw-flbl">${lbl}</span><div style="font-weight:600;">${val ?? '—'}</div></div>`;
    body.innerHTML = `
        <div class="pw-form-row">
            ${fila('Nombre', `${f.nombre} ${f.apellido_paterno} ${f.apellido_materno || ''}`)}
            ${fila('Matrícula', f.matricula)}
            ${fila('Grupo', f.grupo_nombre)}
        </div>
        <div class="pw-form-row">
            ${fila('CURP', f.curp)}${fila('Fecha de nacimiento', f.fecha_nacimiento)}${fila('Sexo', f.sexo)}
        </div>
        <div class="pw-form-row">
            ${fila('Teléfono', f.telefono)}${fila('Correo', f.email_personal)}
        </div>
        <div class="pw-form-row">
            ${fila('Tutor', f.tutor_nombre)}${fila('Tel. tutor', f.tutor_telefono)}
        </div>
        <hr style="margin:14px 0;border:none;border-top:1px solid #e2e8f0;">
        <div class="pw-form-row">
            ${fila('Domicilio', `${f.calle || ''} ${f.numero || ''}, ${f.colonia || ''}`)}
            ${fila('Municipio / Estado', `${f.municipio || ''}, ${f.estado || ''}`)}${fila('C.P.', f.cp)}
        </div>
        <hr style="margin:14px 0;border:none;border-top:1px solid #e2e8f0;">
        <div class="pw-form-row">
            ${fila('Escuela de procedencia', f.escuela_procedencia)}
            ${fila('Municipio', f.municipio_procedencia)}${fila('Promedio secundaria', f.promedio_secundaria)}
        </div>
    `;
}

/* ───────────── CAPTURA DE CALIFICACIONES ───────────── */
async function oriCargarMateriasYCalif() {
    const rm = await fetch(`api/orientador/materias_grupo.php?grupo_id=${ORI_GRUPO_ID}`, { credentials: 'same-origin' });
    const dm = await rm.json();
    if (!dm.ok) { oriAlert(dm.error, 'err'); return; }
    ORI_MATERIAS_CACHE = dm.materias;

    const rc = await fetch(`api/orientador/calificaciones.php?grupo_id=${ORI_GRUPO_ID}`, { credentials: 'same-origin' });
    const dc = await rc.json();
    ORI_CALIF_CACHE = dc.ok ? dc.alumnos : [];

    oriRenderCaptura();
}

/** Genera las <option> de calificación: vacío (sin capturar) + 5.1, 5.2 ... 9.9 + 10 */
function oriOpcionesCalificacion(valorActual) {
    let opts = `<option value=""></option>`;
    for (let entero = 5; entero <= 9; entero++) {
        for (let decimo = 1; decimo <= 9; decimo++) {
            const val = `${entero}.${decimo}`;
            opts += `<option value="${val}" ${valorActual == val ? 'selected' : ''}>${val}</option>`;
        }
    }
    opts += `<option value="10" ${valorActual == '10' ? 'selected' : ''}>10</option>`;
    return opts;
}

function oriRenderCaptura() {
    const thead = document.querySelector('#cap-tabla thead tr');
    const tbody = document.getElementById('cap-tbody');
    const periodo = document.getElementById('cap-periodo').value;

    if (!ORI_MATERIAS_CACHE.length || !ORI_CALIF_CACHE.length) {
        thead.innerHTML = '<th>Alumno</th>';
        tbody.innerHTML = '<tr><td class="pw-empty">No hay materias asignadas a este grupo, o no hay alumnos.</td></tr>';
        return;
    }

    thead.innerHTML = '<th>Alumno</th>' + ORI_MATERIAS_CACHE.map(m => `<th>${m.nombre}</th>`).join('');
    tbody.innerHTML = ORI_CALIF_CACHE.map(al => {
        const celdas = ORI_MATERIAS_CACHE.map(m => {
            const existente = al.calificaciones.find(c => c.materia_id == m.id && c.periodo_id == periodo);
            const val = existente ? existente.calificacion : '';
            const faltas = existente ? existente.faltas : 0;
            return `<td>
                <select class="pw-sel cap-input" style="width:80px;padding:6px 8px;"
                    data-alumno="${al.alumno_id}" data-materia="${m.id}">${oriOpcionesCalificacion(val)}</select>
                <input class="pw-inp cap-faltas" type="number" min="0" max="99" title="Faltas"
                    style="width:80px;padding:4px 6px;margin-top:4px;font-size:.8rem;" placeholder="Faltas"
                    data-alumno="${al.alumno_id}" data-materia="${m.id}" value="${faltas || ''}">
            </td>`;
        }).join('');
        return `<tr><td>${al.nombre}</td>${celdas}</tr>`;
    }).join('');
}

document.addEventListener('change', e => { if (e.target && e.target.id === 'cap-periodo') oriRenderCaptura(); });

async function oriGuardarCalificaciones() {
    const periodo = document.getElementById('cap-periodo').value;
    const inputs = document.querySelectorAll('.cap-input');
    const capturas = [];
    inputs.forEach(inp => {
        const faltasInp = document.querySelector(`.cap-faltas[data-alumno="${inp.dataset.alumno}"][data-materia="${inp.dataset.materia}"]`);
        const faltas = faltasInp && faltasInp.value !== '' ? parseInt(faltasInp.value, 10) : 0;
        if (inp.value !== '') {
            capturas.push({ alumno_id: inp.dataset.alumno, materia_id: inp.dataset.materia, periodo_id: periodo, calificacion: inp.value, faltas });
        }
    });
    if (!capturas.length) { oriAlert('No hay calificaciones para guardar.', 'err'); return; }

    const r = await fetch('api/orientador/calificaciones.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ grupo_id: ORI_GRUPO_ID, capturas }),
    });
    const d = await r.json();
    if (!d.ok) { oriAlert(d.error, 'err'); return; }
    oriAlert('✅ ' + d.mensaje, 'ok');
    await oriCargarMateriasYCalif();
}

/* ───────────── REPORTES ACADÉMICOS ───────────── */
function oriRepTab(tipo) {
    ORI_REP_TIPO = tipo;
    document.querySelectorAll('.pw-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.rep === tipo));
    document.getElementById('rep-boleta-selector').style.display = tipo === 'boleta' ? 'block' : 'none';
    oriCargarReporte();
}

async function oriCargarReporte() {
    const cont = document.getElementById('rep-contenido');
    cont.innerHTML = '<div class="pw-empty">Cargando...</div>';
    let url = `api/orientador/reportes.php?tipo=${ORI_REP_TIPO}&grupo_id=${ORI_GRUPO_ID}`;
    if (ORI_REP_TIPO === 'boleta') {
        const alumnoId = document.getElementById('rep-alumno-sel').value;
        if (!alumnoId) { cont.innerHTML = '<div class="pw-empty">No hay alumnos en este grupo.</div>'; return; }
        url += `&alumno_id=${alumnoId}`;
    }
    const r = await fetch(url, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { cont.innerHTML = `<div class="pw-empty">${d.error}</div>`; return; }

    if (ORI_REP_TIPO === 'concentrado') cont.innerHTML = oriRenderConcentrado(d.concentrado);
    if (ORI_REP_TIPO === 'reprobados')  cont.innerHTML = oriRenderReprobados(d.reprobados);
    if (ORI_REP_TIPO === 'indicadores') cont.innerHTML = oriRenderIndicadores(d.indicadores);
    if (ORI_REP_TIPO === 'honor')       cont.innerHTML = oriRenderHonor(d.honor);
    if (ORI_REP_TIPO === 'irregulares') { ORI_IRREGULARES_CACHE = d.irregulares; cont.innerHTML = oriRenderIrregulares(d.irregulares); }
    if (ORI_REP_TIPO === 'boleta')      cont.innerHTML = oriRenderBoleta(d.boleta);
}

function oriRenderConcentrado(c) {
    if (!c.alumnos.length) return '<div class="pw-empty">No hay datos.</div>';
    const head = '<th>Matrícula</th><th>Alumno</th>' + c.materias.map(m => `<th>${m}</th>`).join('') + '<th>Promedio</th><th>Faltas</th>';
    const rows = c.alumnos.map(a => {
        const celdas = c.materias.map(m => {
            const p = a.materias[m]?.promedio ?? null;
            const cls = p === null ? 'pw-cal-nd' : (p >= 6 ? 'pw-cal-aprov' : 'pw-cal-repro');
            return `<td><span class="pw-cal-num ${cls}">${p ?? '—'}</span></td>`;
        }).join('');
        return `<tr><td>${a.matricula}</td><td>${a.nombre}</td>${celdas}<td><b>${a.promedio_general ?? '—'}</b></td><td>${a.total_faltas ?? 0}</td></tr>`;
    }).join('');
    return `<div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table></div></div>`;
}

function oriRenderReprobados(r) {
    if (!r.length) return '<div class="pw-empty">🎉 No hay alumnos reprobados en este grupo.</div>';
    const rows = r.map(a => `<tr>
        <td>${a.matricula}</td><td>${a.nombre}</td>
        <td>${a.materias_reprobadas.map(m => `${m.materia} (${m.promedio})`).join(', ')}</td>
        <td>${a.total_reprobadas}</td><td><span class="pw-cal-num pw-cal-repro">${a.promedio_general}</span></td>
    </tr>`).join('');
    return `<div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table"><thead><tr><th>Matrícula</th><th>Alumno</th><th>Materias Reprobadas</th><th>Total</th><th>Promedio</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
}

function oriRenderIndicadores(i) {
    let materiasRows = Object.entries(i.por_materia).map(([mat, d]) =>
        `<tr><td>${mat}</td><td>${d.promedio ?? '—'}</td><td>${d.porcentaje_aprobacion ?? '—'}%</td></tr>`).join('');
    return `
    <div class="pw-stats">
        <div class="pw-stat blue"><div class="pw-stat-ico">👥</div><div><div class="pw-stat-val">${i.total_alumnos}</div><div class="pw-stat-lbl">Alumnos</div></div></div>
        <div class="pw-stat amber"><div class="pw-stat-ico">📈</div><div><div class="pw-stat-val">${i.promedio_grupal ?? '—'}</div><div class="pw-stat-lbl">Promedio grupal</div></div></div>
        <div class="pw-stat" style="border-left-color:#dc2626;"><div class="pw-stat-ico">❌</div><div><div class="pw-stat-val">${i.total_reprobados}</div><div class="pw-stat-lbl">Reprobados</div></div></div>
        <div class="pw-stat green"><div class="pw-stat-ico">✅</div><div><div class="pw-stat-val">${i.porcentaje_aprobacion ?? '—'}%</div><div class="pw-stat-lbl">% Aprobación</div></div></div>
    </div>
    <div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table"><thead><tr><th>Materia</th><th>Promedio</th><th>% Aprobación</th></tr></thead><tbody>${materiasRows}</tbody></table></div></div>`;
}

function oriRenderHonor(h) {
    if (!h.length) return '<div class="pw-empty">Aún no hay calificaciones capturadas para este grupo.</div>';
    const rows = h.map(r => `<tr>
        <td>${r.lugar}°</td><td>${r.matricula}</td><td>${r.nombre}</td>
        <td><b>${r.etiqueta}</b></td><td><b>${r.promedio_general}</b></td>
    </tr>`).join('');
    return `<div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table"><thead><tr><th>Lugar</th><th>Matrícula</th><th>Alumno</th><th>Reconocimiento</th><th>Promedio</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
}

let ORI_IRREGULARES_CACHE = [];

function oriRenderIrregulares(list) {
    if (!list.length) return '<div class="pw-empty">🎉 No hay alumnos irregulares (reprobados) en este grupo.</div>';

    const filas = [];
    list.forEach(al => {
        Object.entries(al.materias).forEach(([mat, d]) => {
            const intentosTxt = d.intentos.length
                ? d.intentos.map(i => `${i.oportunidad}ª: ${i.calificacion ?? '—'}${i.fecha ? ' (' + i.fecha + ')' : ''}`).join(' · ')
                : 'Sin intentos registrados';
            filas.push(`<tr>
                <td>${al.matricula}</td><td>${al.nombre}</td><td>${mat}</td><td>${d.promedio_original}</td>
                <td>${intentosTxt}</td>
                <td>${d.regularizada ? '<span class="pw-pill green">Regularizada</span>' : '<span class="pw-pill red">Pendiente</span>'}</td>
                <td><button class="pw-btn pw-btn-blue" style="padding:4px 10px;font-size:.8rem;"
                    onclick="oriAbrirFormExtra(${al.alumno_id}, ${d.materia_id}, '${admEscLike(al.nombre)}', '${admEscLike(mat)}')">📝 Registrar</button></td>
            </tr>`);
        });
    });

    return `
    <div class="pw-card" id="extra-form-card" style="display:none;">
        <h3>Registrar oportunidad de regularización</h3>
        <p id="extra-form-info" style="color:#64748b;margin-bottom:10px;"></p>
        <div class="pw-form-row">
            <div class="pw-fgrp"><span class="pw-flbl">Oportunidad</span>
                <select class="pw-sel" id="extra-oportunidad"><option value="1">1ª Oportunidad</option><option value="2">2ª Oportunidad</option><option value="3">3ª Oportunidad</option></select>
            </div>
            <div class="pw-fgrp"><span class="pw-flbl">Calificación (0-10, opcional si aún no se aplica)</span><input class="pw-inp" type="number" min="0" max="10" step="0.1" id="extra-calificacion"></div>
            <div class="pw-fgrp"><span class="pw-flbl">Fecha</span><input class="pw-inp" type="date" id="extra-fecha"></div>
        </div>
        <div style="display:flex;gap:10px;">
            <button class="pw-btn pw-btn-red" onclick="oriGuardarExtra()">💾 Guardar</button>
            <button class="pw-btn pw-btn-gray" onclick="document.getElementById('extra-form-card').style.display='none';">Cancelar</button>
        </div>
    </div>
    <div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table">
        <thead><tr><th>Matrícula</th><th>Alumno</th><th>Materia</th><th>Promedio original</th><th>Intentos</th><th>Estatus</th><th>Acciones</th></tr></thead>
        <tbody>${filas.join('')}</tbody>
    </table></div></div>`;
}

function admEscLike(s) { return String(s).replace(/'/g, "\\'"); }

let ORI_EXTRA_ALUMNO = null, ORI_EXTRA_MATERIA = null;

function oriAbrirFormExtra(alumnoId, materiaId, nombre, materia) {
    ORI_EXTRA_ALUMNO = alumnoId; ORI_EXTRA_MATERIA = materiaId;
    document.getElementById('extra-form-info').innerText = `${nombre} — ${materia}`;
    document.getElementById('extra-form-card').style.display = 'block';
    document.getElementById('extra-form-card').scrollIntoView({ behavior: 'smooth' });
}

async function oriGuardarExtra() {
    const payload = {
        alumno_id: ORI_EXTRA_ALUMNO, materia_id: ORI_EXTRA_MATERIA, grupo_id: ORI_GRUPO_ID,
        oportunidad: document.getElementById('extra-oportunidad').value,
        calificacion: document.getElementById('extra-calificacion').value,
        fecha_regularizacion: document.getElementById('extra-fecha').value,
    };
    const r = await fetch('api/orientador/extraordinarios.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (!d.ok) { oriAlert(d.error, 'err'); return; }
    oriAlert('✅ ' + d.mensaje, 'ok');
    document.getElementById('extra-form-card').style.display = 'none';
    await oriCargarReporte();
}

function oriRenderBoleta(b) {
    if (!b.alumno) return '<div class="pw-empty">Alumno no encontrado.</div>';
    const rows = Object.entries(b.materias).map(([mat, d]) => `<tr>
        <td>${mat}</td><td>${d.parciales[1] ?? '—'}</td><td>${d.parciales[2] ?? '—'}</td><td>${d.parciales[3] ?? '—'}</td>
        <td><b>${d.promedio ?? '—'}</b></td><td>${d.estatus}</td>
    </tr>`).join('');
    return `
    <div class="pw-card" style="text-align:center;">
        <p><b>ESCUELA PREPARATORIA OFICIAL NÚM. 381 DE TENANCINGO / TURNO MATUTINO</b><br>15EBP0025F</p>
        <p style="border:1px solid #8e1b22;border-radius:8px;padding:8px 12px;font-size:.85rem;">
            <b>INFORME DE CALIFICACIONES (PARA USO EXCLUSIVO DE ORIENTACIÓN). NO ES UNA BOLETA NI COMPROBANTE DE INSCRIPCIÓN.
            Para trámites oficiales, favor de dirigirse con Control Escolar.</b>
        </p>
    </div>
    <div class="pw-card">
        <p><b>Alumno:</b> ${b.alumno.nombre} ${b.alumno.apellido_paterno} ${b.alumno.apellido_materno || ''} &nbsp; <b>Matrícula:</b> ${b.alumno.matricula}</p>
    </div>
    <div class="pw-table-wrap"><div style="overflow-x:auto;"><table class="pw-table">
        <thead><tr><th>Asignatura</th><th>P1</th><th>P2</th><th>P3</th><th>Calif. Final</th><th>Estatus</th></tr></thead>
        <tbody>${rows}</tbody>
    </table></div></div>
    <div class="pw-card"><b>Promedio General:</b> ${b.promedio_general ?? '—'}</div>`;
}

function oriDescargar(formato) {
    let url;
    if (formato === 'todos') {
        url = `api/export/todos.php?grupo_id=${ORI_GRUPO_ID}`;
        window.open(url, '_blank');
        return;
    }
    if (formato === 'pdf') {
        url = `api/export/imprimir.php?tipo=${ORI_REP_TIPO}&grupo_id=${ORI_GRUPO_ID}`;
    } else {
        url = `api/export/excel.php?tipo=${ORI_REP_TIPO}&grupo_id=${ORI_GRUPO_ID}`;
    }
    if (ORI_REP_TIPO === 'boleta') {
        const alumnoId = document.getElementById('rep-alumno-sel').value;
        url += `&alumno_id=${alumnoId}`;
    }
    window.open(url, '_blank');
}

/* ───────────── REPORTES DE CONDUCTA ───────────── */
let ORI_EDITANDO_REPORTE = null; // id del reporte de conducta en edición, o null

async function oriCargarConducta() {
    const r = await fetch(`api/orientador/reporte_conducta.php?grupo_id=${ORI_GRUPO_ID}`, { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('ori-tbody-conducta');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="6" class="pw-empty">${d.error}</td></tr>`; return; }
    tbody.innerHTML = d.reportes.length ? d.reportes.map(r => `<tr>
        <td>${r.fecha}</td><td>${r.alumno_nombre}</td><td>${r.autor_nombre} (${r.autor_rol})</td><td>${r.motivo}</td><td>${r.medida ?? '—'}</td>
        <td style="white-space:nowrap;">
            <button class="pw-btn pw-btn-gray" style="padding:4px 10px;font-size:.8rem;" onclick='oriEditarReporte(${JSON.stringify(r)})'>✏️</button>
            <button class="pw-btn pw-btn-red" style="padding:4px 10px;font-size:.8rem;" onclick="oriEliminarReporte(${r.id})">🗑️</button>
        </td>
    </tr>`).join('') : '<tr><td colspan="6" class="pw-empty">Sin reportes registrados.</td></tr>';
}

function oriEditarReporte(r) {
    ORI_EDITANDO_REPORTE = r.id;
    document.getElementById('oc-alumno').value = r.alumno_id || '';
    document.getElementById('oc-fecha').value = r.fecha;
    document.getElementById('oc-motivo').value = r.motivo;
    document.getElementById('oc-medida').value = r.medida || '';
    document.getElementById('oc-descripcion').value = r.descripcion;
    document.getElementById('oc-form-title').innerText = 'Editando reporte';
    document.getElementById('oc-submit-btn').innerText = '💾 Guardar cambios';
    document.getElementById('oc-cancelar-btn').style.display = 'inline-flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function oriCancelarEdicionReporte() {
    ORI_EDITANDO_REPORTE = null;
    document.getElementById('oc-motivo').value = '';
    document.getElementById('oc-medida').value = '';
    document.getElementById('oc-descripcion').value = '';
    document.getElementById('oc-form-title').innerText = 'Nuevo reporte';
    document.getElementById('oc-submit-btn').innerText = '📤 Generar Reporte';
    document.getElementById('oc-cancelar-btn').style.display = 'none';
}

async function oriEliminarReporte(id) {
    if (!confirm('¿Eliminar este reporte de conducta? Esta acción no se puede deshacer.')) return;
    const r = await fetch('api/orientador/reporte_conducta.php', {
        method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
    });
    const d = await r.json();
    if (!d.ok) { oriAlert(d.error, 'err'); return; }
    oriAlert('✅ ' + d.mensaje, 'ok');
    await oriCargarConducta();
}

async function oriCrearReporte() {
    const payload = {
        alumno_id: document.getElementById('oc-alumno').value,
        motivo: document.getElementById('oc-motivo').value.trim(),
        descripcion: document.getElementById('oc-descripcion').value.trim(),
        medida: document.getElementById('oc-medida').value.trim(),
        fecha: document.getElementById('oc-fecha').value,
    };
    if (!payload.alumno_id || !payload.motivo || !payload.descripcion) { oriAlert('Selecciona un alumno y llena motivo y descripción.', 'err'); return; }

    const editando = ORI_EDITANDO_REPORTE;
    const r = await fetch('api/orientador/reporte_conducta.php', {
        method: editando ? 'PUT' : 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(editando ? { ...payload, id: editando } : payload),
    });
    const d = await r.json();
    if (!d.ok) { oriAlert(d.error, 'err'); return; }
    oriAlert('✅ ' + d.mensaje, 'ok');
    oriCancelarEdicionReporte();
    await oriCargarConducta();
}

/* ───────────── NAV ───────────── */
function oriGoto(panel) {
    document.querySelectorAll('.pw-tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + panel)?.classList.add('active');
    document.querySelectorAll('.pw-sidebar .pw-side-link').forEach(s => s.classList.remove('active'));
    document.querySelector(`.pw-sidebar .pw-side-link[data-panel="${panel}"]`)?.classList.add('active');
    if (panel === 'reportes') oriCargarReporte();
}

function oriAlert(msg, tipo) {
    const el = document.getElementById('ori-alert');
    el.className = 'pw-alert ' + tipo;
    el.innerText = msg;
    setTimeout(() => { el.className = 'pw-alert'; }, 5000);
}

async function cerrarSesion() {
    await fetch('api/logout.php', { credentials: 'same-origin' });
    window.location.href = 'index.html';
}

oriInit();
