"use strict";
/* Manejo del menú lateral en móvil (cajón deslizante) para los 4 portales */
function pwToggleSidebar() {
    document.querySelector('.pw-sidebar')?.classList.toggle('pw-sidebar-open');
    document.querySelector('.pw-sidebar-overlay')?.classList.toggle('open');
}
function pwCloseSidebar() {
    document.querySelector('.pw-sidebar')?.classList.remove('pw-sidebar-open');
    document.querySelector('.pw-sidebar-overlay')?.classList.remove('open');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.pw-sidebar .pw-side-link').forEach(el => {
        el.addEventListener('click', pwCloseSidebar);
    });
});
