/**
 * idle.js — Aviso de cierre de sesión por inactividad.
 *
 * Quién corta de verdad es el servidor: bootstrap.php destruye la sesión
 * vencida en la request siguiente, colabore o no el navegador. Este archivo es
 * solamente la experiencia, para que la sesión no se caiga sin explicación
 * mientras alguien está mirando la pantalla:
 *
 *   · Escucha actividad real (mouse, teclado, scroll, dedo).
 *   · La última actividad se comparte entre las pestañas del CRM abiertas en
 *     el mismo navegador (localStorage + evento `storage`): trabajar en una
 *     pestaña mantiene vivas a las demás, y una pestaña olvidada en segundo
 *     plano no cierra la sesión de la que se está usando.
 *   · Ante actividad manda un latido al servidor, como mucho una vez por
 *     minuto, para que la sesión no venza mientras se trabaja en una pantalla
 *     que no recarga —el editor de plantillas, por ejemplo, donde se pueden
 *     pasar veinte minutos sin cambiar de página.
 *   · Un rato antes del vencimiento muestra un aviso con cuenta regresiva.
 *   · Antes de salir le pregunta al servidor (api-sesion con `__vivo=1`, que
 *     no cuenta como actividad) si de verdad venció: si otra pestaña o el
 *     servidor dicen que no, se reacomoda el reloj y no se sale.
 *   · Si venció, va a «logout?motivo=inactividad»; si lo vencido es el tope
 *     absoluto, el servidor ya cerró la sesión y se va al login con
 *     «expiro=expiro».
 *
 * El layout inyecta la configuración en window.__sesion.
 *
 * Quién lo carga: layout.php, con `defer`, sólo con sesión iniciada. No se
 * engancha a ningún atributo del HTML: escucha la actividad en `window` y crea
 * su propio aviso (marcado con data-idle) cuando hace falta.
 *
 * window.__sesion = { idle, abs, loginAt, base }:
 *   · idle    segundos de inactividad (config app.idle_ttl, 600 por defecto;
 *             0 desactiva el corte por inactividad, igual que en el servidor);
 *   · abs     tope absoluto de la sesión en segundos (app.abs_ttl, 28800;
 *             0 lo desactiva);
 *   · loginAt momento del login en segundos Unix ($_SESSION['login_en']);
 *   · base    URL raíz de la instalación, para armar «logout» y «api-sesion».
 */
(function () {
    'use strict';

    var cfg = window.__sesion || {};

    /**
     * Lee un número de la configuración. Un 0 explícito se respeta (desactiva
     * ese control); sólo si falta el dato se usa el valor por defecto.
     */
    function numero(v, def) {
        var n = parseInt(v, 10);
        return isNaN(n) ? def : n;
    }

    var IDLE    = numero(cfg.idle, 600);        // segundos de inactividad hasta cerrar (0 = sin corte)
    var ABS     = numero(cfg.abs, 28800);       // tope absoluto de la sesión (0 = sin tope)
    var base    = cfg.base || '';
    var loginEn = cfg.loginAt ? cfg.loginAt * 1000 : Date.now();

    if (IDLE <= 0 && ABS <= 0) { return; }

    // El aviso dura el 10% del tiempo de inactividad, entre 15 y 60 segundos:
    // con 10 minutos de corte son 60 segundos, tiempo suficiente para volver de
    // otra pestaña sin perder lo que se estaba cargando.
    var AVISO = IDLE > 0 ? Math.min(60, Math.max(15, Math.floor(IDLE * 0.1))) : 0;

    // Clave compartida entre pestañas. Lleva la base para que dos
    // instalaciones en el mismo dominio (crm/ y crm-pruebas/), que tienen
    // sesiones distintas, no se mezclen.
    var CLAVE = 'crm.actividad:' + base;

    // ultima: última actividad conocida, de esta pestaña o de otra (ms).
    // ultimaEscrita: cuándo se escribió por última vez en localStorage (ms).
    // ultimoLatido: cuándo se mandó el último latido (ms).
    // overlay / cuenta: el aviso abierto y el número de la cuenta regresiva.
    // saliendo: evita navegar dos veces; revisando: hay una consulta a
    // api-sesion en vuelo.
    var ultima = Date.now();
    var ultimaEscrita = 0;
    var ultimoLatido = 0;
    var overlay = null, cuenta = null, saliendo = false, revisando = false;

    /** Lee la última actividad que dejó cualquier pestaña (0 si no hay o no se puede). */
    function leerCompartida() {
        try {
            var v = parseInt(window.localStorage.getItem(CLAVE), 10);
            return isNaN(v) ? 0 : v;
        } catch (e) { return 0; }
    }

    /**
     * Deja la actividad de esta pestaña a la vista de las demás. Se escribe
     * como mucho cada 5 segundos (mover el mouse dispara decenas de eventos
     * por segundo), salvo con `ya`, que escribe en el acto.
     */
    function compartir(t, ya) {
        if (!ya && t - ultimaEscrita < 5000) { return; }
        ultimaEscrita = t;
        try { window.localStorage.setItem(CLAVE, String(t)); } catch (e) {}
    }

    /** La última actividad más nueva entre esta pestaña y las demás. */
    function ultimaReal() {
        var c = leerCompartida();
        if (c > ultima) { ultima = c; }
        return ultima;
    }

    // Otra pestaña registró actividad: el reloj de esta también se reinicia.
    window.addEventListener('storage', function (e) {
        if (e.key !== CLAVE || !e.newValue) { return; }
        var t = parseInt(e.newValue, 10);
        if (!isNaN(t) && t > ultima) {
            ultima = t;
            if (overlay) { cerrarAviso(); }
        }
    });

    /**
     * Navega fuera del sistema. Se ejecuta una sola vez aunque la llamen
     * varias fuentes (la revisión, el latido con 440, el botón del aviso).
     *
     * @param {string} motivo 'inactividad' va a «logout?motivo=inactividad»,
     *   que registra la salida y cierra la sesión; 'expiro' (el servidor ya la
     *   cerró por el tope absoluto) va directo al login con el aviso.
     */
    function salir(motivo) {
        if (saliendo) { return; }
        saliendo = true;
        window.location.href = motivo === 'expiro'
            ? base + 'login?expiro=expiro'
            : base + 'logout?motivo=inactividad';
    }

    /**
     * Le pregunta al servidor si la sesión venció antes de salir.
     *
     * api-sesion va con `__vivo=1`: bootstrap.php no la cuenta como actividad
     * (preguntar no renueva la sesión) y, si estaba vencida, la cierra y
     * contesta 440 con el motivo. Si está viva contesta cuánto le queda
     * (`idle_resto`, `abs_resto`): si queda más que el aviso, alguien la usó
     * (otra pestaña, otro navegador con la misma sesión) y se reacomoda el
     * reloj en vez de salir. Si no se puede preguntar (sin red), se sale igual:
     * no se deja una pantalla con datos abierta y sin nadie delante.
     *
     * @param {string} motivo 'inactividad' o 'expiro', según lo que venció acá.
     */
    function vencer(motivo) {
        if (saliendo || revisando) { return; }
        revisando = true;
        fetch(base + 'api-sesion?__vivo=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (r) {
            if (r.status === 440) {
                return r.json().catch(function () { return {}; }).then(function (d) {
                    salir(d && d.motivo === 'expiro' ? 'expiro' : 'inactividad');
                    return null;
                });
            }
            if (!r.ok) { throw new Error('http ' + r.status); }
            return r.json();
        }).then(function (d) {
            if (!d || saliendo) { return; }
            var ahora = Date.now();
            if (motivo === 'expiro') {
                // El tope absoluto lo cierra el servidor: mientras diga que
                // queda tiempo (relojes desfasados), se espera a que lo haga.
                var absResto = numero(d.abs_resto, 0);
                if (d.ok && absResto > 0) { loginEn = ahora - (ABS - absResto) * 1000; return; }
                salir('expiro');
                return;
            }
            var idleResto = numero(d.idle_resto, -1);
            if (d.ok && idleResto > AVISO) {
                ultima = ahora - (IDLE - idleResto) * 1000;
                if (overlay) { cerrarAviso(); }
                return;
            }
            salir('inactividad');
        }).catch(function () {
            salir(motivo);
        }).then(function () { revisando = false; });
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
        if (IDLE <= 0) { return; }
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
     * Registra actividad: reinicia el reloj, la comparte con las demás
     * pestañas, cierra el aviso si estaba abierto y manda un latido (si pasó
     * un minuto del anterior). Basta con mover el mouse para que el aviso
     * desaparezca, también en las otras pestañas.
     */
    function hayActividad() {
        if (saliendo) { return; }
        var ahora = Date.now();
        ultima = ahora;
        compartir(ahora, !!overlay);
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
     * abierto no hace nada (la revisión lo llama cada 2 segundos). El
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
                '<span class="modal-ic info" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" ' +
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

    /**
     * Revisión periódica (y al volver a la pestaña). El orden importa: primero
     * el tope absoluto (para que el login diga «tiempo máximo» y no
     * «inactividad»), después el corte y por último el aviso. Si otra pestaña
     * registró actividad mientras el aviso estaba abierto, se cierra.
     */
    function revisar() {
        if (saliendo) { return; }
        var ahora = Date.now();
        if (ABS > 0 && (ahora - loginEn) / 1000 >= ABS) { vencer('expiro'); return; }
        if (IDLE <= 0) { return; }
        var inactivo = (ahora - ultimaReal()) / 1000;
        if (inactivo >= IDLE) { vencer('inactividad'); return; }
        if (inactivo >= IDLE - AVISO) {
            abrirAviso();
            if (cuenta) { cuenta.textContent = String(Math.max(0, Math.ceil(IDLE - inactivo))); }
        } else if (overlay) {
            cerrarAviso();
        }
    }

    // Cada 2 segundos, que es también el paso de la cuenta regresiva. El
    // intervalo no se limpia nunca: vive lo que vive la página.
    setInterval(revisar, 2000);

    // Una pestaña en segundo plano puede tener los temporizadores frenados, así
    // que al volver se revisa de inmediato en vez de esperar el próximo ciclo.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { revisar(); }
    });

    // Al abrir una pantalla ya hubo actividad (la navegación misma).
    compartir(ultima, true);
})();
