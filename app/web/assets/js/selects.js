/**
 * selects.js — la lista que se despliega también es nuestra.
 *
 * El campo cerrado ya se puede estilar, pero la lista que se abre al hacer clic
 * la dibuja el sistema operativo: otra tipografía, otro azul de selección, otro
 * tamaño en cada máquina. En el medio de una pantalla terminada se nota, y es
 * lo que hace que una aplicación parezca a medio hacer.
 *
 * El `<select>` de verdad se queda donde estaba y sigue siendo el que manda: es
 * el que tiene el `name`, el que guarda el valor y el que se envía. Esto es sólo
 * la cara. Dos consecuencias buenas: el servidor no se entera de nada, y si este
 * script no llega a cargar, el campo sigue funcionando como siempre.
 *
 * La lista se dibuja en una capa aparte, pegada al final de la página y ubicada
 * a mano. Parece rebuscado y no lo es: hay trece pantallas con desplegables
 * adentro de tablas que scrollean, y ahí una lista común queda recortada por el
 * borde de la tabla.
 */
(function () {
    'use strict';

    var doc = document;
    var abierto = null;          // el desplegable abierto, si hay alguno
    var capa = null;             // la lista, una sola para todos

    // ------------------------------------------------------------------
    //  Armado
    // ------------------------------------------------------------------

    function envolver(sel) {
        // Los de selección múltiple y los de tamaño fijo se dejan como están:
        // ahí el control nativo hace cosas que esto no reemplaza.
        if (sel.multiple || (sel.size && sel.size > 1) || sel.dataset.nativo === '1') { return; }
        if (sel.closest('[data-sel]')) { return; }

        var caja = doc.createElement('div');
        caja.className = 'sel';
        caja.setAttribute('data-sel', '');
        sel.parentNode.insertBefore(caja, sel);
        caja.appendChild(sel);

        var btn = doc.createElement('button');
        btn.type = 'button';                       // si no, envía el formulario
        btn.className = 'sel-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span class="sel-txt"></span>';
        caja.appendChild(btn);

        if (sel.disabled) { btn.disabled = true; }
        // El campo real deja de verse pero sigue existiendo: el formulario lo
        // manda igual y las validaciones del navegador lo siguen alcanzando.
        sel.classList.add('sel-real');
        sel.setAttribute('tabindex', '-1');
        sel.setAttribute('aria-hidden', 'true');

        pintar(caja);

        btn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            (abierto === caja) ? cerrar() : abrir(caja);
        });
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault(); abrir(caja);
            }
        });
        // Si algo cambia el valor por código, el rótulo tiene que seguirlo.
        sel.addEventListener('change', function () { pintar(caja); });
    }

    function pintar(caja) {
        var sel = caja.querySelector('select');
        var txt = caja.querySelector('.sel-txt');
        var op  = sel.options[sel.selectedIndex];
        txt.textContent = op ? op.text : '';
        // Un campo sin elegir se ve más apagado, como el ejemplo de un campo de
        // texto vacío: así se distingue de uno que sí tiene una opción puesta.
        caja.classList.toggle('sel-vacio', !op || op.value === '');
    }

    // ------------------------------------------------------------------
    //  Abrir, elegir, cerrar
    // ------------------------------------------------------------------

    function abrir(caja) {
        cerrar();
        var sel = caja.querySelector('select');
        if (sel.disabled || !sel.options.length) { return; }

        capa = doc.createElement('div');
        capa.className = 'sel-lista';
        capa.setAttribute('role', 'listbox');

        Array.prototype.forEach.call(sel.options, function (o, i) {
            var fila = doc.createElement('div');
            fila.className = 'sel-op' + (i === sel.selectedIndex ? ' elegida' : '')
                           + (o.disabled ? ' inhabilitada' : '');
            fila.setAttribute('role', 'option');
            fila.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false');
            fila.textContent = o.text;
            fila.dataset.i = String(i);
            if (!o.disabled) {
                fila.addEventListener('click', function () { elegir(caja, i); });
            }
            capa.appendChild(fila);
        });

        doc.body.appendChild(capa);
        abierto = caja;
        caja.classList.add('abierto');
        caja.querySelector('.sel-btn').setAttribute('aria-expanded', 'true');
        ubicar();

        var elegida = capa.querySelector('.elegida');
        if (elegida) { elegida.scrollIntoView({ block: 'nearest' }); }
        marcar(sel.selectedIndex);
    }

    /** Pone la lista pegada al campo, y arriba si abajo no entra. */
    function ubicar() {
        if (!abierto || !capa) { return; }
        var r = abierto.getBoundingClientRect();
        var alto = capa.offsetHeight;
        var abajo = window.innerHeight - r.bottom;

        capa.style.minWidth = r.width + 'px';
        capa.style.left = Math.max(8, Math.min(r.left, window.innerWidth - r.width - 8)) + 'px';
        if (abajo < alto + 12 && r.top > abajo) {
            capa.style.top = Math.max(8, r.top - alto - 4) + 'px';
        } else {
            capa.style.top = (r.bottom + 4) + 'px';
        }
    }

    function elegir(caja, i) {
        var sel = caja.querySelector('select');
        if (sel.selectedIndex !== i) {
            sel.selectedIndex = i;
            pintar(caja);
            // Se avisa como lo haría el campo de verdad: hay pantallas que
            // recargan el filtro al cambiar un desplegable (`data-auto`), y sin
            // esto dejarían de hacerlo.
            sel.dispatchEvent(new Event('input',  { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        cerrar();
        caja.querySelector('.sel-btn').focus();
    }

    function cerrar() {
        if (capa && capa.parentNode) { capa.parentNode.removeChild(capa); }
        if (abierto) {
            abierto.classList.remove('abierto');
            abierto.querySelector('.sel-btn').setAttribute('aria-expanded', 'false');
        }
        capa = null; abierto = null;
    }

    function marcar(i) {
        if (!capa) { return; }
        capa.querySelectorAll('.sel-op').forEach(function (f) { f.classList.remove('activa'); });
        var f = capa.querySelector('.sel-op[data-i="' + i + '"]');
        if (f) { f.classList.add('activa'); f.scrollIntoView({ block: 'nearest' }); }
    }

    function activa() {
        var f = capa && capa.querySelector('.sel-op.activa');
        return f ? parseInt(f.dataset.i, 10) : -1;
    }

    // ------------------------------------------------------------------
    //  Teclado: tiene que poder usarse sin tocar el mouse
    // ------------------------------------------------------------------
    var tecleo = '', tecleoReloj = null;

    doc.addEventListener('keydown', function (e) {
        if (!abierto) { return; }
        var sel = abierto.querySelector('select');
        var n = sel.options.length;
        var i = activa();

        if (e.key === 'Escape')      { e.preventDefault(); cerrar(); abierto && abierto.querySelector('.sel-btn').focus(); return; }
        if (e.key === 'Tab')         { cerrar(); return; }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (i >= 0 && !sel.options[i].disabled) { elegir(abierto, i); }
            return;
        }
        if (e.key === 'ArrowDown')   { e.preventDefault(); marcar(Math.min(n - 1, i + 1)); return; }
        if (e.key === 'ArrowUp')     { e.preventDefault(); marcar(Math.max(0, i - 1)); return; }
        if (e.key === 'Home')        { e.preventDefault(); marcar(0); return; }
        if (e.key === 'End')         { e.preventDefault(); marcar(n - 1); return; }

        // Escribir el principio de una opción la busca, como el campo nativo.
        if (e.key.length === 1) {
            tecleo += e.key.toLowerCase();
            clearTimeout(tecleoReloj);
            tecleoReloj = setTimeout(function () { tecleo = ''; }, 700);
            for (var k = 0; k < n; k++) {
                if (sel.options[k].text.toLowerCase().indexOf(tecleo) === 0) { marcar(k); break; }
            }
        }
    });

    doc.addEventListener('click', function (e) {
        if (abierto && !e.target.closest('.sel-lista') && !e.target.closest('[data-sel]')) { cerrar(); }
    });
    // Al scrollear o cambiar el tamaño, la lista tiene que seguir al campo:
    // está en una capa aparte y no se mueve sola con la página.
    window.addEventListener('scroll', ubicar, true);
    window.addEventListener('resize', function () { cerrar(); });

    // ------------------------------------------------------------------
    //  Arranque, y cada vez que aparecen campos nuevos
    // ------------------------------------------------------------------
    function armar(raiz) {
        (raiz || doc).querySelectorAll('select').forEach(envolver);
    }

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', function () { armar(); });
    } else {
        armar();
    }
    // El refresco automático reemplaza zonas enteras de la pantalla: los campos
    // que trae son nuevos y todavía no pasaron por acá.
    doc.addEventListener('vivo:actualizado', function () { cerrar(); armar(); });
})();
