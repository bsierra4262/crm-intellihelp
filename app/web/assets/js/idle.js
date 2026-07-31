/**
 * idle.js — Aviso de cierre de sesión por inactividad.
 *
 * Quién corta de verdad es el servidor: bootstrap.php destruye la sesión
 * vencida en la request siguiente, colabore o no el navegador. Este archivo es
 * solamente la experiencia, para que la sesión no se caiga sin explicación
 * mientras alguien está mirando la pantalla:
 *
 *   · Escucha actividad real (mouse, teclado, scroll, dedo).
 *   · Ante actividad manda un latido al servidor, como mucho una vez por
 *     minuto, para que la sesión no venza mientras se trabaja en una pantalla
 *     que no recarga —el editor de plantillas, por ejemplo, donde se pueden
 *     pasar veinte minutos sin cambiar de página.
 *   · Un rato antes del vencimiento muestra un aviso con cuenta regresiva.
 *   · Si nadie contesta, va a «salir» y el login explica por qué.
 *   · Corta también al llegar al máximo absoluto de sesión.
 *
 * El layout inyecta la configuración en window.__sesion.
 */
(function () {
    'use strict';

    var cfg     = window.__sesion || {};
    var IDLE    = (cfg.idle | 0) || 600;        // segundos de inactividad hasta cerrar
    var ABS     = (cfg.abs  | 0) || 28800;      // tope absoluto de la sesión
    var base    = cfg.base || '';
    var loginEn = cfg.loginAt ? cfg.loginAt * 1000 : Date.now();

    // El aviso dura el 10% del tiempo de inactividad, entre 15 y 60 segundos:
    // con 10 minutos de corte son 60 segundos, tiempo suficiente para volver de
    // otra pestaña sin perder lo que se estaba cargando.
    var AVISO = Math.min(60, Math.max(15, Math.floor(IDLE * 0.1)));

    var ultima = Date.now();
    var ultimoLatido = 0;
    var overlay = null, cuenta = null, saliendo = false;

    function salir(motivo) {
        if (saliendo) { return; }
        saliendo = true;
        window.location.href = base + 'logout?expiro=' + (motivo || 'inactividad');
    }

    function latido() {
        var ahora = Date.now();
        if (ahora - ultimoLatido < 60000) { return; }
        ultimoLatido = ahora;
        try {
            fetch(base + 'api-sesion', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                keepalive: true
            }).then(function (r) {
                // 440: el servidor ya la había dado por vencida.
                if (r && r.status === 440) { salir('inactividad'); }
            }).catch(function () {});
        } catch (e) {}
    }

    function hayActividad() {
        ultima = Date.now();
        if (overlay) { cerrarAviso(); }
        latido();
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
        window.addEventListener(ev, hayActividad, { passive: true });
    });

    /* El aviso usa el modal de la aplicación (.modal-head / .modal-body /
       .modal-foot), no uno propio: si se inventara su propio marcado, este
       cartel sería el único de la app que se ve distinto. */
    function abrirAviso() {
        if (overlay) { return; }
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.setAttribute('data-idle', '1');
        overlay.innerHTML =
            '<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="idleTit">' +
              '<div class="modal-head">' +
                '<span class="modal-ic info"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" ' +
                  'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
                  '<circle cx="12" cy="12" r="10"/><polyline points="12 7 12 12 15 14"/></svg></span>' +
                '<h2 id="idleTit">¿Seguís ahí?</h2>' +
              '</div>' +
              '<div class="modal-body">Vamos a cerrar la sesión por seguridad en ' +
                '<strong data-cuenta aria-live="polite">' + AVISO + '</strong> segundos. ' +
                'Cualquier movimiento del mouse o del teclado la mantiene abierta.</div>' +
              '<div class="modal-foot">' +
                '<button type="button" class="btn btn-ghost" data-idle="salir">Salir ahora</button>' +
                '<button type="button" class="btn btn-primary" data-idle="seguir">Seguir conectado</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        cuenta = overlay.querySelector('[data-cuenta]');
        overlay.addEventListener('click', function (e) {
            var b = e.target.closest('[data-idle]');
            if (!b) { return; }
            if (b.getAttribute('data-idle') === 'salir') { salir('inactividad'); }
            else { hayActividad(); }
        });
        void overlay.offsetWidth;          // fuerza el reflujo para que anime
        overlay.classList.add('open');
        // El foco va al botón que conserva la sesión: si alguien vuelve al
        // teclado, la tecla Enter no lo saca del sistema.
        var seguir = overlay.querySelector('[data-idle="seguir"]');
        if (seguir) { seguir.focus(); }
    }

    function cerrarAviso() {
        if (!overlay) { return; }
        var o = overlay;
        overlay = null; cuenta = null;
        o.classList.remove('open');
        setTimeout(function () { if (o.parentNode) { o.parentNode.removeChild(o); } }, 250);
    }

    setInterval(function () {
        var inactivo = (Date.now() - ultima) / 1000;
        var total    = (Date.now() - loginEn) / 1000;
        if (total >= ABS)   { salir('expiro'); return; }
        if (inactivo >= IDLE) { salir('inactividad'); return; }
        if (inactivo >= IDLE - AVISO) {
            abrirAviso();
            if (cuenta) { cuenta.textContent = String(Math.max(0, Math.ceil(IDLE - inactivo))); }
        }
    }, 2000);

    // Una pestaña en segundo plano puede tener los temporizadores frenados, así
    // que al volver se revisa de inmediato en vez de esperar el próximo ciclo.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        var inactivo = (Date.now() - ultima) / 1000;
        if (inactivo >= IDLE || (Date.now() - loginEn) / 1000 >= ABS) { salir('inactividad'); }
    });
})();
