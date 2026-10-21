"use strict";

let ADM_USER = null;
let ADM_GRUPOS = [];
let ADM_MATERIAS = [];
let ADM_ALUMNOS = [];
let ADM_DOCENTES = [];
let ADM_ORIENTADORES = [];
let ADM_USER_TAB = 'alumno';
let ADM_EDITANDO_USUARIO = null; // id del docente/orientador en edición, o null
let ADM_EDITANDO_AVISO = null;   // id del aviso en edición, o null
let ADM_EDITANDO_MATERIA = null; // id de la materia en edición, o null

async function admInit() {
    try {
        const r = await fetch('api/session_check.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok || d.user.rol !== 'admin') { window.location.href = 'index.html'; return; }
        ADM_USER = d.user;
    } catch (e) { window.location.href = 'index.html'; return; }

    document.getElementById('adm-nombre-top').innerText = ADM_USER.nombre + ' ' + ADM_USER.apellido_paterno;
    await admCargarGrupos();
    await admCargarMaterias();
    await admCargarDocentesYOrientadores();
    await admCargarUsuarios('alumno');
    await admCargarAsigDocente();
    await admCargarAsigOrientador();
    await admCargarAvisos();

    // Actualiza las listas automáticamente (sin recargar la página), evitando
    // pisar formularios que el administrador esté editando en ese momento.
    setInterval(async () => {
        const panelActivo = document.querySelector('.pw-tab-content.active')?.id;
        if (panelActivo === 'panel-grupos') await admCargarGrupos();
        if (panelActivo === 'panel-materias' && !ADM_EDITANDO_MATERIA) await admCargarMaterias();
        if (panelActivo === 'panel-usuarios' && !ADM_EDITANDO_USUARIO) await admCargarUsuarios(ADM_USER_TAB);
        if (panelActivo === 'panel-asignaciones') { await admCargarAsigDocente(); await admCargarAsigOrientador(); }
        if (panelActivo === 'panel-avisos' && !ADM_EDITANDO_AVISO) await admCargarAvisos();
    }, 20000);
}

/* ───────────── GRUPOS ───────────── */
async function admCargarGrupos() {
    const r = await fetch('api/admin/grupos.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    ADM_GRUPOS = d.grupos;

    document.getElementById('adm-tbody-grupos').innerHTML = d.grupos.length ? d.grupos.map(g => `<tr>
        <td>${g.nombre}</td><td>${g.grado}°</td><td>${g.semestre}° (Sem. global ${(g.grado-1)*2+g.semestre})</td><td>${g.turno}</td><td>${g.ciclo_escolar}</td>
        <td style="white-space:nowrap;display:flex;gap:6px;flex-wrap:wrap;">
            <button class="pw-btn pw-btn-gray" style="padding:4px 10px;font-size:.75rem;" ${g.semestre === 2 ? 'disabled title="Ya está en 2º semestre"' : ''}
                onclick="admAvanzarSemestre(${g.id}, '${admEsc(g.nombre)}')">➡️ Avanzar semestre</button>
            <button class="pw-btn pw-btn-blue" style="padding:4px 10px;font-size:.75rem;"
                onclick="admNuevoCiclo(${g.id}, '${admEsc(g.nombre)}')">🔄 Nuevo ciclo</button>
        </td>
    </tr>`).join('') : '<tr><td colspan="6" class="pw-empty">Aún no hay grupos creados.</td></tr>';

    const opciones = d.grupos.map(g => `<option value="${g.id}">${g.nombre} (Sem. ${(g.grado-1)*2+g.semestre})</option>`).join('');
    document.getElementById('u-grupo').innerHTML = opciones;
    document.getElementById('as-grupo-docente').innerHTML = opciones;
    document.getElementById('as-grupo-orientador').innerHTML = opciones;
    document.getElementById('cg-grupo').innerHTML = opciones;
    document.getElementById('pm-origen').innerHTML = opciones;
    document.getElementById('pm-destino').innerHTML = opciones;
}

async function admAvanzarSemestre(id, nombre) {
    if (!confirm(`¿Avanzar el grupo "${nombre}" al 2º semestre? Los alumnos y sus calificaciones anteriores no se ven afectados.`)) return;
    const r = await fetch('api/admin/grupos.php', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, accion: 'avanzar_semestre' }) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarGrupos();
}

async function admNuevoCiclo(id, nombre) {
    const ciclo = prompt(`Nuevo ciclo escolar para "${nombre}" (ej. 2027-2028). Antes de continuar, asegúrate de haber movido a la generación saliente con "Promoción masiva":`);
    if (!ciclo) return;
    let payload = { id, accion: 'nuevo_ciclo', ciclo_escolar: ciclo.trim() };
    let r = await fetch('api/admin/grupos.php', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    let d = await r.json();
    if (!d.ok && d.error && d.error.includes('alumnos activos')) {
        if (!confirm(d.error + '\n\n¿Continuar de todas formas?')) return;
        payload.confirmar = true;
        r = await fetch('api/admin/grupos.php', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        d = await r.json();
    }
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarGrupos();
}

function admSincronizarGrado() {
    const nombre = document.getElementById('gr-nombre').value;
    if (!nombre) return;
    document.getElementById('gr-grado').value = nombre.split(' ')[0]; // "1 I" -> "1"
}

async function admCrearGrupo() {
    const payload = {
        nombre: document.getElementById('gr-nombre').value.trim(),
        grado: document.getElementById('gr-grado').value,
        semestre: document.getElementById('gr-semestre').value,
        turno: 'Matutino',
        ciclo_escolar: document.getElementById('gr-ciclo').value.trim(),
    };
    if (!payload.nombre) { admAlert('Selecciona el salón (grupo).', 'err'); return; }
    const r = await fetch('api/admin/grupos.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    document.getElementById('gr-nombre').value = '';
    await admCargarGrupos();
}

/* ───────────── MATERIAS ───────────── */
async function admCargarMaterias() {
    const r = await fetch('api/admin/materias.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    ADM_MATERIAS = d.materias;

    document.getElementById('adm-tbody-materias').innerHTML = d.materias.length ? d.materias.map(m => `<tr>
        <td>${m.clave || '—'}</td><td>${m.nombre}</td>
        <td>${m.semestre ? m.semestre + '° semestre' : '<span class="pw-pill red">Sin semestre</span>'}</td>
        <td><button class="pw-btn pw-btn-blue" style="padding:4px 10px;font-size:.8rem;" onclick='admEditarMateria(${JSON.stringify(m)})'>✏️ Editar</button></td>
    </tr>`).join('') : '<tr><td colspan="4" class="pw-empty">Aún no hay materias creadas.</td></tr>';

    admFiltrarMateriasPorGrupo();
}

/** Solo muestra en "Materia" las asignaturas del semestre que le corresponde
 *  al grupo seleccionado en "Grupo" — cada semestre tiene sus propias materias. */
function admFiltrarMateriasPorGrupo() {
    const grupoId = document.getElementById('as-grupo-docente')?.value;
    const grupo = ADM_GRUPOS.find(g => String(g.id) === String(grupoId));
    const semSel = document.getElementById('as-materia');
    if (!semSel) return;

    if (!grupo) { semSel.innerHTML = '<option value="">Selecciona un grupo primero...</option>'; return; }

    const semestreGlobal = (grupo.grado - 1) * 2 + grupo.semestre;
    const materiasDelSemestre = ADM_MATERIAS.filter(m => m.semestre == semestreGlobal);

    document.getElementById('as-materia-hint').innerText =
        `Materias del ${semestreGlobal}° semestre (grupo ${grupo.nombre}, grado ${grupo.grado}°, ${grupo.semestre}° semestre del grado).`;

    semSel.innerHTML = materiasDelSemestre.length
        ? materiasDelSemestre.map(m => `<option value="${m.id}">${m.nombre}</option>`).join('')
        : '<option value="">Sin materias registradas para este semestre — créalas en la pestaña Materias</option>';
}

async function admGuardarMateria() {
    const payload = {
        nombre: document.getElementById('mat-nombre').value.trim(),
        clave: document.getElementById('mat-clave').value.trim(),
        semestre: document.getElementById('mat-semestre').value,
    };
    if (!payload.nombre) { admAlert('El nombre de la materia es obligatorio.', 'err'); return; }
    if (!payload.semestre) { admAlert('Indica a qué semestre pertenece la materia.', 'err'); return; }

    const editando = ADM_EDITANDO_MATERIA;
    const r = await fetch('api/admin/materias.php', {
        method: editando ? 'PUT' : 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(editando ? { ...payload, id: editando } : payload),
    });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    admCancelarEdicionMateria();
    await admCargarMaterias();
}

function admEditarMateria(m) {
    ADM_EDITANDO_MATERIA = m.id;
    document.getElementById('mat-nombre').value = m.nombre;
    document.getElementById('mat-clave').value = m.clave || '';
    document.getElementById('mat-semestre').value = m.semestre || '';
    document.getElementById('mat-form-title').innerText = 'Editando materia';
    document.getElementById('mat-submit-btn').innerText = '💾 Guardar cambios';
    document.getElementById('mat-cancelar-btn').style.display = 'inline-flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function admCancelarEdicionMateria() {
    ADM_EDITANDO_MATERIA = null;
    document.getElementById('mat-nombre').value = '';
    document.getElementById('mat-clave').value = '';
    document.getElementById('mat-semestre').value = '';
    document.getElementById('mat-form-title').innerText = 'Nueva materia';
    document.getElementById('mat-submit-btn').innerText = '➕ Crear Materia';
    document.getElementById('mat-cancelar-btn').style.display = 'none';
}

/* ───────────── USUARIOS ───────────── */
function admUserTab(rol) {
    ADM_USER_TAB = rol;
    admCancelarEdicionUsuario();
    document.querySelectorAll('#panel-usuarios .pw-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.u === rol));
    document.getElementById('u-grupo-wrap').style.display = rol === 'alumno' ? 'flex' : 'none';
    const nombres = { alumno: 'Alumno', docente: 'Docente', orientador: 'Orientador' };
    document.getElementById('adm-user-form-title').innerText = 'Nuevo ' + nombres[rol];
    document.getElementById('adm-user-list-title').innerText = nombres[rol] + 's registrados';
    document.getElementById('u-nombre').value = '';
    document.getElementById('u-appat').value = '';
    document.getElementById('u-apmat').value = '';
    document.getElementById('u-usuario').value = '';
    document.getElementById('u-password').value = '';
    const cache = { alumno: ADM_ALUMNOS, docente: ADM_DOCENTES, orientador: ADM_ORIENTADORES }[rol];
    if (cache && cache.length) renderTablaUsuarios(rol, cache); // instantáneo mientras llega lo más reciente
    else { document.getElementById('adm-tbody-usuarios').innerHTML = '<tr><td colspan="5" class="pw-empty">Cargando...</td></tr>'; }
    admCargarUsuarios(rol);
}

async function admCargarUsuarios(rol) {
    const r = await fetch(`api/admin/usuarios.php?rol=${rol}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) {
        if (rol === ADM_USER_TAB) document.getElementById('adm-tbody-usuarios').innerHTML = `<tr><td colspan="5" class="pw-empty">${d.error}</td></tr>`;
        return;
    }

    // Guarda los datos y refresca los <select> dependientes SIEMPRE, sin importar
    // qué pestaña esté visible en este momento (para que estén listos al cambiar de tab).
    if (rol === 'alumno') {
        ADM_ALUMNOS = d.usuarios;
        const opciones = d.usuarios.map(u => `<option value="${u.id}">${u.nombre} ${u.apellido_paterno} (${u.matricula})</option>`).join('');
        document.getElementById('cg-alumno').innerHTML = opciones;
        document.getElementById('baja-alumno').innerHTML = opciones;
    } else if (rol === 'docente') {
        ADM_DOCENTES = d.usuarios;
        document.getElementById('as-docente').innerHTML = d.usuarios.map(u => `<option value="${u.id}">${u.nombre} ${u.apellido_paterno}</option>`).join('');
    } else {
        ADM_ORIENTADORES = d.usuarios;
        document.getElementById('as-orientador').innerHTML = d.usuarios.map(u => `<option value="${u.id}">${u.nombre} ${u.apellido_paterno}</option>`).join('');
    }

    // La tabla visible SOLO se repinta si el rol que acaba de llegar es el de la
    // pestaña que el administrador tiene abierta ahora mismo; si no, no se toca
    // (antes esto causaba que, al entrar a Usuarios, se viera primero la tabla de
    // Alumnos y "después de un rato" apareciera la del rol correcto).
    if (rol === ADM_USER_TAB) renderTablaUsuarios(rol, d.usuarios);
}

function renderTablaUsuarios(rol, usuarios) {
    const tbody = document.getElementById('adm-tbody-usuarios');
    const thead = document.getElementById('adm-user-thead');
    if (rol === 'alumno') {
        thead.innerHTML = '<tr><th>Matrícula</th><th>Nombre</th><th>Grupo</th><th>Estatus</th><th>Acciones</th></tr>';
        tbody.innerHTML = usuarios.length ? usuarios.map(u => `<tr>
            <td>${u.matricula}</td><td>${u.nombre} ${u.apellido_paterno} ${u.apellido_materno || ''}</td>
            <td>${u.grupo_nombre || '—'}</td>
            <td><span class="pw-pill ${u.estatus === 'activo' ? 'green' : 'red'}">${u.estatus}</span></td>
            <td><button class="pw-btn pw-btn-gray" onclick="admAbrirResetPassword(${u.id}, '${admEsc(u.usuario)}')">🔑 Contraseña</button></td>
        </tr>`).join('') : '<tr><td colspan="5" class="pw-empty">Sin alumnos registrados.</td></tr>';
    } else {
        thead.innerHTML = '<tr><th>Usuario</th><th>Nombre</th><th>Estatus</th><th>Acciones</th></tr>';
        tbody.innerHTML = usuarios.length ? usuarios.map(u => `<tr>
            <td>${u.usuario}</td><td>${u.nombre} ${u.apellido_paterno} ${u.apellido_materno || ''}</td>
            <td><span class="pw-pill ${u.activo ? 'green' : 'red'}">${u.activo ? 'Activo' : 'Inactivo'}</span></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="pw-btn pw-btn-blue" onclick='admEditarUsuario(${JSON.stringify(u)})'>✏️</button>
                <button class="pw-btn pw-btn-gray" onclick="admAbrirResetPassword(${u.id}, '${admEsc(u.usuario)}')">🔑</button>
                <button class="pw-btn pw-btn-red" onclick="admEliminarUsuario(${u.id}, '${admEsc(u.nombre + ' ' + u.apellido_paterno)}')">🗑️</button>
            </td>
        </tr>`).join('') : `<tr><td colspan="4" class="pw-empty">Sin ${rol}s registrados.</td></tr>`;
    }
}

function admEsc(s) { return String(s).replace(/'/g, "\\'"); }

async function admCargarDocentesYOrientadores() {
    await admCargarUsuarios('docente');
    await admCargarUsuarios('orientador');
    await admCargarUsuarios('alumno');
}

async function admCrearUsuario() {
    if (ADM_EDITANDO_USUARIO) { await admGuardarEdicionUsuario(); return; }

    const payload = {
        rol: ADM_USER_TAB,
        nombre: document.getElementById('u-nombre').value.trim(),
        apellido_paterno: document.getElementById('u-appat').value.trim(),
        apellido_materno: document.getElementById('u-apmat').value.trim(),
        usuario: document.getElementById('u-usuario').value.trim(),
        password: document.getElementById('u-password').value.trim(),
        grupo_id: document.getElementById('u-grupo').value,
    };
    if (!payload.nombre || !payload.apellido_paterno || !payload.usuario || !payload.password) {
        admAlert('Nombre, apellido paterno, usuario y contraseña son obligatorios.', 'err'); return;
    }
    const r = await fetch('api/admin/usuarios.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje + ' Usuario: ' + payload.usuario, 'ok');
    admUserTab(ADM_USER_TAB);
    await admCargarDocentesYOrientadores();
}

function admEditarUsuario(u) {
    ADM_EDITANDO_USUARIO = u.id;
    document.getElementById('u-nombre').value = u.nombre;
    document.getElementById('u-appat').value = u.apellido_paterno;
    document.getElementById('u-apmat').value = u.apellido_materno || '';
    document.getElementById('u-usuario').value = u.usuario;
    document.getElementById('u-usuario').disabled = true;
    document.getElementById('u-password').closest('.pw-fgrp').style.display = 'none';
    document.getElementById('adm-user-form-title').innerText = 'Editando: ' + u.nombre + ' ' + u.apellido_paterno;
    document.getElementById('u-submit-btn').innerText = '💾 Guardar cambios';
    document.getElementById('u-cancel-btn').style.display = 'inline-flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function admGuardarEdicionUsuario() {
    const payload = {
        id: ADM_EDITANDO_USUARIO,
        nombre: document.getElementById('u-nombre').value.trim(),
        apellido_paterno: document.getElementById('u-appat').value.trim(),
        apellido_materno: document.getElementById('u-apmat').value.trim(),
    };
    if (!payload.nombre || !payload.apellido_paterno) { admAlert('Nombre y apellido paterno son obligatorios.', 'err'); return; }
    const r = await fetch('api/admin/usuarios.php', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    admCancelarEdicionUsuario();
    await admCargarDocentesYOrientadores();
}

function admCancelarEdicionUsuario() {
    ADM_EDITANDO_USUARIO = null;
    document.getElementById('u-usuario').disabled = false;
    document.getElementById('u-password').closest('.pw-fgrp').style.display = 'flex';
    document.getElementById('u-submit-btn').innerText = '➕ Crear Usuario';
    document.getElementById('u-cancel-btn').style.display = 'none';
    const nombres = { alumno: 'Alumno', docente: 'Docente', orientador: 'Orientador' };
    document.getElementById('adm-user-form-title').innerText = 'Nuevo ' + nombres[ADM_USER_TAB];
    document.getElementById('u-nombre').value = '';
    document.getElementById('u-appat').value = '';
    document.getElementById('u-apmat').value = '';
    document.getElementById('u-usuario').value = '';
    document.getElementById('u-password').value = '';
}

async function admEliminarUsuario(id, nombre) {
    if (!confirm(`¿Eliminar a ${nombre}? Si tiene calificaciones o reportes ligados, su cuenta se desactivará en vez de borrarse por completo.`)) return;
    const r = await fetch('api/admin/usuarios.php', { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarDocentesYOrientadores();
}

/* ───────────── RESTABLECER CONTRASEÑA (solo admin) ───────────── */
async function admAbrirResetPassword(usuarioId, usuarioLogin) {
    const nueva = prompt(`Nueva contraseña para "${usuarioLogin}":`);
    if (!nueva) return;
    const r = await fetch('api/admin/reset_password.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ usuario_id: usuarioId, nueva_password: nueva }),
    });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
}

/* ───────────── ASIGNACIONES ───────────── */
async function admCargarAsigDocente() {
    const r = await fetch('api/admin/asignaciones.php?tipo=docente', { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('adm-tbody-asig-docente');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="4" class="pw-empty">${d.error}</td></tr>`; return; }
    tbody.innerHTML = d.asignaciones.length ? d.asignaciones.map(a => `<tr>
        <td>${a.docente_nombre}</td><td>${a.materia_nombre}</td><td>${a.grupo_nombre}</td>
        <td><button class="pw-btn pw-btn-gray" onclick="admEliminarAsignacion('docente', ${a.id})">🗑️</button></td>
    </tr>`).join('') : '<tr><td colspan="4" class="pw-empty">Sin asignaciones.</td></tr>';
}

async function admAsignarDocente() {
    const payload = { tipo: 'docente', docente_id: document.getElementById('as-docente').value, materia_id: document.getElementById('as-materia').value, grupo_id: document.getElementById('as-grupo-docente').value };
    if (!payload.docente_id || !payload.materia_id || !payload.grupo_id) { admAlert('Selecciona docente, materia y grupo.', 'err'); return; }
    const r = await fetch('api/admin/asignaciones.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarAsigDocente();
}

async function admCargarAsigOrientador() {
    const r = await fetch('api/admin/asignaciones.php?tipo=orientador', { credentials: 'same-origin' });
    const d = await r.json();
    const tbody = document.getElementById('adm-tbody-asig-orientador');
    if (!d.ok) { tbody.innerHTML = `<tr><td colspan="3" class="pw-empty">${d.error}</td></tr>`; return; }
    tbody.innerHTML = d.asignaciones.length ? d.asignaciones.map(a => `<tr>
        <td>${a.orientador_nombre}</td><td>${a.grupo_nombre}</td>
        <td><button class="pw-btn pw-btn-gray" onclick="admEliminarAsignacion('orientador', ${a.id})">🗑️</button></td>
    </tr>`).join('') : '<tr><td colspan="3" class="pw-empty">Sin asignaciones.</td></tr>';
}

async function admAsignarOrientador() {
    const payload = { tipo: 'orientador', orientador_id: document.getElementById('as-orientador').value, grupo_id: document.getElementById('as-grupo-orientador').value };
    if (!payload.orientador_id || !payload.grupo_id) { admAlert('Selecciona orientador y grupo.', 'err'); return; }
    const r = await fetch('api/admin/asignaciones.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarAsigOrientador();
}

async function admEliminarAsignacion(tipo, id) {
    const r = await fetch('api/admin/asignaciones.php', { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tipo, id }) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ Asignación eliminada.', 'ok');
    if (tipo === 'docente') await admCargarAsigDocente(); else await admCargarAsigOrientador();
}

/* ───────────── CAMBIO DE GRUPO / BAJA / PROMOCIÓN MASIVA ───────────── */
async function admCambiarGrupo() {
    const payload = { alumno_id: document.getElementById('cg-alumno').value, grupo_nuevo_id: document.getElementById('cg-grupo').value, motivo: document.getElementById('cg-motivo').value.trim() };
    if (!payload.alumno_id || !payload.grupo_nuevo_id) { admAlert('Selecciona alumno y grupo nuevo.', 'err'); return; }
    const r = await fetch('api/admin/cambio_grupo.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    document.getElementById('cg-motivo').value = '';
    await admCargarUsuarios('alumno');
}

async function admPromocionMasiva() {
    const payload = {
        grupo_origen_id: document.getElementById('pm-origen').value,
        grupo_destino_id: document.getElementById('pm-destino').value,
        motivo: document.getElementById('pm-motivo').value.trim(),
    };
    if (!payload.grupo_origen_id || !payload.grupo_destino_id) { admAlert('Selecciona grupo de origen y destino.', 'err'); return; }
    if (!confirm('¿Mover a TODOS los alumnos activos del grupo de origen al grupo destino?')) return;
    const r = await fetch('api/admin/promocion_masiva.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarUsuarios('alumno');
}

async function admBajaAlumno() {
    const payload = { alumno_id: document.getElementById('baja-alumno').value, accion: 'baja', motivo: document.getElementById('baja-motivo').value.trim() };
    if (!payload.alumno_id) { admAlert('Selecciona un alumno.', 'err'); return; }
    const r = await fetch('api/admin/usuarios.php', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarUsuarios('alumno');
}

async function admAltaAlumno() {
    const payload = { alumno_id: document.getElementById('baja-alumno').value, accion: 'activar' };
    if (!payload.alumno_id) { admAlert('Selecciona un alumno.', 'err'); return; }
    const r = await fetch('api/admin/usuarios.php', { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarUsuarios('alumno');
}

/* ───────────── AVISOS ───────────── */
async function admCargarAvisos() {
    const r = await fetch('api/admin/avisos.php', { credentials: 'same-origin' });
    const d = await r.json();
    const cont = document.getElementById('adm-avisos-lista');
    if (!d.ok) { cont.innerHTML = `<div class="pw-empty">${d.error}</div>`; return; }
    if (!d.avisos.length) { cont.innerHTML = '<div class="pw-empty">Aún no hay avisos publicados.</div>'; return; }

    cont.innerHTML = d.avisos.map(a => `
        <div class="pw-card" style="display:flex;gap:16px;align-items:flex-start;">
            ${a.imagen ? `<img src="${a.imagen}" style="width:110px;height:80px;object-fit:cover;border-radius:10px;flex-shrink:0;">` : ''}
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <h3 style="margin:0;">${a.titulo} ${a.activo ? '<span class="pw-pill green">Visible</span>' : '<span class="pw-pill gray">Oculto</span>'}</h3>
                    <div style="display:flex;gap:6px;">
                        <button class="pw-btn pw-btn-blue" onclick='admEditarAviso(${JSON.stringify(a)})'>✏️ Editar</button>
                        <button class="pw-btn pw-btn-red" onclick="admEliminarAviso(${a.id})">🗑️ Eliminar</button>
                    </div>
                </div>
                <p style="margin-top:8px;color:var(--p-gray);">${a.contenido}</p>
            </div>
        </div>
    `).join('');
}

function admEditarAviso(a) {
    ADM_EDITANDO_AVISO = a.id;
    document.getElementById('av-id').value = a.id;
    document.getElementById('av-titulo').value = a.titulo;
    document.getElementById('av-contenido').value = a.contenido;
    document.getElementById('av-orden').value = a.orden;
    document.getElementById('av-activo').value = a.activo ? '1' : '0';
    document.getElementById('av-form-title').innerText = 'Editando: ' + a.titulo;
    document.getElementById('av-submit-btn').innerText = '💾 Guardar cambios';
    document.getElementById('av-cancel-btn').style.display = 'inline-flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function admCancelarEdicionAviso() {
    ADM_EDITANDO_AVISO = null;
    document.getElementById('av-id').value = '';
    document.getElementById('av-titulo').value = '';
    document.getElementById('av-contenido').value = '';
    document.getElementById('av-orden').value = '0';
    document.getElementById('av-activo').value = '1';
    document.getElementById('av-imagen').value = '';
    document.getElementById('av-form-title').innerText = 'Nuevo aviso';
    document.getElementById('av-submit-btn').innerText = '📤 Publicar aviso';
    document.getElementById('av-cancel-btn').style.display = 'none';
}

async function admGuardarAviso() {
    const titulo = document.getElementById('av-titulo').value.trim();
    const contenido = document.getElementById('av-contenido').value.trim();
    if (!titulo || !contenido) { admAlert('Título y contenido son obligatorios.', 'err'); return; }

    const fd = new FormData();
    fd.append('titulo', titulo);
    fd.append('contenido', contenido);
    fd.append('orden', document.getElementById('av-orden').value || '0');
    const archivo = document.getElementById('av-imagen').files[0];
    if (archivo) fd.append('imagen', archivo);

    if (ADM_EDITANDO_AVISO) {
        fd.append('_accion', 'editar');
        fd.append('id', ADM_EDITANDO_AVISO);
        fd.append('activo', document.getElementById('av-activo').value);
    }

    const r = await fetch('api/admin/avisos.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    admCancelarEdicionAviso();
    await admCargarAvisos();
}

async function admEliminarAviso(id) {
    if (!confirm('¿Eliminar este aviso? Esta acción no se puede deshacer.')) return;
    const fd = new FormData();
    fd.append('_accion', 'eliminar');
    fd.append('id', id);
    const r = await fetch('api/admin/avisos.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const d = await r.json();
    if (!d.ok) { admAlert(d.error, 'err'); return; }
    admAlert('✅ ' + d.mensaje, 'ok');
    await admCargarAvisos();
}

/* ───────────── NAV ───────────── */
function admGoto(panel) {
    document.querySelectorAll('.pw-tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + panel)?.classList.add('active');
    document.querySelectorAll('.pw-sidebar .pw-side-link').forEach(s => s.classList.remove('active'));
    document.querySelector(`.pw-sidebar .pw-side-link[data-panel="${panel}"]`)?.classList.add('active');
}

function admAlert(msg, tipo) {
    const el = document.getElementById('adm-alert');
    el.className = 'pw-alert ' + tipo;
    el.innerText = msg;
    setTimeout(() => { el.className = 'pw-alert'; }, 5000);
}

async function cerrarSesion() {
    await fetch('api/logout.php', { credentials: 'same-origin' });
    window.location.href = 'index.html';
}

admInit();
