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
 *
 * Quién lo carga: layout.php, con `defer`, con o sin sesión, después de
 * campos.js. Envuelve TODOS los <select> de la página salvo los múltiples,
 * los de `size` mayor a 1 y los marcados con data-nativo="1". Vuelve a correr
 * con el evento `vivo:actualizado` de secciones.js.
 *
 * Marcado que genera (los estilos están en app.css, sección «La lista
 * desplegada también es nuestra»):
 *   div.sel[data-sel] > select.sel-real + button.sel-btn > span.sel-txt
 *   y, mientras está abierta, div.sel-lista[role=listbox] > div.sel-op
 *   colgada del body.
 *
 * Accesibilidad, lo que hay y lo que falta: el botón anuncia aria-haspopup y
 * aria-expanded, la lista tiene roles listbox/option y se maneja con flechas,
 * Inicio, Fin, Escape, Enter y escribiendo las primeras letras. No hay
 * aria-activedescendant (el lector de pantalla no anuncia la opción resaltada)
 * y el botón no toma el rótulo del <label>: su nombre accesible es el texto
 * de la opción elegida.
 */
(function () {
    'use strict';

    var doc = document;
    var abierto = null;          // el desplegable abierto, si hay alguno
    var capa = null;             // la lista, una sola para todos

    // ------------------------------------------------------------------
    //  Armado
    // ------------------------------------------------------------------

    /**
     * Envuelve un <select> con su botón propio. Si ya estaba envuelto no hace
     * nada, así que se puede llamar sobre toda la página tantas veces como
     * haga falta.
     *
     * El estado `disabled` se copia una sola vez, al envolver: si después se
     * habilita o deshabilita el select por código, el botón no se entera.
     *
     * @param {HTMLSelectElement} sel
     */
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
        // Ojo con el orden de los manejadores: este corre primero y abre la
        // lista; después el mismo evento llega al keydown de `document` (más
        // abajo), que ya la ve abierta. Con las flechas eso mueve la marca una
        // opción; con Enter o Espacio elige la opción actual y la cierra en el
        // acto, así que con esas dos teclas la lista no llega a verse.
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault(); abrir(caja);
            }
        });
        // Si algo cambia el valor por código, el rótulo tiene que seguirlo.
        sel.addEventListener('change', function () { pintar(caja); });
    }

    /**
     * Copia al botón el texto de la opción elegida en el select real.
     *
     * @param {HTMLElement} caja el div.sel que envuelve al select.
     */
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

    /**
     * Abre la lista de un desplegable: cierra la que hubiera abierta, arma
     * una fila por opción (con textContent, nunca HTML), la cuelga del body,
     * la ubica y deja marcada la opción elegida. No abre si el select está
     * deshabilitado o no tiene opciones.
     *
     * Los manejadores de clic de cada fila se van con la capa al cerrarla.
     *
     * @param {HTMLElement} caja el div.sel que envuelve al select.
     */
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

    /**
     * Pone la lista pegada al campo, y arriba si abajo no entra.
     * Coordenadas de ventana: la capa es position:fixed, por eso hay que
     * recalcular en cada scroll.
     */
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

    /**
     * Elige la opción i en el select real, avisa con `input` y `change` si
     * cambió, cierra la lista y devuelve el foco al botón.
     *
     * @param {HTMLElement} caja
     * @param {number} i índice de la opción.
     */
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

    /** Cierra la lista abierta, si hay una, y deja `abierto` y `capa` en null. */
    function cerrar() {
        if (capa && capa.parentNode) { capa.parentNode.removeChild(capa); }
        if (abierto) {
            abierto.classList.remove('abierto');
            abierto.querySelector('.sel-btn').setAttribute('aria-expanded', 'false');
        }
        capa = null; abierto = null;
    }

    /**
     * Resalta la fila i (la «activa» del teclado) y la trae a la vista.
     * Es sólo visual: no elige nada hasta Enter o clic.
     *
     * @param {number} i índice de la opción.
     */
    function marcar(i) {
        if (!capa) { return; }
        capa.querySelectorAll('.sel-op').forEach(function (f) { f.classList.remove('activa'); });
        var f = capa.querySelector('.sel-op[data-i="' + i + '"]');
        if (f) { f.classList.add('activa'); f.scrollIntoView({ block: 'nearest' }); }
    }

    /** @returns {number} índice de la fila resaltada, o -1 si no hay ninguna. */
    function activa() {
        var f = capa && capa.querySelector('.sel-op.activa');
        return f ? parseInt(f.dataset.i, 10) : -1;
    }

    // ------------------------------------------------------------------
    //  Teclado: tiene que poder usarse sin tocar el mouse
    // ------------------------------------------------------------------
    // tecleo: letras escritas seguidas para buscar una opción; se vacía tras
    // 700 ms sin teclear (tecleoReloj).
    var tecleo = '', tecleoReloj = null;

    // Un solo manejador para todos los desplegables; sólo actúa con una lista
    // abierta. Escape: cerrar() deja `abierto` en null antes de la expresión
    // que intenta devolver el foco al botón, así que el foco no vuelve.
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
    /**
     * Envuelve todos los <select> que encuentre bajo raiz.
     * @param {ParentNode} [raiz] por defecto, toda la página.
     */
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
