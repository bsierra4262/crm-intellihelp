/**
 * vivo.js — las campañas se actualizan solas, sin recargar la página.
 *
 * Una campaña manda un correo cada 25 a 45 segundos y una corrida del cron dura
 * varios minutos. Sin esto había que apretar F5 para saber si estaba avanzando,
 * y mirando una pantalla quieta parece que no está pasando nada.
 *
 * Cubre las tres pantallas donde aparecen números de campaña: el panel, el
 * listado y la ficha. Todas leen del mismo endpoint, que cuenta sobre la cola
 * de envíos en el momento: así ninguna puede discrepar de otra.
 *
 * NO se apaga cuando la campaña termina. Los envíos paran, pero las respuestas
 * y las reuniones agendadas siguen llegando después —a veces días después— y
 * ésos son justamente los números que importan. Lo que hace es bajar el ritmo:
 * cada 6 segundos mientras manda, cada 30 cuando ya terminó.
 */
(function () {
    'use strict';

    var doc = document;

    // ------------------------------------------------------------------
    //  La campana de alertas: va en TODAS las pantallas.
    //  Una respuesta de un cliente no puede quedar esperando a que a alguien
    //  se le ocurra entrar a mirar la campaña. Se consulta aparte del avance
    //  de las campañas porque el número tiene que moverse estés donde estés.
    // ------------------------------------------------------------------
    (function () {
        var campana = doc.querySelector('[data-alertas]');
        if (!campana) { return; }
        var numero = doc.querySelector('[data-alertas-n]');
        var base   = (window.__sesion && window.__sesion.base) || '';
        var malas  = 0;
        var reloj  = null;

        function mirar() {
            if (doc.hidden) { return; }
            fetch(base + 'api-alertas', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
                .then(function (d) {
                    malas = 0;
                    if (!d || !d.ok || !numero) { return; }
                    var n = d.sin_leer | 0;
                    numero.textContent = n > 99 ? '99+' : String(n);
                    numero.hidden = n === 0;
                    // El título de la pestaña también lo dice: sirve cuando el
                    // CRM está abierto en otra solapa, que es lo normal.
                    var base0 = doc.title.replace(/^\(\d+\+?\)\s*/, '');
                    doc.title = n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' + base0 : base0;
                })
                .catch(function () { if (++malas >= 3 && reloj) { clearInterval(reloj); reloj = null; } });
        }
        reloj = setInterval(mirar, 15000);
        doc.addEventListener('visibilitychange', function () { if (!doc.hidden) { mirar(); } });
        mirar();
    })();

    if (!doc.querySelector('[data-vivo]')) { return; }

    var base   = (window.__sesion && window.__sesion.base) || '';
    var idFicha = (doc.querySelector('[data-vivo-campana]') || {}).getAttribute
                ? doc.querySelector('[data-vivo-campana]').getAttribute('data-vivo-campana') : '';

    var RAPIDO = 6000;      // mientras está mandando
    var LENTO  = 30000;     // ya terminó, pero pueden entrar respuestas
    var ritmo  = RAPIDO;
    var fallos = 0;
    var timer  = null;

    function poner(el, valor) {
        if (el && el.textContent !== String(valor)) { el.textContent = String(valor); }
    }

    /** Actualiza una fila del listado o del panel. */
    function pintarFila(c) {
        var fila = doc.querySelector('[data-vivo-fila="' + c.id + '"]');
        if (!fila) { return; }

        var barra = fila.querySelector('[data-vivo-barra]');
        if (barra) {
            barra.style.width = c.pct + '%';
            barra.classList.toggle('ok', c.pct >= 100);
        }
        // El listado muestra «3 / 6»; el panel, la frase completa.
        poner(fila.querySelector('[data-vivo-avance]'), c.enviados + ' / ' + c.total);
        var res = fila.querySelector('[data-vivo-resumen]');
        if (res) {
            poner(res, c.enviados + ' de ' + c.total + ' enviados · '
                     + c.respondidos + ' respuestas · ' + c.rebotados + ' rebotes');
        }
        ['respondidos', 'rebotados', 'reuniones', 'enviados', 'aperturas'].forEach(function (k) {
            poner(fila.querySelector('[data-vivo-col="' + k + '"]'), c[k]);
        });
        // El estado llega ya dibujado desde el servidor, con su color: así el
        // navegador no tiene que saber qué color le toca a cada estado.
        var badge = fila.querySelector('[data-vivo-badge]');
        if (badge && c.badge && badge.innerHTML !== c.badge) { badge.innerHTML = c.badge; }
    }

    /** Actualiza la ficha de una campaña. */
    function pintarFicha(c) {
        ['enviados', 'total', 'rebotados', 'respondidos', 'aperturas', 'reuniones', 'errores']
            .forEach(function (k) {
                poner(doc.querySelector('[data-vivo-campo="' + k + '"]'), c[k]);
            });
        var barra = doc.querySelector('[data-vivo-barra]');
        if (barra) {
            barra.style.width = c.pct + '%';
            barra.classList.toggle('ok', c.pct >= 100);
        }
    }

    function pintarCola(filas) {
        filas.forEach(function (f) {
            var tr = doc.querySelector('[data-envio="' + f.id + '"]');
            if (!tr) { return; }
            var e = tr.querySelector('[data-vivo-badge]');
            if (e && e.innerHTML !== f.badge) { e.innerHTML = f.badge; }
            poner(tr.querySelector('[data-vivo-enviado]'), f.enviado);
            poner(tr.querySelector('[data-vivo-respondio]'), f.respondio);
        });
    }

    function marcarCron(activo) {
        var el = doc.querySelector('[data-vivo-cron]');
        if (el) { el.hidden = !activo; }
    }

    function reprogramar(nuevo) {
        if (nuevo === ritmo) { return; }
        ritmo = nuevo;
        if (timer) { clearInterval(timer); }
        timer = setInterval(consultar, ritmo);
    }

    function consultar() {
        if (doc.hidden) { return; }          // nadie está mirando la pantalla
        fetch(base + 'api-campana' + (idFicha ? '?id=' + encodeURIComponent(idFicha) : ''), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('http ' + r.status); }
            return r.json();
        }).then(function (d) {
            fallos = 0;
            if (!d || !d.ok) { return; }
            marcarCron(!!d.cron);

            if (d.campana) {
                pintarFicha(d.campana);
                pintarFila(d.campana);
                if (d.filas) { pintarCola(d.filas); }
                reprogramar(d.campana.sigue ? RAPIDO : LENTO);
            } else if (d.campanas) {
                d.campanas.forEach(pintarFila);
                reprogramar(d.campanas.some(function (c) { return c.sigue; }) ? RAPIDO : LENTO);
            }
        }).catch(function () {
            // Tres fallos seguidos y se corta: si el servidor no responde, no
            // tiene sentido seguir insistiendo.
            if (++fallos >= 3 && timer) { clearInterval(timer); timer = null; }
        });
    }

    timer = setInterval(consultar, ritmo);
    doc.addEventListener('visibilitychange', function () {
        if (!doc.hidden) { consultar(); }    // al volver, se pone al día enseguida
    });
    consultar();
})();
