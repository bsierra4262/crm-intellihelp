/**
 * vivo.js — el avance de las campañas se actualiza solo, sin recargar.
 *
 * Una campaña manda un correo cada 25 a 45 segundos y una corrida del cron dura
 * varios minutos. Sin esto había que apretar F5 para saber si estaba avanzando,
 * y mirando una pantalla quieta parece que no está pasando nada.
 *
 * Sólo se activa donde hay algo que mirar (el panel y la ficha de una campaña),
 * pregunta cada 6 segundos, y se apaga solo en tres casos: cuando la campaña
 * termina, cuando la pestaña queda en segundo plano y cuando el servidor deja
 * de responder. No tiene sentido consultar para siempre una pantalla que nadie
 * está mirando.
 */
(function () {
    'use strict';

    var caja = document.querySelector('[data-vivo]');
    if (!caja) { return; }
    // Se busca en toda la página: las cifras y la tabla de destinatarios están
    // en tarjetas distintas, y buscando sólo dentro de la caja la tabla no se
    // actualizaba nunca.
    var doc = document;

    var base    = (window.__sesion && window.__sesion.base) || '';
    var idCamp  = caja.getAttribute('data-vivo-campana') || '';
    var CADA    = 6000;
    var fallos  = 0;
    var timer   = null;

    function texto(sel, valor) {
        var el = doc.querySelector('[data-vivo-campo="' + sel + '"]');
        if (el && el.textContent !== String(valor)) { el.textContent = String(valor); }
    }

    function pintarCampana(c) {
        texto('enviados', c.enviados);
        texto('total', c.total);
        texto('rebotados', c.rebotados);
        texto('respondidos', c.respondidos);
        texto('aperturas', c.aperturas);
        texto('reuniones', c.reuniones);
        texto('errores', c.errores);

        var barra = doc.querySelector('[data-vivo-barra]');
        if (barra) {
            barra.style.width = c.pct + '%';
            barra.classList.toggle('ok', c.pct >= 100);
        }
        var badge = doc.querySelector('[data-vivo-estado]');
        if (badge && badge.textContent.trim().toLowerCase() !== c.estado) {
            badge.textContent = c.estado;
        }
        // Los porcentajes que acompañan a cada número.
        doc.querySelectorAll('[data-vivo-pct]').forEach(function (el) {
            var n = parseInt(c[el.getAttribute('data-vivo-pct')] || 0, 10);
            el.textContent = c.enviados > 0 ? (Math.round(1000 * n / c.enviados) / 10) : 0;
        });
    }

    function pintarFilas(filas) {
        filas.forEach(function (f) {
            var tr = doc.querySelector('[data-envio="' + f.id + '"]');
            if (!tr) { return; }
            var e = tr.querySelector('[data-vivo-badge]');
            if (e && e.innerHTML !== f.badge) { e.innerHTML = f.badge; }
            var en = tr.querySelector('[data-vivo-enviado]');
            if (en && en.textContent !== f.enviado) { en.textContent = f.enviado; }
            var re = tr.querySelector('[data-vivo-respondio]');
            if (re && re.textContent !== f.respondio) { re.textContent = f.respondio; }
        });
    }

    function marcarCron(activo) {
        var el = document.querySelector('[data-vivo-cron]');
        if (el) { el.hidden = !activo; }
    }

    function frenar() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function consultar() {
        if (document.hidden) { return; }        // nadie está mirando
        fetch(base + 'api-campana' + (idCamp ? '?id=' + encodeURIComponent(idCamp) : ''), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('http ' + r.status); }
            return r.json();
        }).then(function (d) {
            fallos = 0;
            if (!d || !d.ok) { return; }
            marcarCron(!!d.cron);

            if (d.campana) {
                pintarCampana(d.campana);
                if (d.filas) { pintarFilas(d.filas); }
                // Terminó: se deja de preguntar y se recarga una vez, para que
                // los botones de la pantalla queden acordes al estado nuevo.
                if (!d.campana.sigue) { frenar(); setTimeout(function () { location.reload(); }, 1200); }
            } else if (d.campanas) {
                d.campanas.forEach(function (c) {
                    var fila = doc.querySelector('[data-vivo-fila="' + c.id + '"]');
                    if (!fila) { return; }
                    var b = fila.querySelector('[data-vivo-barra]');
                    if (b) { b.style.width = c.pct + '%'; }
                    var t = fila.querySelector('[data-vivo-resumen]');
                    if (t) {
                        t.textContent = c.enviados + ' de ' + c.total + ' enviados · '
                                      + c.respondidos + ' respuestas · ' + c.rebotados + ' rebotes';
                    }
                });
                if (!d.campanas.some(function (c) { return c.sigue; })) { frenar(); }
            }
        }).catch(function () {
            // Tres fallos seguidos y se corta: si el servidor no responde, no
            // tiene sentido seguir insistiendo cada seis segundos.
            if (++fallos >= 3) { frenar(); }
        });
    }

    timer = setInterval(consultar, CADA);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { consultar(); }
    });
    consultar();
})();
