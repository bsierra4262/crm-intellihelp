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
 *
 * Quién lo carga: layout.php, con `defer`, sólo con sesión iniciada. No se
 * engancha a ningún atributo del HTML: escucha la actividad en `window` y crea
 * su propio aviso (marcado con data-idle) cuando hace falta.
 *
 * window.__sesion = { idle, abs, loginAt, base }:
 *   · idle    segundos de inactividad (config app.idle_ttl, 600 por defecto);
 *   · abs     tope absoluto de la sesión en segundos (app.abs_ttl, 28800);
 *   · loginAt momento del login en segundos Unix ($_SESSION['login_en']);
 *   · base    URL raíz de la instalación, para armar «logout» y «api-sesion».
 *
 * Al vencer navega a «logout?expiro=inactividad|expiro»: logout.php registra
 * el motivo en la auditoría, destruye la sesión y manda al login con el aviso.
 *
 * Trampa: el reloj de inactividad es POR PESTAÑA. Una pestaña olvidada en
 * segundo plano cuenta su propia inactividad aunque el usuario esté
 * trabajando en otra, y al vencer va a «logout», que cierra la sesión para
 * todas las pestañas. Los latidos de la pestaña activa mantienen viva la
 * sesión en el servidor, pero no le avisan a las demás pestañas.
 *
 * app.js tiene un bloque viejo que hace una versión reducida de esto mismo.
 */
(function () {
    'use strict';

    // `| 0` convierte a entero; si el resultado es 0 (falta el dato o vale 0)
    // se usa el valor por defecto. Por eso un idle_ttl = 0 en la configuración,
    // que en el servidor desactiva el corte por inactividad, acá NO lo
    // desactiva: el navegador igual cierra a los 600 segundos.
    var cfg     = window.__sesion || {};
    var IDLE    = (cfg.idle | 0) || 600;        // segundos de inactividad hasta cerrar
    var ABS     = (cfg.abs  | 0) || 28800;      // tope absoluto de la sesión
    var base    = cfg.base || '';
    var loginEn = cfg.loginAt ? cfg.loginAt * 1000 : Date.now();

    // El aviso dura el 10% del tiempo de inactividad, entre 15 y 60 segundos:
    // con 10 minutos de corte son 60 segundos, tiempo suficiente para volver de
    // otra pestaña sin perder lo que se estaba cargando.
    var AVISO = Math.min(60, Math.max(15, Math.floor(IDLE * 0.1)));

    // ultima: última actividad vista en ESTA pestaña (ms).
    // ultimoLatido: cuándo se mandó el último latido (ms).
    // overlay / cuenta: el aviso abierto y el número de la cuenta regresiva.
    // saliendo: evita navegar dos veces a «logout».
    var ultima = Date.now();
    var ultimoLatido = 0;
    var overlay = null, cuenta = null, saliendo = false;

    /**
     * Navega al cierre de sesión. Se ejecuta una sola vez aunque la llamen
     * varias fuentes (el intervalo, el latido con 440, el botón del aviso).
     *
     * @param {string} [motivo] 'inactividad' o 'expiro'; viaja en la URL y
     *   logout.php lo filtra a letras minúsculas antes de usarlo.
     */
    function salir(motivo) {
        if (saliendo) { return; }
        saliendo = true;
        window.location.href = base + 'logout?expiro=' + (motivo || 'inactividad');
    }

    /**
     * Avisa al servidor que hay actividad (POST a api-sesion), como mucho una
     * vez por minuto. bootstrap.php actualiza `ultima_actividad` en esa misma
     * request; si la sesión ya estaba vencida responde 440 y se sale.
     *
     * No lleva token CSRF: api-sesion no cambia nada más que el reloj de la
     * propia sesión. `keepalive` deja terminar el pedido aunque la página se
     * esté cerrando. Cualquier error de red se ignora: el latido es un extra.
     */
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

    /**
     * Registra actividad: reinicia el reloj, cierra el aviso si estaba abierto
     * y manda un latido (si pasó un minuto del anterior). Basta con mover el
     * mouse para que el aviso desaparezca.
     */
    function hayActividad() {
        ultima = Date.now();
        if (overlay) { cerrarAviso(); }
        latido();
    }

    // `passive` porque sólo se mira, nunca se frena el evento: así no se
    // demora el scroll en el celular.
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
        window.addEventListener(ev, hayActividad, { passive: true });
    });

    /* El aviso usa el modal de la aplicación (.modal-head / .modal-body /
       .modal-foot), no uno propio: si se inventara su propio marcado, este
       cartel sería el único de la app que se ve distinto. */
    /**
     * Muestra el aviso «¿Seguís ahí?» con la cuenta regresiva. Si ya está
     * abierto no hace nada (el intervalo lo llama cada 2 segundos). El
     * innerHTML es fijo; sólo se intercala AVISO, que es un número calculado
     * acá. El manejador de clic se va junto con el elemento al cerrarlo.
     */
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

    /**
     * Cierra el aviso con su animación y lo quita del DOM a los 250 ms.
     * Las variables se limpian antes, así un aviso nuevo puede abrirse
     * mientras el viejo todavía se desvanece.
     */
    function cerrarAviso() {
        if (!overlay) { return; }
        var o = overlay;
        overlay = null; cuenta = null;
        o.classList.remove('open');
        setTimeout(function () { if (o.parentNode) { o.parentNode.removeChild(o); } }, 250);
    }

    // Revisión cada 2 segundos, que es también el paso de la cuenta regresiva.
    // El orden importa: primero el tope absoluto (para que el login diga
    // «tiempo máximo» y no «inactividad»), después el corte y por último el
    // aviso. El intervalo no se limpia nunca: vive lo que vive la página.
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
    // Acá el motivo es siempre 'inactividad', aunque lo vencido sea el tope
    // absoluto: el login muestra el aviso de inactividad en ese caso.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        var inactivo = (Date.now() - ultima) / 1000;
        if (inactivo >= IDLE || (Date.now() - loginEn) / 1000 >= ABS) { salir('inactividad'); }
    });
})();
