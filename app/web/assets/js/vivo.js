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
 *
 * Corrección sobre lo de «las tres pantallas»: la parte de campañas arranca
 * sólo si la página tiene [data-vivo-campanas], y eso hoy lo tienen el listado
 * (campanas.php) y el panel (dashboard.php). La ficha (campana-ver.php) marca
 * data-vivo-campana pero no data-vivo-campanas, así que en la ficha este
 * archivo no hace nada: sus números y la cola los refresca secciones.js, que
 * vuelve a pedir la pantalla entera. pintarFicha() y pintarCola() no se
 * ejecutan nunca.
 *
 * Quién lo carga: layout.php, con `defer`, sólo con sesión iniciada.
 *
 * Con qué se engancha en el HTML:
 *   · Campana (layout.php): [data-alertas] y [data-alertas-n] (el globo).
 *     Consulta api-alertas cada 15 s; espera { ok, sin_leer, ultimas: [{ id,
 *     tipo, titulo, detalle, url }] }.
 *   · Campañas: [data-vivo-campanas] activa la consulta a api-campana; cada
 *     fila es [data-vivo-fila=ID] con [data-vivo-barra], [data-vivo-avance],
 *     [data-vivo-resumen], [data-vivo-col=...] y [data-vivo-badge]; además
 *     [data-vivo-cron] (aviso de cron en curso). Para la ficha estaban
 *     previstos [data-vivo-campana=ID], [data-vivo-campo=...] y las filas
 *     [data-envio=ID] de la cola.
 *
 * Datos del servidor y HTML: todo se escribe con textContent, salvo el
 * «badge» del estado, que llega ya armado (badge_campana / badge_envio en el
 * servidor, a partir del estado) y se inserta con innerHTML.
 *
 * Ninguna de estas consultas lleva `__vivo`, así que bootstrap.php las cuenta
 * como actividad y renueva la sesión: con la pestaña a la vista, la sesión no
 * vence del lado del servidor y el corte depende de idle.js.
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
        var visto  = null;   // id de la alerta más nueva que ya se mostró

        // ------------------------------------------------------------------
        //  El aviso en pantalla.
        //
        //  Un número chiquito arriba a la derecha no alcanza: si estás mirando
        //  otra parte de la pantalla, no te enterás. Y enterarse de que un
        //  cliente contestó es justamente lo que no puede esperar. Esto asoma,
        //  se queda unos segundos y se va solo; con un clic te lleva a leerla.
        // ------------------------------------------------------------------
        /**
         * Devuelve el contenedor de avisos (.avisos), creándolo la primera vez.
         * @returns {HTMLElement}
         */
        function pila() {
            var p = doc.querySelector('.avisos');
            if (!p) {
                p = doc.createElement('div');
                p.className = 'avisos';
                p.setAttribute('aria-live', 'polite');   // los lectores de pantalla también lo anuncian
                doc.body.appendChild(p);
            }
            return p;
        }

        /**
         * Muestra un aviso emergente para una alerta nueva. Se va solo a los
         * 9 segundos o con la cruz; un clic en el resto lleva a a.url.
         *
         * El título y el detalle van con textContent. a.tipo se usa como
         * parte del nombre de una clase y a.url va directo al href, sin
         * revisar: los dos los arma el servidor al crear la alerta.
         *
         * @param {{id:number, tipo:string, titulo:string, detalle:string, url:string}} a
         */
        function avisar(a) {
            var el = doc.createElement('a');
            el.className = 'aviso aviso-' + (a.tipo || 'info');
            el.href = a.url;
            el.innerHTML = '<strong></strong><span></span><i class="aviso-x" aria-hidden="true">×</i>';
            el.querySelector('strong').textContent = a.titulo || 'Novedad';
            el.querySelector('span').textContent   = a.detalle || '';
            el.querySelector('.aviso-x').addEventListener('click', function (ev) {
                ev.preventDefault(); ev.stopPropagation(); cerrar(el);
            });
            pila().appendChild(el);
            // Sin esto el navegador dibuja el elemento ya en su lugar y no hay
            // transición: tiene que existir primero y moverse después.
            requestAnimationFrame(function () { el.classList.add('visible'); });
            setTimeout(function () { cerrar(el); }, 9000);
        }

        /**
         * Oculta un aviso con su transición y lo quita a los 300 ms. Se puede
         * llamar dos veces (cruz y plazo) sin problema.
         * @param {HTMLElement} el
         */
        function cerrar(el) {
            if (!el || !el.parentNode) { return; }
            el.classList.remove('visible');
            setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 300);
        }

        /**
         * Consulta api-alertas y actualiza el globo, el título de la pestaña
         * y los avisos emergentes. Con la pestaña oculta no consulta.
         *
         * Tres errores seguidos (incluida una sesión vencida, que vuelve 440)
         * cortan el reloj para siempre: volver a la pestaña hace una consulta
         * suelta, pero el intervalo no se rearma.
         */
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

                    // Las nuevas se avisan en pantalla. En la primera consulta
                    // sólo se toma nota de por dónde vamos: si no, al abrir el
                    // CRM te saltan de golpe todas las alertas viejas.
                    var ultimas = d.ultimas || [];
                    var masNueva = ultimas.length ? ultimas[0].id : 0;
                    if (visto === null) { visto = masNueva; return; }
                    if (masNueva <= visto) { return; }

                    ultimas.filter(function (a) { return a.id > visto; })
                           .reverse()                      // de la más vieja a la más nueva
                           .slice(-3)                      // tres a la vez es el techo de lo legible
                           .forEach(avisar);
                    visto = masNueva;
                })
                .catch(function () { if (++malas >= 3 && reloj) { clearInterval(reloj); reloj = null; } });
        }
        reloj = setInterval(mirar, 15000);
        doc.addEventListener('visibilitychange', function () { if (!doc.hidden) { mirar(); } });
        mirar();
    })();

    // ------------------------------------------------------------------
    //  Avance de las campañas: sólo en las pantallas que lo piden.
    // ------------------------------------------------------------------
    if (!doc.querySelector('[data-vivo-campanas]')) { return; }

    var base   = (window.__sesion && window.__sesion.base) || '';
    // Id de la campaña si la pantalla es una ficha ([data-vivo-campana]); vacío
    // en el listado y el panel. El getAttribute del lado izquierdo sólo
    // comprueba que se encontró un elemento (un objeto vacío no lo tiene).
    var idFicha = (doc.querySelector('[data-vivo-campana]') || {}).getAttribute
                ? doc.querySelector('[data-vivo-campana]').getAttribute('data-vivo-campana') : '';

    var RAPIDO = 6000;      // mientras está mandando
    var LENTO  = 30000;     // ya terminó, pero pueden entrar respuestas
    var ritmo  = RAPIDO;
    var fallos = 0;
    var timer  = null;

    /**
     * Escribe un valor como texto, sólo si cambió (para no tocar el DOM ni
     * reiniciar selecciones en cada vuelta). Tolera que el elemento no exista.
     *
     * @param {?Element} el
     * @param {*} valor
     */
    function poner(el, valor) {
        if (el && el.textContent !== String(valor)) { el.textContent = String(valor); }
    }

    /**
     * Actualiza una fila del listado o del panel.
     *
     * @param {Object} c campaña según api-campana: id, pct, procesados, total,
     *   enviados, respondidos, rebotados, reuniones, aperturas, badge, sigue.
     */
    function pintarFila(c) {
        var fila = doc.querySelector('[data-vivo-fila="' + c.id + '"]');
        if (!fila) { return; }

        var barra = fila.querySelector('[data-vivo-barra]');
        if (barra) {
            barra.style.width = c.pct + '%';
            barra.classList.toggle('ok', c.pct >= 100);
        }
        // El listado muestra «3 / 6»; el panel, la frase completa.
        // El avance mide lo resuelto —entregados más rebotados—, no sólo lo
        // entregado: una campaña que entregó 3 y rebotó 3 terminó con los 6.
        poner(fila.querySelector('[data-vivo-avance]'), c.procesados + ' / ' + c.total);
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

    /**
     * Actualiza la ficha de una campaña. Hoy no se ejecuta: ver la corrección
     * del encabezado sobre data-vivo-campanas.
     *
     * @param {Object} c campaña según api-campana (con errores y total).
     */
    function pintarFicha(c) {
        ['enviados', 'total', 'total2', 'procesados', 'rebotados', 'respondidos', 'aperturas', 'reuniones', 'errores']
            .forEach(function (k) {
                poner(doc.querySelector('[data-vivo-campo="' + k + '"]'), k === 'total2' ? c.total : c[k]);
            });
        var barra = doc.querySelector('[data-vivo-barra]');
        if (barra) {
            barra.style.width = c.pct + '%';
            barra.classList.toggle('ok', c.pct >= 100);
        }
    }

    /**
     * Actualiza las filas de la cola de envíos de la ficha (estado, fecha de
     * envío y de respuesta). Mismo caso que pintarFicha(): hoy no se ejecuta.
     *
     * @param {Array<{id:number, badge:string, enviado:string, respondio:string}>} filas
     */
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

    /**
     * Muestra u oculta el aviso de «el cron está corriendo».
     * @param {boolean} activo
     */
    function marcarCron(activo) {
        var el = doc.querySelector('[data-vivo-cron]');
        if (el) { el.hidden = !activo; }
    }

    /**
     * Cambia el ritmo de consulta si es distinto del actual. A diferencia de
     * secciones.js, no rearma un reloj cortado por fallos: si el ritmo no
     * cambia y el reloj está detenido, sigue detenido.
     *
     * @param {number} nuevo milisegundos entre consultas.
     */
    function reprogramar(nuevo) {
        if (nuevo === ritmo) { return; }
        ritmo = nuevo;
        if (timer) { clearInterval(timer); }
        timer = setInterval(consultar, ritmo);
    }

    /**
     * Consulta api-campana (con ?id= en una ficha) y pinta lo que vuelve:
     * `campana` + `filas` para una ficha, `campanas` para listado y panel.
     * Ajusta el ritmo según haya alguna campaña que siga mandando (`sigue`).
     * Una sesión vencida (440) cuenta como fallo; no recarga la página.
     */
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
