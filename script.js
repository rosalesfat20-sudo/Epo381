"use strict";
/* =============================================
   SITIO PÚBLICO EPO 381 — Navegación y Acceso
   (Sin IndexedDB / sin usuarios de prueba.
   El login real se valida contra PostgreSQL vía api/login.php)
   ============================================= */

let pendingRole = null;

function showPage(id) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    const target = document.getElementById('page-' + id);
    if (target) {
        target.classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    document.querySelector('.menu-list')?.classList.remove('open');
    document.querySelectorAll('.menu-item.open').forEach(mi => mi.classList.remove('open'));
}

// En celular, los submenús (Acerca de / Comunidad) no se pueden abrir con
// hover porque no existe el "pasar el mouse" en pantallas táctiles: aquí se
// habilita que un toque los despliegue como acordeón.
document.querySelectorAll('.menu-item').forEach(item => {
    const link = item.querySelector(':scope > .menu-link');
    const dropdown = item.querySelector(':scope > .dropdown');
    if (link && dropdown) {
        link.addEventListener('click', (e) => {
            if (window.innerWidth <= 900) {
                e.preventDefault();
                const yaAbierto = item.classList.contains('open');
                document.querySelectorAll('.menu-item.open').forEach(mi => mi.classList.remove('open'));
                if (!yaAbierto) item.classList.add('open');
            }
        });
    }
});

// ── Arranque: el login es lo primero que se ve ──
// Solo después de iniciar sesión (y aceptar el aviso) se muestra el sitio público.
const PORTALES = { alumno: 'portal-alumno.html', docente: 'portal-docente.html',
                   orientador: 'portal-orientador.html', admin: 'portal-admin.html' };
let usuarioActual = null;

function entrarAlSitio(user, conAviso) {
    usuarioActual = user;
    document.body.classList.remove('login-first');
    showPage('home');
    if (conAviso) mostrarAvisoLegal();
}

function mostrarAvisoLegal() {
    document.getElementById('aviso-legal')?.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function aceptarAvisoLegal() {
    document.getElementById('aviso-legal')?.classList.remove('open');
    document.body.style.overflow = '';
    sessionStorage.setItem('epo_aviso_visto', '1');
}

function mostrarToast(texto) {
    const el = document.getElementById('toast-msg');
    if (!el) return;
    el.innerText = texto;
    el.style.opacity = '1';
    setTimeout(() => { el.style.opacity = '0'; }, 3500);
}

function irAMiPortal() {
    if (usuarioActual && PORTALES[usuarioActual.rol]) window.location.href = PORTALES[usuarioActual.rol];
}

function irAPortal(rol) {
    if (usuarioActual && usuarioActual.rol === rol) window.location.href = PORTALES[rol];
    else mostrarToast('Tu cuenta no tiene acceso a ese portal. Usa el botón "Mi portal".');
}

async function cerrarSesionSitio() {
    try { await fetch('api/logout.php', { credentials: 'same-origin' }); } catch (e) {}
    sessionStorage.removeItem('epo_aviso_visto');
    window.location.href = 'index.html';
}

// Si ya hay sesión activa, se salta el login (el aviso solo sale una vez por inicio de sesión)
fetch('api/session_check.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
        if (d && d.ok && d.user) entrarAlSitio(d.user, !sessionStorage.getItem('epo_aviso_visto'));
    })
    .catch(() => {});

function openLogin(rol, tituloVisible) {
    pendingRole = rol || null;
    document.body.classList.remove('login-first');
    const loginTitle = document.getElementById('l-title');
    const loginHint  = document.getElementById('l-hint');
    if (loginTitle) loginTitle.innerText = tituloVisible || 'Iniciar Sesión';
    if (loginHint)  loginHint.innerText  = '';
    document.getElementById('login-usuario').value = '';
    document.getElementById('login-password').value = '';
    document.getElementById('login-error').style.display = 'none';
    showPage('login');
    document.getElementById('login-usuario').focus();
}

async function processLogin() {
    const usuario  = document.getElementById('login-usuario').value.trim();
    const password = document.getElementById('login-password').value.trim();
    const errorEl  = document.getElementById('login-error');
    const btn      = document.getElementById('login-btn');

    if (!usuario || !password) {
        errorEl.innerText = '❌ Escribe tu usuario y contraseña.';
        errorEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Verificando...';

    try {
        const res = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ usuario, password, rol: pendingRole || '' }),
        });
        const textoBruto = await res.text();
        let data;
        try {
            data = JSON.parse(textoBruto);
        } catch (parseErr) {
            // El servidor respondió, pero no con JSON limpio (probablemente un error
            // de PHP, un 404, o un error de Apache) — se muestra tal cual para poder
            // diagnosticarlo, en vez de un mensaje genérico que no dice nada.
            console.error('Respuesta cruda del servidor (no era JSON):', textoBruto);
            errorEl.innerText = `❌ El servidor respondió con un error (HTTP ${res.status}) en vez de datos válidos. `
                + 'Abre las Herramientas de Desarrollador (F12) → pestaña "Network"/"Red" → clic en "login.php" → "Response" '
                + 'para ver el detalle exacto, o revisa el log de errores de PHP/Apache en el servidor.';
            errorEl.style.display = 'block';
            return;
        }

        if (!data.ok) {
            errorEl.innerText = '❌ ' + (data.error || 'Usuario o contraseña incorrectos.');
            if (data.detalle) console.error('Detalle del error:', data.detalle);
            errorEl.style.display = 'block';
            return;
        }

        // Login correcto: se muestra el aviso legal y después el sitio público
        sessionStorage.removeItem('epo_aviso_visto');
        document.getElementById('login-password').value = '';
        entrarAlSitio(data.user, true);
    } catch (e) {
        console.error('No se pudo hacer la petición al servidor:', e);
        errorEl.innerText = '❌ No se pudo contactar al servidor (error de red). Verifica que Apache esté encendido y que la URL sea correcta.';
        errorEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerText = 'Acceder al Sistema';
    }
}

// Permite enviar el login con Enter
document.addEventListener('DOMContentLoaded', () => {
    ['login-usuario', 'login-password'].forEach(id => {
        document.getElementById(id)?.addEventListener('keydown', e => {
            if (e.key === 'Enter') processLogin();
        });
    });
    cargarAvisosPublicos();
    // Actualiza los avisos automáticamente sin recargar la página
    setInterval(cargarAvisosPublicos, 30000);
});

async function cargarAvisosPublicos() {
    const cont = document.getElementById('avisos-lista');
    if (!cont) return;
    try {
        const res = await fetch('api/public/avisos.php');
        const data = await res.json();
        if (!data.ok || !data.avisos.length) {
            cont.innerHTML = '<p style="color:#64748b;">No hay avisos publicados por el momento.</p>';
            return;
        }
        cont.innerHTML = data.avisos.map(a => `
            <div class="card aviso-card">
                ${a.imagen ? `<div class="aviso-img-wrap"><img src="${a.imagen}" class="aviso-img" alt="${a.titulo}"></div>` : ''}
                <div class="inst-info">
                    <h4>${a.titulo}</h4>
                    <p>${a.contenido}</p>
                </div>
            </div>
        `).join('');
    } catch (e) {
        cont.innerHTML = '<p style="color:#64748b;">No se pudieron cargar los avisos.</p>';
    }
}
