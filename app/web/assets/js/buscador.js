/**
 * buscador.js — desplegables que buscan al escribir.
 *
 * Producción tiene más de 25.000 contactos y 9.000 empresas. Un <select> con
 * todas las filas pesa megas y tarda en abrir; recortado a unas miles, el
 * valor vinculado podía quedar afuera y guardar sin tocar nada lo borraba.
 *
 * Así que esos <select> llegan del servidor sólo con la opción vacía y el
 * valor ya vinculado, marcados con `data-buscar="cuenta|contacto|negocio|
 * campana"` (y `data-nativo="1"`, para que selects.js no los envuelva).
 * Este script pone al lado un campo de texto: al escribir consulta la ruta
 * api-buscar y muestra los resultados; al elegir uno, agrega esa opción al
 * <select> y la marca. El <select> sigue siendo el que tiene el `name` y el
 * que se envía, así que el POST no cambia, y si el script no carga el campo
 * sigue funcionando con el valor que tenía.
 *
 * Reglas para no perder nada:
 *  · escribir sin elegir no cambia el valor: al salir del campo vuelve el
 *    texto de lo que estaba elegido;
 *  · borrar el texto (o la ×) deja el campo vacío, que es «ninguno».
 *
 * Quién lo carga: layout.php, con `defer`, sólo con sesión. Vuelve a correr
 * con el evento `vivo:actualizado` de secciones.js. La lista usa los estilos
 * de selects.js (.sel-lista, .sel-op) para que se vea igual que los demás.
 */
(function () {
    'use strict';

    var doc = document;
    var base = (window.__sesion && window.__sesion.base) || '';
    var abierto = null;     // el buscador con la lista abierta
    var capa = null;        // la lista, una sola para todos
    var cuenta = 0;         // para descartar respuestas viejas
    var MIN = 2;

    /**
     * Arma el buscador de un <select data-buscar>. Si ya estaba armado no
     * hace nada.
     * @param {HTMLSelectElement} sel
     */
    function armarUno(sel) {
        if (sel.closest('.busc')) { return; }
        var caja = doc.createElement('div');
        caja.className = 'busc';
        sel.parentNode.insertBefore(caja, sel);
        caja.appendChild(sel);

        var vacio = sel.querySelector('option[value=""]');
        var inp = doc.createElement('input');
        inp.type = 'text';
        inp.className = 'busc-input';
        inp.autocomplete = 'off';
        inp.setAttribute('role', 'combobox');
        inp.setAttribute('aria-autocomplete', 'list');
        inp.setAttribute('aria-expanded', 'false');
        inp.placeholder = sel.getAttribute('data-placeholder') || 'Escribí para buscar…';
        if (vacio && vacio.text) { inp.setAttribute('aria-label', vacio.text.replace(/[—\s]+/g, ' ').trim()); }
        if (sel.disabled) { inp.disabled = true; }
        caja.appendChild(inp);

        var x = doc.createElement('button');
        x.type = 'button';
        x.className = 'busc-limpiar';
        x.title = 'Quitar';
        x.setAttribute('aria-label', 'Quitar');
        x.textContent = '×';
        caja.appendChild(x);

        // El select real queda debajo, invisible pero presente: si es
        // obligatorio, el navegador todavía puede mostrar su aviso.
        sel.classList.add('busc-real');
        sel.setAttribute('tabindex', '-1');
        sel.setAttribute('aria-hidden', 'true');

        var reloj = null;
        mostrarElegido(caja);

        inp.addEventListener('input', function () {
            clearTimeout(reloj);
            var q = inp.value.trim();
            if (q === '') { cerrar(); return; }
            if (q.length < MIN) { pintar(caja, null, 'Escribí al menos ' + MIN + ' letras…'); return; }
            reloj = setTimeout(function () { buscar(caja, q); }, 250);
        });
        inp.addEventListener('keydown', function (e) { teclas(caja, e); });
        inp.addEventListener('blur', function () {
            // Se espera un poco: el clic en una fila de la lista llega después del blur.
            setTimeout(function () {
                if (abierto === caja && capa && capa.contains(doc.activeElement)) { return; }
                if (abierto === caja) { cerrar(); }
                if (inp.value.trim() === '') { elegir(caja, '', ''); } else { mostrarElegido(caja); }
            }, 180);
        });
        x.addEventListener('click', function () {
            elegir(caja, '', '');
            inp.focus();
        });
        sel.addEventListener('change', function () { mostrarElegido(caja); });
    }

    /** Copia al campo de texto el nombre de la opción elegida. */
    function mostrarElegido(caja) {
        var sel = caja.querySelector('select');
        var inp = caja.querySelector('.busc-input');
        var op = sel.options[sel.selectedIndex];
        var hay = !!(op && op.value !== '');
        inp.value = hay ? op.text : '';
        caja.classList.toggle('busc-con-valor', hay);
    }

    /**
     * Marca un valor en el select real. Si la opción no existe, la agrega.
     * Avisa con un `change` para que otros scripts se enteren.
     */
    function elegir(caja, id, nombre) {
        var sel = caja.querySelector('select');
        id = String(id);
        if (id !== '' && !Array.prototype.some.call(sel.options, function (o) { return o.value === id; })) {
            sel.appendChild(new Option(nombre, id));
        }
        var antes = sel.value;
        sel.value = id;
        mostrarElegido(caja);
        if (antes !== id) { sel.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    /** Consulta api-buscar y dibuja los resultados. */
    function buscar(caja, q) {
        var sel = caja.querySelector('select');
        var mia = ++cuenta;
        pintar(caja, null, 'Buscando…');
        fetch(base + 'api-buscar?tipo=' + encodeURIComponent(sel.getAttribute('data-buscar'))
              + '&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (d) {
                if (mia !== cuenta || !d || !d.ok) { if (mia === cuenta) { pintar(caja, null, 'No se pudo buscar.'); } return; }
                pintar(caja, d.resultados || [], 'No hay coincidencias.');
            })
            .catch(function () { if (mia === cuenta) { pintar(caja, null, 'No se pudo buscar. Revisá la conexión.'); } });
    }

    /**
     * Dibuja la lista: una fila por resultado o un aviso si no hay.
     * @param {HTMLElement} caja
     * @param {Array|null} lista resultados {id, nombre, detalle}, o null para sólo el aviso
     * @param {string} aviso el texto si la lista viene vacía
     */
    function pintar(caja, lista, aviso) {
        var inp = caja.querySelector('.busc-input');
        if (doc.activeElement !== inp) { return; }
        if (abierto !== caja) { cerrar(); }
        if (!capa) {
            capa = doc.createElement('div');
            capa.className = 'sel-lista busc-lista';
            capa.id = 'busc-lista';
            capa.setAttribute('role', 'listbox');
            doc.body.appendChild(capa);
        }
        abierto = caja;
        inp.setAttribute('aria-expanded', 'true');
        inp.setAttribute('aria-controls', capa.id);
        capa.textContent = '';
        if (!lista || !lista.length) {
            var av = doc.createElement('div');
            av.className = 'sel-op busc-aviso';
            av.textContent = aviso;
            capa.appendChild(av);
        } else {
            lista.forEach(function (r, i) {
                var fila = doc.createElement('div');
                fila.className = 'sel-op';
                fila.id = 'busc-op-' + i;
                fila.setAttribute('role', 'option');
                fila.dataset.id = String(r.id);
                fila.dataset.nombre = r.nombre;
                var t = doc.createElement('span');
                t.textContent = r.nombre;
                fila.appendChild(t);
                if (r.detalle) {
                    var dd = doc.createElement('span');
                    dd.className = 'busc-det';
                    dd.textContent = r.detalle;
                    fila.appendChild(dd);
                }
                // mousedown y no click: así se elige antes de que el blur cierre la lista.
                fila.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    elegir(caja, r.id, r.nombre);
                    cerrar();
                });
                capa.appendChild(fila);
            });
        }
        ubicar();
    }

    /** Flechas, Enter y Escape mientras se escribe. */
    function teclas(caja, e) {
        var filas = capa && abierto === caja ? capa.querySelectorAll('.sel-op[role=option]') : [];
        var inp = caja.querySelector('.busc-input');
        var i = Array.prototype.findIndex.call(filas, function (f) { return f.classList.contains('activa'); });
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!filas.length) { return; }
            e.preventDefault();
            i = e.key === 'ArrowDown' ? Math.min(filas.length - 1, i + 1) : Math.max(0, i - 1);
            Array.prototype.forEach.call(filas, function (f, j) { f.classList.toggle('activa', j === i); });
            inp.setAttribute('aria-activedescendant', filas[i].id);
            filas[i].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            // Enter nunca envía el formulario desde acá: elige, o no hace nada.
            if (abierto === caja) {
                e.preventDefault();
                var f = filas[i >= 0 ? i : 0];
                if (f) { elegir(caja, f.dataset.id, f.dataset.nombre); cerrar(); }
            } else if (inp.value.trim() !== '') {
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            if (abierto === caja) { e.preventDefault(); cerrar(); mostrarElegido(caja); }
        }
    }

    /** Ubica la lista debajo del campo (o arriba si no entra). */
    function ubicar() {
        if (!capa || !abierto) { return; }
        var r = abierto.querySelector('.busc-input').getBoundingClientRect();
        capa.style.minWidth = r.width + 'px';
        capa.style.left = Math.max(8, Math.min(r.left, window.innerWidth - capa.offsetWidth - 8)) + 'px';
        var abajo = window.innerHeight - r.bottom;
        if (abajo < capa.offsetHeight + 8 && r.top > abajo) {
            capa.style.top = Math.max(8, r.top - capa.offsetHeight - 4) + 'px';
        } else {
            capa.style.top = (r.bottom + 4) + 'px';
        }
    }

    function cerrar() {
        if (abierto) {
            var inp = abierto.querySelector('.busc-input');
            inp.setAttribute('aria-expanded', 'false');
            inp.removeAttribute('aria-activedescendant');
        }
        if (capa) { capa.remove(); capa = null; }
        abierto = null;
    }

    window.addEventListener('scroll', ubicar, true);
    window.addEventListener('resize', function () { cerrar(); });

    /** Arma todos los <select data-buscar> bajo raiz. */
    function armar(raiz) {
        (raiz || doc).querySelectorAll('select[data-buscar]').forEach(armarUno);
    }
    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', function () { armar(); });
    } else {
        armar();
    }
    doc.addEventListener('vivo:actualizado', function () { cerrar(); armar(); });
})();
