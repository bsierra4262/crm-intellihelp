/**
 * secciones.js — cualquier pantalla del sistema se actualiza sola.
 *
 * El problema que resuelve: el CRM lo mueven varias personas a la vez, y
 * además lo mueve el cron sin que nadie toque nada. Alguien edita un contacto,
 * llega una respuesta, entra una reunión, rebota un correo. Si la pantalla es
 * una foto del momento en que la abriste, todo eso pasa sin que te enteres, y
 * la única forma de saberlo es acordarte de apretar F5. Nadie se acuerda.
 *
 * Cómo funciona: cada zona que tiene que estar viva se marca en el HTML con
 * `data-vivo="algo"`. Este archivo vuelve a pedir la MISMA dirección que estás
 * viendo con `__vivo=1`, agarra del resultado las zonas con el mismo nombre y
 * reemplaza sólo las que cambiaron. No hay un endpoint por pantalla: la
 * pantalla ya sabe dibujarse, se le pide que lo haga de nuevo.
 *
 * Casi siempre no cambió nada, y esas respuestas vuelven vacías (304): no
 * viajan datos y no se toca el HTML. Recién cuando algo cambió de verdad se
 * reemplaza la zona y se marca en amarillo un segundo, para que se vea qué se
 * movió en vez de que los números salten solos.
 *
 * Tres cosas que NO hace, porque romperían el uso normal:
 *   · no toca una zona donde estás escribiendo o que tiene el foco adentro;
 *   · no toca nada mientras hay un diálogo abierto;
 *   · no cuenta como actividad para el cierre de sesión (eso lo impone el
 *     servidor): una pantalla olvidada tiene que cerrarse igual.
 */
(function () {
    'use strict';

    var doc = document;
    var zonas = doc.querySelectorAll('[data-vivo]');
    if (!zonas.length) { return; }

    // Rápido cuando algo se está moviendo, lento cuando está todo quieto. Una
    // pantalla dormida no tiene por qué castigar al servidor cada 5 segundos.
    var RAPIDO = 5000,
        LENTO  = 20000,
        ritmo  = RAPIDO,
        quietas = 0,      // consultas seguidas sin un solo cambio
        fallos  = 0,
        timer   = null,
        huella  = null,   // huella de lo último que se recibió
        pidiendo = false;

    var url = new URL(window.location.href);
    url.searchParams.set('__vivo', '1');

    /** ¿Se puede reemplazar esta zona sin arruinarle nada a quien la mira? */
    function seguro(zona) {
        var act = doc.activeElement;
        // Estás escribiendo acá adentro: reemplazarla borraría lo que escribiste.
        if (act && act !== doc.body && zona.contains(act)) { return false; }
        // Hay algo desplegado o un diálogo encima: se espera a la próxima vuelta.
        if (zona.querySelector('details[open], dialog[open], .modal:not([hidden])')) { return false; }
        // Una selección de texto adentro se perdería a mitad de copiar.
        var sel = window.getSelection();
        if (sel && !sel.isCollapsed && sel.anchorNode && zona.contains(sel.anchorNode)) { return false; }
        return true;
    }

    /**
     * Deja visible lo que cambió.
     *
     * Sin esto los números cambian solos y el ojo no lo registra: mirás la
     * pantalla, había un 3, ahora hay un 4, y no sabés si siempre fue 4.
     */
    function resaltar(zona) {
        zona.classList.remove('vivo-cambio');
        void zona.offsetWidth;               // reinicia la animación
        zona.classList.add('vivo-cambio');
        setTimeout(function () { zona.classList.remove('vivo-cambio'); }, 1600);
    }

    function aplicar(html) {
        var doc2 = new DOMParser().parseFromString(html, 'text/html');
        var cambio = false;

        doc.querySelectorAll('[data-vivo]').forEach(function (zona) {
            var nombre = zona.getAttribute('data-vivo');
            var nueva  = doc2.querySelector('[data-vivo="' + nombre + '"]');
            if (!nueva || nueva.innerHTML === zona.innerHTML) { return; }
            if (!seguro(zona)) { return; }

            // El scroll horizontal de las tablas anchas se conserva: si no, cada
            // refresco te devuelve al principio de la tabla.
            var scrolls = [];
            zona.querySelectorAll('.table-scroll').forEach(function (s, i) { scrolls[i] = s.scrollLeft; });

            zona.innerHTML = nueva.innerHTML;
            zona.querySelectorAll('.table-scroll').forEach(function (s, i) {
                if (scrolls[i]) { s.scrollLeft = scrolls[i]; }
            });

            resaltar(zona);
            cambio = true;
        });

        if (cambio) {
            // Algo se movió: puede haber más en camino, se vuelve al ritmo rápido.
            quietas = 0;
            reprogramar(RAPIDO);
            // Los diálogos de confirmación y demás se enganchan solos sobre el
            // HTML nuevo; se avisa por si algo necesita rearmarse.
            doc.dispatchEvent(new CustomEvent('vivo:actualizado'));
        }
    }

    function reprogramar(nuevo) {
        if (nuevo === ritmo && timer) { return; }
        ritmo = nuevo;
        if (timer) { clearInterval(timer); }
        timer = setInterval(consultar, ritmo);
    }

    function consultar() {
        if (doc.hidden || pidiendo) { return; }   // nadie mirando, o ya hay una en vuelo
        pidiendo = true;

        // La huella de lo último recibido va en la dirección, no en una
        // cabecera: la CDN del hosting descarta las cabeceras condicionales
        // (está comprobado contra el servidor), y por ahí no hay forma de
        // avisarle al servidor que ya tenemos esta versión.
        var u = new URL(url.toString());
        if (huella) { u.searchParams.set('__h', huella); }

        fetch(u.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) {
                fallos = 0;
                // La sesión se venció: se recarga y el servidor manda al login.
                if (r.status === 440 || r.status === 401 || r.status === 403) {
                    window.location.reload();
                    return null;
                }
                if (r.status === 204) {           // no cambió nada
                    if (++quietas >= 6) { reprogramar(LENTO); }
                    return null;
                }
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                if (!html) { return; }
                var m = html.match(/^<!--vivo:([0-9a-f]{32})-->/);
                if (m) { huella = m[1]; }
                aplicar(html);
            })
            .catch(function () {
                // Si el servidor no contesta tres veces seguidas, se corta. Sigue
                // andando todo lo demás: es un refresco, no el funcionamiento.
                if (++fallos >= 3 && timer) { clearInterval(timer); timer = null; }
            })
            .then(function () { pidiendo = false; });
    }

    timer = setInterval(consultar, ritmo);

    // Al volver a la pestaña se pone al día enseguida: es justo cuando más
    // desactualizado está lo que estás viendo.
    doc.addEventListener('visibilitychange', function () {
        if (!doc.hidden) { quietas = 0; reprogramar(RAPIDO); consultar(); }
    });

    // Después de guardar algo desde la propia pantalla conviene mirar ya.
    doc.addEventListener('submit', function () { setTimeout(consultar, 900); }, true);
})();
