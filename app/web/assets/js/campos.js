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
 */
(function () {
    'use strict';

    // Lo que puede tener un teléfono escrito a mano: +54 9 11 5162-9038,
    // (011) 4567-8901, 11.2345.6789. La misma regla que valida el servidor.
    var TELEFONO = /[^0-9+()./\s-]/g;
    var SOLO_DIGITOS = /[^0-9]/g;
    var CUIT = /[^0-9-]/g;          // 30-12345678-1

    /** ¿Qué se le permite a este campo? */
    function reglaDe(el) {
        if (!el || !el.matches) { return null; }
        if (el.matches('input[data-solo="digitos"]')) { return SOLO_DIGITOS; }
        if (el.matches('input[data-solo="cuit"]'))    { return CUIT; }
        if (el.matches('input[type="tel"]'))          { return TELEFONO; }
        return null;
    }

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

    document.addEventListener('input', function (ev) {
        var regla = reglaDe(ev.target);
        if (regla) { limpiar(ev.target, regla); }
    }, true);

    // ------------------------------------------------------------------
    //  El sector depende del tipo de empresa.
    //
    //  A un proveedor no le sirven las mismas categorías que a un cliente.
    //  Antes el sector se escribía a mano y la misma industria terminaba
    //  cargada de cinco formas distintas —«Logistica», «logística»,
    //  «Transporte y logística»—, que es exactamente lo que vuelve inservible
    //  un filtro y una segmentación.
    // ------------------------------------------------------------------
    function llenarSectores() {
        var tipo = document.querySelector('[data-tipo-empresa]');
        var sec  = document.querySelector('[data-sector-empresa]');
        if (!tipo || !sec) { return; }

        var mapa = {};
        try { mapa = JSON.parse(sec.getAttribute('data-mapa') || '{}'); } catch (e) { return; }
        // Lo que ya estaba guardado tiene que seguir elegido al abrir la ficha.
        var elegido = sec.value || sec.getAttribute('data-elegido') || '';
        var opciones = mapa[tipo.value] || [];

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
    document.addEventListener('paste', function (ev) {
        var el = ev.target, regla = reglaDe(el);
        if (regla) { setTimeout(function () { limpiar(el, regla); }, 0); }
    }, true);
})();
