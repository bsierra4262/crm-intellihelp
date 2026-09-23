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

    // --------------------------------------------------------
    //  Los correos de una conversación se muestran adentro de un marco
    //  aislado, para que su HTML no toque el de la pantalla. El marco no sabe
    //  cuánto mide su contenido: si no se le dice, queda con una altura fija y
    //  el mensaje aparece cortado con una barra de scroll adentro, que es
    //  exactamente lo que uno no quiere al leer un correo.
    // --------------------------------------------------------
    function ajustarMarco(f) {
        try {
            var d = f.contentDocument;
            if (!d || !d.body) { return; }
            // +2 px: sin ese margen algunos navegadores dejan una barra de
            // scroll de un píxel que se ve como un borde raro.
            f.style.height = Math.max(40, d.documentElement.scrollHeight) + 2 + 'px';
        } catch (e) { /* si el navegador no deja medirlo, queda la altura por defecto */ }
    }

    function ajustarMarcos() {
        document.querySelectorAll('iframe[data-marco]').forEach(ajustarMarco);
    }

    document.addEventListener('load', function (e) {
        if (e.target.matches && e.target.matches('iframe[data-marco]')) { ajustarMarco(e.target); }
    }, true);

    // Al desplegar la cita, el marco de adentro recién ahí tiene tamaño.
    document.addEventListener('toggle', function (e) {
        if (e.target.matches && e.target.matches('details')) { setTimeout(ajustarMarcos, 30); }
    }, true);

    window.addEventListener('resize', function () { setTimeout(ajustarMarcos, 80); });
    setTimeout(ajustarMarcos, 60);
    setTimeout(ajustarMarcos, 400);   // las imágenes del correo llegan después

    // --------------------------------------------------------
    //  Lo que se recorta en la tabla, se puede leer igual
    //
    //  Las celdas cortan con puntos suspensivos para que la tabla entre en la
    //  pantalla sin barra horizontal. Pero un correo cortado a la mitad no
    //  sirve de nada si no hay forma de verlo entero, así que el valor completo
    //  queda en el `title` —y SÓLO en las celdas que de verdad se cortaron: un
    //  tooltip en cada celda es ruido que tapa la que importa.
    // --------------------------------------------------------
    function marcarRecortadas(raiz) {
        (raiz || document).querySelectorAll('table.grid td').forEach(function (td) {
            // El ancho de la tarjeta en el celular es otro: ahí no se corta nada.
            if (window.innerWidth <= 768) { td.removeAttribute('title'); return; }
            var el = td.querySelector('.cell-main strong, .cell-main a') || td;
            var corta = el.scrollWidth > el.clientWidth + 1;
            if (corta) {
                var txt = (td.textContent || '').trim().replace(/\s+/g, ' ');
                if (txt && td.getAttribute('title') !== txt) { td.setAttribute('title', txt); }
            } else if (td.hasAttribute('title')) {
                td.removeAttribute('title');
            }
        });
    }

    var relojRecorte = null;
    function revisarRecortes() {
        clearTimeout(relojRecorte);
        relojRecorte = setTimeout(function () { marcarRecortadas(); }, 120);
    }
    window.addEventListener('resize', revisarRecortes);
    // El refresco automático trae filas nuevas, que todavía no pasaron por acá.
    document.addEventListener('vivo:actualizado', revisarRecortes);
    revisarRecortes();

    // --------------------------------------------------------
    //  Trabajos que tardan: decir que está pasando algo
    //
    //  Importar unos miles de filas lleva su tiempo y la pantalla se queda
    //  quieta. Sin nada que lo indique, la reacción natural es apretar de nuevo
    //  —y ahí se importa dos veces— o cerrar la pestaña a la mitad. El botón se
    //  bloquea y se muestra un aviso con los colores de la casa.
    // --------------------------------------------------------
    function tapaCargando(titulo, detalle) {
        var t = document.createElement('div');
        t.className = 'cargando-tapa';
        t.innerHTML =
            '<div class="cargando-caja">' +
              '<div class="cargando-aro" aria-hidden="true"><i></i><i></i><i></i></div>' +
              '<strong></strong><span></span>' +
            '</div>';
        t.querySelector('strong').textContent = titulo;
        t.querySelector('span').textContent = detalle || '';
        t.setAttribute('role', 'status');
        t.setAttribute('aria-live', 'polite');
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('visible'); });
        return t;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.dataset.enviando === '1') { return; }
        var btn = form.querySelector('[data-cargando]');
        if (!btn) { return; }
        // Si el navegador frenó el envío por un campo inválido, no hay nada que
        // esperar: mostrar la tapa dejaría la pantalla bloqueada sin motivo.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }

        form.dataset.enviando = '1';
        btn.disabled = true;
        tapaCargando(btn.getAttribute('data-cargando') || 'Procesando…',
                     btn.getAttribute('data-cargando-detalle') || '');
    });

    window.crmConfirmar = confirmar;
})();
