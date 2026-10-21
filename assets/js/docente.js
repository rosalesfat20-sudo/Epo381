"use strict";

let DOC_USER = null;
let DOC_ASIGNACIONES = [];

async function docInit() {
    try {
        const r = await fetch('api/session_check.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok || d.user.rol !== 'docente') { window.location.href = 'index.html'; return; }
        DOC_USER = d.user;
    } catch (e) { window.location.href = 'index.html'; return; }

    document.getElementById('doc-nombre-top').innerText = DOC_USER.nombre + ' ' + DOC_USER.apellido_paterno;
    document.getElementById('rc-fecha').value = new Date().toISOString().slice(0, 10);
    await docCargarAsignaciones();
    await docCargarMisReportes();

    // Actualiza calificaciones y reportes automáticamente (sin recargar la página)
    setInterval(async () => {
        const panelActivo = document.querySelector('.pw-tab-content.active')?.id;
        if (panelActivo === 'panel-calificaciones') await docCargarCalificaciones();
        if (panelActivo === 'panel-conducta') await docCargarMisReportes();
    }, 20000);
}

async function docCargarAsignaciones() {
    const r = await fetch('api/docente/grupos.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { docAlert(d.error, 'err'); return; }
    DOC_ASIGNACIONES = d.asignaciones;

    const selAsig = document.getElementById('doc-sel-asignacion');
    const selGrupo = document.getElementById('rc-grupo');

    if (!DOC_ASIGNACIONES.length) {
        selAsig.innerHTML = '<option>Sin materias asignadas</option>';
        selGrupo.innerHTML = '<option>Sin grupos asignados</option>';
        document.getElementById('doc-tbody').innerHTML = '<tr><td colspan="6" class="pw-empty">El administrador aún no te ha asignado materias.</td></tr>';
        return;
    }

    selAsig.innerHTML = DOC_ASIGNACIONES.map(a =>
        `<option value="${a.grupo_id}|${a.materia_id}">${a.grupo_nombre} · ${a.materia_nombre}</option>`
    ).join('');

    const gruposUnicos = [...new Map(DOC_ASIGNACIONES.map(a => [a.grupo_id, a.grupo_nombre])).entries()];
    selGrupo.innerHTML = gruposUnicos.map(([id, nombre]) => `<option value="${id}">${nombre}</option>`).join('');

    await docCargarCalificaciones();
    await docCargarAlumnosGrupo();
}

async function docCargarCalificaciones() {
    const [grupoId, materiaId] = document.getElementById('doc-sel-asignacion').value.split('|');
    if (!grupoId || !materiaId) return;

    const r = await fetch(`api/docente/calificaciones.php?grupo_id=${grupoId}&materia_id=${materiaId}`, { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('doc-tbody');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="6" class="pw-empty">${d.error}</td></tr>`; return; }

    const alumnos = d.alumnos;
    document.getElementById('doc-count-badge').innerText = `${alumnos.length} alumnos`;
    document.getElementById('doc-total').innerText = alumnos.length;

    let aprov = 0, repro = 0, suma = 0, n = 0;
    if (!alumnos.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="pw-empty">No hay alumnos en este grupo.</td></tr>';
    } else {
        tbody.innerHTML = alumnos.map(a => {
            const p = a.parciales;
            if (a.promedio !== null) { suma += a.promedio; n++; a.promedio >= 6 ? aprov++ : repro++; }
            const cls = a.promedio === null ? 'pw-cal-nd' : (a.promedio >= 6 ? 'pw-cal-aprov' : 'pw-cal-repro');
            return `<tr><td>${a.matricula}</td><td>${a.nombre}</td><td>${p[1] ?? '—'}</td><td>${p[2] ?? '—'}</td><td>${p[3] ?? '—'}</td><td><span class="pw-cal-num ${cls}">${a.promedio ?? '—'}</span></td></tr>`;
        }).join('');
    }
    document.getElementById('doc-aprov').innerText = aprov;
    document.getElementById('doc-repro').innerText = repro;
    document.getElementById('doc-prom').innerText = n ? (suma / n).toFixed(1) : '—';
}

async function docCargarAlumnosGrupo() {
    const grupoId = document.getElementById('rc-grupo').value;
    const selAlumno = document.getElementById('rc-alumno');
    if (!grupoId) { selAlumno.innerHTML = ''; return; }
    const r = await fetch(`api/docente/alumnos.php?grupo_id=${grupoId}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { docAlert(d.error, 'err'); return; }
    selAlumno.innerHTML = d.alumnos.map(a => `<option value="${a.alumno_id}">${a.nombre} (${a.matricula})</option>`).join('') || '<option>Sin alumnos</option>';
}

async function docCrearReporte() {
    const payload = {
        alumno_id: document.getElementById('rc-alumno').value,
        motivo: document.getElementById('rc-motivo').value.trim(),
        descripcion: document.getElementById('rc-descripcion').value.trim(),
        medida: document.getElementById('rc-medida').value.trim(),
        fecha: document.getElementById('rc-fecha').value,
    };
    if (!payload.alumno_id || !payload.motivo || !payload.descripcion) {
        docAlert('Selecciona un alumno y llena motivo y descripción.', 'err');
        return;
    }
    const r = await fetch('api/docente/reporte_conducta.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (!d.ok) { docAlert(d.error, 'err'); return; }
    docAlert('✅ ' + d.mensaje, 'ok');
    document.getElementById('rc-motivo').value = '';
    document.getElementById('rc-medida').value = '';
    document.getElementById('rc-descripcion').value = '';
    await docCargarMisReportes();
}

async function docCargarMisReportes() {
    const r = await fetch('api/docente/reporte_conducta.php', { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('doc-tbody-reportes');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="5" class="pw-empty">${d.error}</td></tr>`; return; }
    if (!d.reportes.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="pw-empty">Aún no has generado reportes.</td></tr>';
        return;
    }
    tbody.innerHTML = d.reportes.map(r => `<tr>
        <td>${r.fecha}</td><td>${r.alumno_nombre}</td><td>${r.grupo_nombre ?? '—'}</td><td>${r.motivo}</td><td>${r.medida ?? '—'}</td>
    </tr>`).join('');
}

function docGoto(panel) {
    document.querySelectorAll('.pw-tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + panel)?.classList.add('active');
    document.querySelectorAll('.pw-side-link').forEach(s => s.classList.remove('active'));
    document.querySelector(`.pw-side-link[data-panel="${panel}"]`)?.classList.add('active');
}

function docAlert(msg, tipo) {
    const el = document.getElementById('doc-alert');
    el.className = 'pw-alert ' + tipo;
    el.innerText = msg;
    setTimeout(() => { el.className = 'pw-alert'; }, 5000);
}

async function cerrarSesion() {
    await fetch('api/logout.php', { credentials: 'same-origin' });
    window.location.href = 'index.html';
}

docInit();
