/* ============================================================
   CRM INTELLIHELP — comportamiento común de la interfaz.
   Sin dependencias ni build: se carga tal cual en todas las pantallas.
   ============================================================ */
(function () {
    'use strict';

    // --------------------------------------------------------
    //  Confirmación con ventana propia (nunca el confirm() del navegador)
    //  Uso:  <button data-confirmar="¿Eliminar el contacto?" ...>
    // --------------------------------------------------------
    var overlay = null;

    function construirModal() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true">' +
              '<div class="modal-head">' +
                '<span class="modal-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>' +
                '<h2></h2>' +
              '</div>' +
              '<div class="modal-body"></div>' +
              '<div class="modal-foot">' +
                '<button type="button" class="btn btn-ghost" data-accion="no">Cancelar</button>' +
                '<button type="button" class="btn btn-primary" data-accion="si">Confirmar</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function confirmar(opciones) {
        return new Promise(function (resolve) {
            var ov = construirModal();
            ov.querySelector('h2').textContent = opciones.titulo || '¿Confirmás?';
            ov.querySelector('.modal-body').textContent = opciones.texto || '';
            var btnSi = ov.querySelector('[data-accion="si"]');
            btnSi.textContent = opciones.aceptar || 'Confirmar';
            btnSi.className = 'btn ' + (opciones.peligro ? 'btn-danger' : 'btn-primary');
            ov.querySelector('.modal-ic').className = 'modal-ic' + (opciones.peligro ? '' : ' info');
            ov.classList.add('open');
            btnSi.focus();

            function cerrar(valor) {
                ov.classList.remove('open');
                document.removeEventListener('keydown', porTecla);
                ov.onclick = null;
                resolve(valor);
            }
            function porTecla(e) {
                if (e.key === 'Escape') { cerrar(false); }
                if (e.key === 'Enter')  { cerrar(true); }
            }
            ov.onclick = function (e) {
                var a = e.target.closest('[data-accion]');
                if (a) { cerrar(a.getAttribute('data-accion') === 'si'); }
                else if (e.target === ov) { cerrar(false); }
            };
            document.addEventListener('keydown', porTecla);
        });
    }

    // Intercepta cualquier elemento con data-confirmar y frena la acción hasta
    // que el usuario acepte. Funciona con enlaces y con botones de formulario.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirmar]');
        if (!el || el.dataset.confirmado === '1') { return; }
        e.preventDefault();
        e.stopPropagation();
        confirmar({
            titulo:  el.getAttribute('data-confirmar-titulo') || '¿Confirmás la acción?',
            texto:   el.getAttribute('data-confirmar'),
            aceptar: el.getAttribute('data-confirmar-ok') || 'Confirmar',
            peligro: el.hasAttribute('data-peligro')
        }).then(function (ok) {
            if (!ok) { return; }
            el.dataset.confirmado = '1';
            if (el.tagName === 'A') { window.location.href = el.href; }
            else if (el.form)       { el.form.submit(); }
            else                    { el.click(); }
        });
    }, true);

    // --------------------------------------------------------
    //  Cierre de los menús al hacer clic afuera
    //  (drawer de la barra y desplegable «Más»)
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        if (document.body.classList.contains('nav-open') && !e.target.closest('.topnav, .nav-toggle')) {
            document.body.classList.remove('nav-open');
        }
        document.querySelectorAll('details.menu[open]').forEach(function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        document.body.classList.remove('nav-open');
        document.querySelectorAll('details.menu[open]').forEach(function (d) { d.removeAttribute('open'); });
    });

    // --------------------------------------------------------
    //  Aviso de cierre de sesión por inactividad.
    //  Es SOLO cosmético: quien corta la sesión de verdad es el servidor.
    // --------------------------------------------------------
    var cfg = window.__sesion;
    if (cfg && cfg.idle > 0) {
        var ultimo = Date.now();
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, function () { ultimo = Date.now(); }, { passive: true });
        });
        setInterval(function () {
            var inactivo = (Date.now() - ultimo) / 1000;
            var vencida  = cfg.abs > 0 && (Date.now() / 1000 - cfg.loginAt) > cfg.abs;
            if (inactivo > cfg.idle || vencida) {
                window.location.href = cfg.base + 'login?expiro=' + (vencida ? 'expiro' : 'inactividad');
            }
        }, 15000);
    }

    // --------------------------------------------------------
    //  Filtros: el botón «Limpiar» vacía sin recargar dos veces
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-limpiar]');
        if (!b) { return; }
        e.preventDefault();
        var form = b.closest('form');
        if (!form) { return; }
        form.querySelectorAll('input, select').forEach(function (c) {
            if (c.type === 'hidden') { return; }
            if (c.type === 'checkbox' || c.type === 'radio') { c.checked = false; }
            else { c.value = ''; }
        });
        form.submit();
    });

    // --------------------------------------------------------
    //  Campo de archivo: mostrar el nombre elegido
    // --------------------------------------------------------
    document.addEventListener('change', function (e) {
        var input = e.target.closest('.filefield input[type=file]');
        if (!input) { return; }
        var etiqueta = input.closest('.filefield').querySelector('.nombre');
        if (!etiqueta) { return; }
        etiqueta.textContent = input.files && input.files.length
            ? input.files[0].name
            : 'Ningún archivo seleccionado';
    });

    // --------------------------------------------------------
    //  Auto-envío de filtros al cambiar un select
    // --------------------------------------------------------
    document.addEventListener('change', function (e) {
        var s = e.target.closest('[data-auto]');
        if (s && s.form) { s.form.submit(); }
    });

    window.crmConfirmar = confirmar;
})();
