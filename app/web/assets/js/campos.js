/**
 * campos.js — los campos no dejan escribir lo que no corresponde.
 *
 * El servidor ya rechaza un teléfono con letras, y el formulario tiene su
 * `pattern`. Pero las dos cosas actúan recién cuando apretás «Guardar»: hasta
 * ese momento el campo te deja escribir `nknknknkn12312131` como si nada, y el
 * aviso llega después de haber llenado todo el formulario. Eso no se siente
 * como un sistema que sabe lo que pide.
 *
 * Acá las letras directamente no entran. Se filtran mientras escribís, se
 * respeta dónde tenías el cursor, y también se limpia lo que venga pegado
 * desde otro lado, que es por donde se cuela la basura de verdad.
 *
 * Va en TODAS las pantallas, incluidas las públicas. La página de reservar una
 * reunión la usa gente de afuera, no cargaba ningún script, y era justamente
 * donde peor se portaba el campo de teléfono.
 *
 * Quién lo carga: layout.php, con `defer`, con o sin sesión (es el primero de
 * los scripts de la página).
 *
 * Con qué se engancha en el HTML:
 *   · input[type="tel"]: teléfonos (contactos, empresas, posibles clientes,
 *     ajustes, reserva pública). Regla TELEFONO.
 *   · input[data-solo="digitos"]: sólo números (embudos). En
 *     actividad-form, data-solo tiene otro uso (tarea/reunion/llamada, qué
 *     campos se muestran según el tipo) y acá no matchea ninguna regla.
 *   · input[data-solo="cuit"]: números y guiones (cuenta-form).
 *   · select[data-tipo-empresa] + select[data-sector-empresa] con
 *     data-mapa (JSON tipo → sectores, de sectores_por_tipo()) y
 *     data-elegido (el sector guardado): la ficha de empresa (cuenta-form).
 *
 * Es sólo comodidad: el servidor valida igual, porque el filtro se saltea
 * fácil (sin JavaScript, o mandando el formulario a mano).
 */
(function () {
    'use strict';

    // Lo que puede tener un teléfono escrito a mano: +54 9 11 5162-9038,
    // (011) 4567-8901, 11.2345.6789. La misma regla que valida el servidor.
    // Las tres expresiones están NEGADAS ([^...]): describen lo que se borra,
    // no lo que se permite. El servidor (TELEFONO_PATRON en crm.php) además
    // exige un largo de 6 a 40, que acá no se controla.
    var TELEFONO = /[^0-9+()./\s-]/g;
    var SOLO_DIGITOS = /[^0-9]/g;
    var CUIT = /[^0-9-]/g;          // 30-12345678-1

    /**
     * ¿Qué se le permite a este campo?
     *
     * @param {Element} el el campo donde se escribió o pegó.
     * @returns {RegExp|null} la expresión de lo que hay que borrar, o null si
     *   el campo no tiene filtro.
     */
    function reglaDe(el) {
        if (!el || !el.matches) { return null; }
        if (el.matches('input[data-solo="digitos"]')) { return SOLO_DIGITOS; }
        if (el.matches('input[data-solo="cuit"]'))    { return CUIT; }
        if (el.matches('input[type="tel"]'))          { return TELEFONO; }
        return null;
    }

    /**
     * Borra del valor del campo lo que la regla no admite, conservando la
     * posición del cursor. Si no había nada que borrar, no toca el campo (así
     * no se pierde la selección ni se mueve el cursor al escribir normal).
     *
     * @param {HTMLInputElement} el
     * @param {RegExp} regla expresión global de caracteres a quitar.
     */
    function limpiar(el, regla) {
        var antes = el.value;
        var despues = antes.replace(regla, '');
        if (despues === antes) { return; }

        // Sin esto, escribir una letra en el medio de un número te manda el
        // cursor al final y seguís escribiendo en el lugar equivocado.
        var pos = el.selectionStart;
        var quitados = antes.slice(0, pos).length - antes.slice(0, pos).replace(regla, '').length;
        el.value = despues;
        try { el.setSelectionRange(pos - quitados, pos - quitados); } catch (e) {}
    }

    // En captura, para limpiar antes de que otros manejadores de `input` lean
    // el valor.
    document.addEventListener('input', function (ev) {
        var regla = reglaDe(ev.target);
        if (regla) { limpiar(ev.target, regla); }
    }, true);

    // ------------------------------------------------------------------
    //  El sector depende del tipo de empresa.
    //
    //  A un proveedor no le sirven las mismas categorías que a un cliente.
    //  Se elige de una lista y no se escribe a mano, para que una misma
    //  industria no quede cargada de varias formas («Logistica», «logística»,
    //  «Transporte y logística») y los filtros y segmentaciones funcionen.
    // ------------------------------------------------------------------

    /**
     * Rehace las opciones del select de sector según el tipo de empresa
     * elegido, a partir del mapa JSON de data-mapa. Se llama al cargar la
     * página y cada vez que cambia el tipo.
     *
     * Si el sector guardado (data-elegido) no figura en la lista del tipo
     * guardado (un valor viejo cargado a mano o importado), se agrega al final
     * como opción extra, marcada «(valor anterior)», para que abrir y guardar
     * la ficha no obligue a cambiarlo. Si se elige otro tipo, esa opción deja
     * de ofrecerse: el sector viejo no tiene por qué servir para el tipo nuevo.
     * Si data-mapa no es JSON válido, no se toca nada.
     */
    var tipoInicial = null;   // el tipo con el que se abrió la ficha
    function llenarSectores() {
        var tipo = document.querySelector('[data-tipo-empresa]');
        var sec  = document.querySelector('[data-sector-empresa]');
        if (!tipo || !sec) { return; }

        var mapa = {};
        try { mapa = JSON.parse(sec.getAttribute('data-mapa') || '{}'); } catch (e) { return; }
        // Lo que ya estaba guardado tiene que seguir elegido al abrir la ficha.
        var elegido = sec.value || sec.getAttribute('data-elegido') || '';
        var opciones = mapa[tipo.value] || [];

        // Las opciones nuevas se crean con textContent, no con HTML: los
        // nombres de sector no se interpretan como marcado.
        sec.innerHTML = '';
        var vacia = document.createElement('option');
        vacia.value = '';
        vacia.textContent = opciones.length ? '— Elegí el sector —' : '— Elegí primero el tipo —';
        sec.appendChild(vacia);

        opciones.forEach(function (o) {
            var op = document.createElement('option');
            op.value = o; op.textContent = o;
            if (o === elegido) { op.selected = true; }
            sec.appendChild(op);
        });

        if (tipoInicial === null) { tipoInicial = tipo.value; }
        var guardado = sec.getAttribute('data-elegido') || '';
        if (guardado !== '' && tipo.value === tipoInicial && opciones.indexOf(guardado) === -1) {
            var viejo = document.createElement('option');
            viejo.value = guardado;
            viejo.textContent = guardado + ' (valor anterior)';
            if (elegido === guardado) { viejo.selected = true; }
            sec.appendChild(viejo);
        }
        // El desplegable propio tiene que redibujarse: su lista es una copia.
        sec.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-tipo-empresa]')) { llenarSectores(); }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', llenarSectores);
    } else {
        llenarSectores();
    }

    // Pegar es por donde entra el texto sucio de verdad: un teléfono copiado de
    // un correo se trae el nombre, los dos puntos y todo lo demás.
    // El setTimeout 0 espera a que el texto pegado ya esté en el campo; en los
    // navegadores actuales el evento `input` de arriba también lo cubre, así
    // que esto es un respaldo.
    document.addEventListener('paste', function (ev) {
        var el = ev.target, regla = reglaDe(el);
        if (regla) { setTimeout(function () { limpiar(el, regla); }, 0); }
    }, true);
})();
