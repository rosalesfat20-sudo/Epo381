"use strict";

let AL_USER = null;
let AL_PERFIL = null;

async function alInit() {
    try {
        const r = await fetch('api/session_check.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok || d.user.rol !== 'alumno') { window.location.href = 'index.html'; return; }
        AL_USER = d.user;
    } catch (e) { window.location.href = 'index.html'; return; }

    document.getElementById('al-nombre-top').innerText = AL_USER.nombre + ' ' + AL_USER.apellido_paterno;
    await alCargarPerfil();
    await alCargarCalificaciones();

    // Actualiza calificaciones, historial y reportes automáticamente (sin recargar
    // la página) cada 20s, solo si el modal de perfil no está abierto para no
    // interrumpir al alumno mientras captura sus datos.
    setInterval(async () => {
        if (document.getElementById('al-modal-perfil').classList.contains('open')) return;
        await alCargarCalificaciones();
        const panelActivo = document.querySelector('.pw-tab-content.active')?.id;
        if (panelActivo === 'panel-historial') await alCargarHistorial();
        if (panelActivo === 'panel-reportes') await alCargarReportes();
    }, 20000);
}

async function alCargarPerfil() {
    const r = await fetch('api/alumno/perfil.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { alAlert(d.error, 'err'); return; }
    AL_PERFIL = d.perfil;

    const nombreCompleto = `${AL_PERFIL.nombre} ${AL_PERFIL.apellido_paterno} ${AL_PERFIL.apellido_materno || ''}`.trim();
    document.getElementById('al-nombre-header').innerText = nombreCompleto;
    document.getElementById('al-matricula').innerText = AL_PERFIL.matricula;
    document.getElementById('al-grupo').innerText = AL_PERFIL.grupo_nombre || 'Sin asignar';

    if (!AL_PERFIL.perfil_completo) {
        document.getElementById('al-aviso-primer-ingreso').style.display = 'block';
        document.getElementById('al-resumen-card').style.display = 'none';
        alAbrirModalPerfil(true); // forzado, sin botón de cerrar
    } else {
        document.getElementById('al-aviso-primer-ingreso').style.display = 'none';
        document.getElementById('al-resumen-card').style.display = 'block';
    }
}

/* ───────────── MODAL DE PERFIL (primer ingreso o edición posterior) ───────────── */
const AL_MODAL_MAP = {
    'm-curp': 'curp', 'm-fecha-nac': 'fecha_nacimiento', 'm-sexo': 'sexo', 'm-telefono': 'telefono',
    'm-email': 'email_personal', 'm-tutor-nombre': 'tutor_nombre', 'm-tutor-tel': 'tutor_telefono',
    'm-calle': 'calle', 'm-numero': 'numero', 'm-colonia': 'colonia', 'm-municipio': 'municipio',
    'm-estado': 'estado', 'm-cp': 'cp',
    'm-escuela': 'escuela_procedencia', 'm-municipio-proc': 'municipio_procedencia', 'm-promedio': 'promedio_secundaria',
};

function alAbrirModalPerfil(forzado) {
    const nombreCompleto = `${AL_PERFIL.nombre} ${AL_PERFIL.apellido_paterno} ${AL_PERFIL.apellido_materno || ''}`.trim();
    document.getElementById('m-nombre').value = nombreCompleto;
    document.getElementById('m-matricula').value = AL_PERFIL.matricula;
    for (const [elId, campo] of Object.entries(AL_MODAL_MAP)) {
        const val = AL_PERFIL[campo];
        document.getElementById(elId).value = (val !== null && val !== undefined) ? val : (elId === 'm-estado' ? 'Estado de México' : '');
    }
    document.getElementById('al-modal-cerrar-btn').style.display = (forzado && !AL_PERFIL.perfil_completo) ? 'none' : 'inline-flex';
    document.getElementById('al-modal-perfil').classList.add('open');
}

function alCerrarModalPerfil() {
    if (!AL_PERFIL.perfil_completo) return; // no se puede cerrar sin completar la primera vez
    document.getElementById('al-modal-perfil').classList.remove('open');
}

async function alGuardarModalPerfil() {
    const payload = {};
    for (const [elId, campo] of Object.entries(AL_MODAL_MAP)) {
        payload[campo] = document.getElementById(elId).value.trim();
    }

    const alertEl = document.getElementById('al-modal-alert');

    // Validación rápida en el navegador (el servidor vuelve a validar de todas formas)
    const curp = payload.curp.toUpperCase();
    if (curp && !/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/.test(curp)) {
        alertEl.className = 'pw-alert err';
        alertEl.innerText = 'La CURP debe tener 18 caracteres con el formato oficial (ej. AAAA000101HAAAAA00).';
        return;
    }
    const tel = payload.telefono.replace(/\D/g, '');
    if (tel && tel.length !== 10) {
        alertEl.className = 'pw-alert err';
        alertEl.innerText = 'El teléfono debe tener exactamente 10 dígitos.';
        return;
    }
    const tutorTel = payload.tutor_telefono.replace(/\D/g, '');
    if (tutorTel && tutorTel.length !== 10) {
        alertEl.className = 'pw-alert err';
        alertEl.innerText = 'El teléfono del tutor debe tener exactamente 10 dígitos.';
        return;
    }
    const cp = payload.cp.replace(/\D/g, '');
    if (cp && cp.length !== 5) {
        alertEl.className = 'pw-alert err';
        alertEl.innerText = 'El código postal debe tener exactamente 5 dígitos.';
        return;
    }

    try {
        const r = await fetch('api/alumno/perfil.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (!d.ok) {
            alertEl.className = 'pw-alert err'; alertEl.innerText = d.error;
            return;
        }
        alertEl.className = 'pw-alert ok'; alertEl.innerText = '✅ ' + d.mensaje;
        await alCargarPerfil();
        if (d.perfil_completo) {
            setTimeout(() => { document.getElementById('al-modal-perfil').classList.remove('open'); alAlert('✅ Perfil actualizado.', 'ok'); }, 700);
        }
    } catch (e) {
        alertEl.className = 'pw-alert err'; alertEl.innerText = 'No se pudo guardar. Verifica tu conexión con el servidor.';
    }
}

/* ───────────── CALIFICACIONES (semestre actual) ───────────── */
async function alCargarCalificaciones() {
    const r = await fetch('api/alumno/calificaciones.php', { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('al-tbody-calif');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="6" class="pw-empty">${d.error}</td></tr>`; return; }

    const materias = d.materias;
    const keys = Object.keys(materias);
    if (!keys.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="pw-empty"><div class="pw-empty-ico">📊</div>Aún no hay calificaciones registradas.</td></tr>';
        document.getElementById('al-total-materias').innerText = 0;
        document.getElementById('al-aprobadas').innerText = 0;
        document.getElementById('al-reprobadas').innerText = 0;
        document.getElementById('al-promedio').innerText = '—';
        return;
    }

    let aprobadas = 0, reprobadas = 0, sumaProm = 0, nProm = 0;
    tbody.innerHTML = keys.map(mat => {
        const info = materias[mat];
        const p = info.parciales;
        const cls = info.promedio === null ? 'pw-cal-nd' : (info.promedio >= 6 ? 'pw-cal-aprov' : 'pw-cal-repro');
        if (info.estatus === 'Aprobado') aprobadas++;
        if (info.estatus === 'Reprobado') reprobadas++;
        if (info.promedio !== null) { sumaProm += info.promedio; nProm++; }
        return `<tr>
            <td>${mat}</td>
            <td>${p[1] ?? '—'}</td><td>${p[2] ?? '—'}</td><td>${p[3] ?? '—'}</td>
            <td><span class="pw-cal-num ${cls}">${info.promedio ?? '—'}</span></td>
            <td>${info.estatus}</td>
        </tr>`;
    }).join('');
    document.getElementById('al-total-materias').innerText = keys.length;
    document.getElementById('al-aprobadas').innerText = aprobadas;
    document.getElementById('al-reprobadas').innerText = reprobadas;
    document.getElementById('al-promedio').innerText = nProm ? (sumaProm / nProm).toFixed(1) : '—';
}

/* ───────────── HISTORIAL ACADÉMICO (3 años / 6 semestres) ───────────── */
async function alCargarHistorial() {
    const cont = document.getElementById('al-historial-cont');
    cont.innerHTML = '<div class="pw-empty">Cargando...</div>';
    const r = await fetch('api/alumno/historial.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { cont.innerHTML = `<div class="pw-empty">${d.error}</div>`; return; }

    if (!d.historial.length) {
        cont.innerHTML = '<div class="pw-empty"><div class="pw-empty-ico">🗂️</div>Aún no tienes semestres con calificaciones registradas.</div>';
        return;
    }

    cont.innerHTML = d.historial.map(sem => {
        const materias = Object.entries(sem.materias);
        const filas = materias.length ? materias.map(([mat, info]) => {
            const p = info.parciales;
            const cls = info.promedio === null ? 'pw-cal-nd' : (info.promedio >= 6 ? 'pw-cal-aprov' : 'pw-cal-repro');
            return `<tr><td>${mat}</td><td>${p[1] ?? '—'}</td><td>${p[2] ?? '—'}</td><td>${p[3] ?? '—'}</td><td><span class="pw-cal-num ${cls}">${info.promedio ?? '—'}</span></td><td>${info.faltas ?? 0}</td><td>${info.estatus}</td></tr>`;
        }).join('') : '<tr><td colspan="7" class="pw-empty">Sin calificaciones en este semestre.</td></tr>';

        return `
        <div class="pw-card">
            <h3>📘 Semestre ${sem.semestre_global} — Grado ${sem.grado}°, ${sem.semestre === 1 ? '1er' : '2do'} semestre · Grupo ${sem.grupo_nombre} · Ciclo ${sem.ciclo_escolar}</h3>
            <div style="overflow-x:auto;">
                <table class="pw-table">
                    <thead><tr><th>Materia</th><th>P1</th><th>P2</th><th>P3</th><th>Promedio</th><th>Faltas</th><th>Estatus</th></tr></thead>
                    <tbody>${filas}</tbody>
                </table>
            </div>
            <p style="margin-top:12px;font-weight:800;color:var(--p-navy);">Promedio del semestre: ${sem.promedio_general ?? '—'} &nbsp;·&nbsp; Faltas totales: ${sem.total_faltas ?? 0}</p>
        </div>`;
    }).join('');
}

/* ───────────── REPORTES DE CONDUCTA (los que el alumno tiene) ───────────── */
async function alCargarReportes() {
    const tbody = document.getElementById('al-tbody-reportes');
    tbody.innerHTML = '<tr><td colspan="5" class="pw-empty">Cargando...</td></tr>';
    const r = await fetch('api/alumno/reportes.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="5" class="pw-empty">${d.error}</td></tr>`; return; }
    if (!d.reportes.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="pw-empty"><div class="pw-empty-ico">✅</div>No tienes reportes de conducta registrados.</td></tr>';
        return;
    }
    tbody.innerHTML = d.reportes.map(rp => `<tr>
        <td>${rp.fecha}</td><td>${rp.motivo}</td><td>${rp.descripcion}</td><td>${rp.medida || '—'}</td><td>${rp.autor_nombre} (${rp.autor_rol})</td>
    </tr>`).join('');
}

function alGoto(panel) {
    const bloqueados = ['calificaciones', 'historial', 'reportes'];
    if (AL_PERFIL && !AL_PERFIL.perfil_completo && bloqueados.includes(panel)) {
        alAlert('⚠️ Debes completar tus datos personales antes de ver tus calificaciones.', 'err');
        alAbrirModalPerfil(true);
        return;
    }
    document.querySelectorAll('.pw-tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + panel)?.classList.add('active');
    document.querySelectorAll('.pw-side-link').forEach(s => s.classList.remove('active'));
    document.querySelector(`.pw-side-link[data-panel="${panel}"]`)?.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (panel === 'historial') alCargarHistorial();
    if (panel === 'reportes') alCargarReportes();
}

function alAlert(msg, tipo) {
    const el = document.getElementById('al-alert');
    el.className = 'pw-alert ' + tipo;
    el.innerText = msg;
    setTimeout(() => { el.className = 'pw-alert'; }, 5000);
}

async function cerrarSesion() {
    await fetch('api/logout.php', { credentials: 'same-origin' });
    window.location.href = 'index.html';
}

alInit();
