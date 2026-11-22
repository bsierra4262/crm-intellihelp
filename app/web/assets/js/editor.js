/* ============================================================
   Editor visual de plantillas de correo.

   El estado es una lista de bloques y unos ajustes generales. El editor NUNCA
   arma el HTML del correo: lo pide al servidor, que es quien tiene el único
   renderizador. Así la vista previa es exactamente lo que se va a enviar, y no
   una aproximación que después no coincide.
   ============================================================ */
// Dónde corre: sólo en views/plantilla-form.php (pantalla de plantillas,
// módulo Campañas), que lo carga con `defer` y deja antes en
// `window.__editor` la configuración:
//   · datos     {bloques:[…], ajustes:{…}} con lo guardado o el preajuste;
//   · tipos     bloques_tipos() de bloques.php: nombre y ayuda de cada tipo;
//   · imagenes  [{url, nombre}] de la biblioteca de imágenes;
//   · urlVista  ruta `plantilla-vista`, que devuelve {ok, html, asunto, chequeo};
//   · urlImagenes, csrf y chequeo (el chequeo inicial).
// Si no está `window.__editor`, el archivo no hace nada (por eso se puede
// incluir en cualquier página).
//
// Elementos del DOM que espera: #lienzo, #lienzoVacio, #campoBloques
// (campo oculto que viaja con el formulario: el estado entero en JSON),
// #propTitulo, #propCuerpo, #previewFrame, #campoAsunto, #vistaAsunto,
// #preview, #chequeoCuerpo y #chequeoResumen.
//
// Seguridad: todo lo que escribe el usuario pasa por escapar() antes de ir a
// innerHTML. La vista previa se carga en un iframe por srcdoc; ese iframe no
// tiene sandbox, así que el HTML que arma el servidor corre con el origen
// del CRM (bloques.php escapa los textos y descarta las URL peligrosas).
(function () {
    'use strict';

    var cfg = window.__editor;
    if (!cfg) { return; }

    // Estado único del editor. Todo cambio pasa por acá y después se
    // repinta (lienzo, panel) y se pide la vista previa (refrescar).
    var estado = cfg.datos && cfg.datos.bloques ? cfg.datos : { bloques: [], ajustes: {} };
    if (!Array.isArray(estado.bloques)) { estado.bloques = []; }
    if (!estado.ajustes) { estado.ajustes = {}; }

    var seleccion = null;                     // índice del bloque abierto
    var lienzo    = document.getElementById('lienzo');
    var vacio     = document.getElementById('lienzoVacio');
    var campo     = document.getElementById('campoBloques');
    var propT     = document.getElementById('propTitulo');
    var propC     = document.getElementById('propCuerpo');
    var marco     = document.getElementById('previewFrame');
    // La vista previa es HTML armado por el servidor con lo que escribió el
    // usuario: se muestra en un marco aislado, sin scripts ni acceso al CRM
    // (los bloques ya escapan el texto; esto es la segunda capa). Los enlaces
    // pueden abrirse en otra pestaña.
    if (marco) { marco.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox'); }

    // --------------------------------------------------------
    //  Qué campos tiene cada bloque. Es el espejo del renderizador de PHP:
    //  si allá se agrega una propiedad, acá se agrega el campo.
    //
    //  Cada campo es [clave, tipo de control, etiqueta, opciones]. Tipos:
    //  texto, area, numero, color, alineacion, opcion e imagen (ver
    //  campoHTML). Opciones: ph (placeholder), ayuda, filas, min, max y
    //  opciones (para 'opcion'). Un tipo de bloque que no esté acá se puede
    //  mover y borrar, pero no tiene nada para editar.
    // --------------------------------------------------------
    var ESQUEMA = {
        encabezado: [
            ['logo', 'imagen', 'Logo'],
            ['alto', 'numero', 'Alto del logo (px)', { min: 16, max: 120 }],
            ['derecha', 'texto', 'Texto a la derecha', { ph: '{{empresa_web}}' }]
        ],
        hero: [
            ['volanta', 'texto', 'Volanta (arriba, chiquita)'],
            ['titulo', 'area', 'Título'],
            ['bajada', 'area', 'Bajada'],
            ['fondo', 'color', 'Color de fondo']
        ],
        titulo: [
            ['texto', 'area', 'Texto'],
            ['nivel', 'opcion', 'Tamaño', { opciones: [[1, 'Grande'], [2, 'Mediano'], [3, 'Chico']] }],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        texto: [
            ['texto', 'area', 'Texto', { filas: 6 }],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        lista: [
            ['texto', 'area', 'Un punto por línea', { filas: 5 }]
        ],
        imagen: [
            ['src', 'imagen', 'Imagen'],
            ['alt', 'texto', 'Texto alternativo', { ph: 'Qué se ve en la imagen' }],
            ['ancho', 'numero', 'Ancho (px)', { min: 40, max: 600 }],
            ['alineacion', 'alineacion', 'Alineación'],
            ['enlace', 'texto', 'Enlace al hacer clic', { ph: 'https://…' }]
        ],
        boton: [
            ['texto', 'texto', 'Texto del botón'],
            ['enlace', 'texto', 'Enlace', { ph: '{{agenda}}' }],
            ['fondo', 'color', 'Color del botón'],
            ['color', 'color', 'Color del texto'],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        agenda: [
            ['texto', 'texto', 'Texto del botón', { ph: 'Agendar una reunión' }],
            ['fondo', 'color', 'Color del botón'],
            ['color', 'color', 'Color del texto'],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        whatsapp: [
            ['telefono', 'texto', 'Número de WhatsApp',
             { ph: '5491150274827', ayuda: 'Sólo números, con código de país y sin el «+». Si lo dejás vacío se usa el de Ajustes.' }],
            ['mensaje', 'area', 'Mensaje que ya viene escrito',
             { filas: 3, ph: 'Hola, me interesa conocer más sobre sus soluciones.',
               ayuda: 'Es lo que aparece tipeado cuando se abre WhatsApp. Sólo escribí el texto: la dirección se arma sola.' }],
            ['texto', 'texto', 'Texto del botón', { ph: 'Escribinos por WhatsApp' }],
            ['fondo', 'color', 'Color del botón'],
            ['color', 'color', 'Color del texto'],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        destacado: [
            ['titulo', 'texto', 'Título'],
            ['texto', 'area', 'Texto'],
            ['fondo', 'color', 'Color de fondo'],
            ['alineacion', 'alineacion', 'Alineación']
        ],
        contacto: [
            ['web', 'texto', 'Web', { ph: 'se toma de Ajustes si lo dejás vacío' }],
            ['whatsapp', 'texto', 'WhatsApp'],
            ['telefono', 'texto', 'Teléfono'],
            ['email', 'texto', 'Email']
        ],
        firma: [
            ['cierre', 'texto', 'Cierre', { ph: 'Saludos cordiales,' }],
            ['firma', 'texto', 'Quién firma', { ph: '{{remitente}}' }],
            ['cargo', 'texto', 'Cargo o empresa']
        ],
        separador: [],
        espacio: [['alto', 'numero', 'Alto (px)', { min: 4, max: 90 }]]
    };

    // Ajustes generales del correo: se editan cuando no hay bloque elegido.
    var AJUSTES = [
        ['marca', 'color', 'Color principal'],
        ['acento', 'color', 'Color de acento'],
        ['fondo', 'color', 'Fondo de la página'],
        ['tarjeta', 'color', 'Fondo del correo'],
        ['titulo', 'color', 'Color de los títulos'],
        ['texto', 'color', 'Color del texto'],
        ['preencabezado', 'area', 'Texto de anticipo',
         { filas: 2, ayuda: 'Es la línea gris que se ve en la bandeja, al lado del asunto. Si no la ponés, el cliente muestra el principio del correo.' }]
    ];

    /**
     * Una línea que identifica al bloque en el lienzo: su primer texto
     * significativo, recortado a 70 caracteres. Para WhatsApp y Agenda dice
     * adónde lleva el botón.
     */
    function resumen(b) {
        if (b.tipo === 'whatsapp') { return (b.texto || 'WhatsApp') + ' → ' + (b.telefono || 'el de Ajustes'); }
        if (b.tipo === 'agenda')   { return (b.texto || 'Agendar una reunión') + ' → tu agenda pública'; }
        var t = (b.texto || b.titulo || b.volanta || b.alt || b.derecha || b.cierre || '').toString();
        t = t.replace(/\s+/g, ' ').trim();
        return t.length > 70 ? t.slice(0, 69) + '…' : t;
    }

    // --------------------------------------------------------
    //  Lienzo
    // --------------------------------------------------------
    /**
     * Redibuja la lista de bloques de la izquierda desde cero. Cada bloque
     * guarda su posición en data-i; los botones de cada uno (subir, bajar,
     * duplicar, quitar) se atienden por delegación en el click global.
     */
    function pintarLienzo() {
        lienzo.innerHTML = '';
        vacio.hidden = estado.bloques.length > 0;
        estado.bloques.forEach(function (b, i) {
            var meta = cfg.tipos[b.tipo] || { nombre: b.tipo };
            var el = document.createElement('div');
            el.className = 'ed-bloque' + (i === seleccion ? ' sel' : '');
            el.setAttribute('draggable', 'true');
            el.dataset.i = i;
            el.innerHTML =
                '<span class="ed-arrastre" title="Arrastrar">⠿</span>' +
                '<span class="ed-info"><strong>' + escapar(meta.nombre) + '</strong>' +
                '<small>' + escapar(resumen(b)) + '</small></span>' +
                '<span class="ed-ops">' +
                  '<button type="button" class="act" data-op="subir" title="Subir">▲</button>' +
                  '<button type="button" class="act" data-op="bajar" title="Bajar">▼</button>' +
                  '<button type="button" class="act" data-op="copiar" title="Duplicar">⧉</button>' +
                  '<button type="button" class="act danger" data-op="borrar" title="Quitar">✕</button>' +
                '</span>';
            lienzo.appendChild(el);
        });
    }

    /** Escapa texto para meterlo en HTML, también dentro de atributos entre comillas dobles. */
    function escapar(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // --------------------------------------------------------
    //  Panel de propiedades
    // --------------------------------------------------------
    /**
     * Arma el HTML de un campo del panel de propiedades.
     *
     * Todos los controles llevan data-campo="<clave>"; el listener de
     * `input` del panel usa eso para escribir en el bloque o en los ajustes.
     * El de color son dos controles con la misma clave (selector + texto) que
     * se copian entre sí. La alineación son botones (se atiende en el click).
     * clave y las opciones salen de ESQUEMA/AJUSTES, no del usuario: por eso
     * van sin escapar; el valor y los textos sí se escapan.
     *
     * @param {string} clave    propiedad del bloque
     * @param {string} tipo     texto | area | numero | color | alineacion | opcion | imagen
     * @param {string} etiqueta rótulo visible
     * @param {Object} ops      ph, ayuda, filas, min, max, opciones
     * @param {*}      valor    valor actual
     * @returns {string} HTML del <label class="fld">
     */
    function campoHTML(clave, tipo, etiqueta, ops, valor) {
        ops = ops || {};
        var id = 'f_' + clave;
        var h = '<label class="fld"><span>' + escapar(etiqueta) + '</span>';
        var val = escapar(valor == null ? '' : valor);
        if (tipo === 'area') {
            h += '<textarea id="' + id + '" data-campo="' + clave + '" rows="' + (ops.filas || 3) + '" ' +
                 'placeholder="' + escapar(ops.ph || '') + '">' + val + '</textarea>';
        } else if (tipo === 'numero') {
            h += '<input id="' + id + '" data-campo="' + clave + '" type="number" value="' + val + '" ' +
                 'min="' + (ops.min || 0) + '" max="' + (ops.max || 999) + '">';
        } else if (tipo === 'color') {
            h += '<span class="ed-color"><input id="' + id + '" data-campo="' + clave + '" type="color" value="' +
                 (val || '#663399') + '"><input data-campo="' + clave + '" data-espejo="1" value="' + val + '" maxlength="7"></span>';
        } else if (tipo === 'alineacion') {
            h += '<span class="seg ed-seg">';
            [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']].forEach(function (o) {
                h += '<button type="button" class="seg-item' + (valor === o[0] ? ' active' : '') +
                     '" data-campo="' + clave + '" data-valor="' + o[0] + '">' + o[1] + '</button>';
            });
            h += '</span>';
        } else if (tipo === 'opcion') {
            h += '<select id="' + id + '" data-campo="' + clave + '">';
            (ops.opciones || []).forEach(function (o) {
                h += '<option value="' + o[0] + '"' + (String(valor) === String(o[0]) ? ' selected' : '') + '>' + o[1] + '</option>';
            });
            h += '</select>';
        } else if (tipo === 'imagen') {
            h += '<span class="ed-img"><input id="' + id + '" data-campo="' + clave + '" value="' + val + '" ' +
                 'placeholder="URL de la imagen"><button type="button" class="btn btn-ghost btn-sm" data-elegir="' +
                 clave + '">Elegir</button></span>';
        } else {
            h += '<input id="' + id + '" data-campo="' + clave + '" value="' + val + '" placeholder="' +
                 escapar(ops.ph || '') + '">';
        }
        if (ops.ayuda) { h += '<i class="hint">' + escapar(ops.ayuda) + '</i>'; }
        return h + '</label>';
    }

    /**
     * Redibuja el panel de la derecha: los campos del bloque elegido o, si no
     * hay ninguno, los ajustes generales. Anota en data-destino a quién van
     * los cambios ('bloque' o 'ajustes'); destino() lo lee.
     */
    function pintarProps() {
        if (seleccion === null || !estado.bloques[seleccion]) {
            propT.textContent = 'Ajustes del correo';
            var h = '<p class="muted small">Los colores valen para todo el correo. Elegí un bloque de la lista para editarlo.</p>';
            AJUSTES.forEach(function (f) {
                h += campoHTML(f[0], f[1], f[2], f[3], estado.ajustes[f[0]]);
            });
            propC.innerHTML = h;
            propC.dataset.destino = 'ajustes';
            return;
        }
        var b = estado.bloques[seleccion];
        var meta = cfg.tipos[b.tipo] || { nombre: b.tipo, ayuda: '' };
        propT.textContent = meta.nombre;
        var campos = ESQUEMA[b.tipo] || [];
        var html = '<p class="muted small">' + escapar(meta.ayuda || '') + '</p>';
        if (!campos.length) { html += '<p class="muted small">Este bloque no tiene nada para configurar.</p>'; }
        campos.forEach(function (f) { html += campoHTML(f[0], f[1], f[2], f[3], b[f[0]]); });
        propC.innerHTML = html;
        propC.dataset.destino = 'bloque';
    }

    /** El objeto que se está editando: estado.ajustes o el bloque elegido. */
    function destino() {
        return propC.dataset.destino === 'ajustes' ? estado.ajustes : estado.bloques[seleccion];
    }

    // --------------------------------------------------------
    //  Vista previa: la arma el servidor, no el navegador
    // --------------------------------------------------------
    var pendiente = null;
    // Número de pedido de vista previa: sólo se pinta la respuesta del
    // último. Si una vieja tarda más que la nueva, se descarta al llegar.
    var pedidoN = 0;

    /**
     * Muestra (o saca, con texto vacío) un aviso arriba de la vista previa
     * cuando no se pudo actualizar: sin esto la vista quedaba congelada sin
     * que nadie se diera cuenta.
     */
    function avisoVista(texto) {
        var cont = marco ? marco.parentNode : null;
        if (!cont) { return; }
        var av = document.getElementById('avisoVista');
        if (!texto) { if (av) { av.hidden = true; } return; }
        if (!av) {
            av = document.createElement('div');
            av.id = 'avisoVista';
            av.className = 'flash flash-warn';
            av.setAttribute('role', 'status');
            cont.parentNode.insertBefore(av, cont);
        }
        av.textContent = texto;
        av.hidden = false;
    }

    /**
     * Copia el estado al campo oculto (así el formulario siempre manda lo
     * último) y, 400 ms después del último cambio, pide al servidor el HTML
     * de la vista previa, el asunto con las variables reemplazadas y el
     * chequeo.
     *
     * Sólo se pinta la respuesta del pedido más reciente. Si el servidor no
     * contesta JSON (la sesión venció y devolvió el login, o hubo un error),
     * se avisa arriba de la vista previa en vez de dejarla congelada.
     */
    function refrescar() {
        campo.value = JSON.stringify(estado);
        clearTimeout(pendiente);
        pendiente = setTimeout(function () {
            var n = ++pedidoN;
            var cuerpo = new URLSearchParams();
            cuerpo.set('_csrf', cfg.csrf);
            cuerpo.set('bloques', campo.value);
            cuerpo.set('asunto', (document.getElementById('campoAsunto') || {}).value || '');
            fetch(cfg.urlVista, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: cuerpo.toString()
            }).then(function (r) {
                var tipo = r.headers.get('Content-Type') || '';
                if (r.redirected || tipo.indexOf('json') === -1) { throw new Error('sesion'); }
                return r.json();
            }).then(function (d) {
                if (n !== pedidoN) { return; }   // llegó tarde: ya hay uno más nuevo
                if (!d || !d.ok) {
                    avisoVista('No se pudo actualizar la vista previa' + (d && d.error ? ': ' + d.error : '.'));
                    return;
                }
                avisoVista('');
                marco.srcdoc = d.html;
                document.getElementById('vistaAsunto').textContent = d.asunto || '(sin asunto)';
                pintarChequeo(d.chequeo);
            }).catch(function (err) {
                if (n !== pedidoN) { return; }
                avisoVista(err && err.message === 'sesion'
                    ? 'La vista previa no se actualiza: la sesión venció. Guardá una copia de lo escrito y recargá la página.'
                    : 'La vista previa no se actualiza: no hay conexión con el servidor. Se reintenta con el próximo cambio.');
            });
        }, 400);
    }

    /**
     * Muestra el resultado de bloques_verificar(): errores, avisos (cada uno
     * [título, detalle]) y las métricas del correo (peso, palabras, imágenes,
     * enlaces). El límite de 102 KB es el de Gmail, que corta el correo más
     * pesado con «Mensaje recortado».
     */
    function pintarChequeo(ch) {
        if (!ch) { return; }
        var c = document.getElementById('chequeoCuerpo');
        var r = document.getElementById('chequeoResumen');
        var h = '';
        if (!ch.errores.length && !ch.avisos.length) {
            h = '<div class="flash flash-success" style="margin:0">Todo en orden: el correo respeta los límites de los clientes y no dispara señales de spam.</div>';
        }
        ch.errores.forEach(function (x) {
            h += '<div class="ed-chk error"><strong>' + escapar(x[0]) + '</strong><span>' + escapar(x[1]) + '</span></div>';
        });
        ch.avisos.forEach(function (x) {
            h += '<div class="ed-chk aviso"><strong>' + escapar(x[0]) + '</strong><span>' + escapar(x[1]) + '</span></div>';
        });
        var d = ch.datos || {};
        h += '<div class="ed-metricas">' +
             '<span><b>' + Math.round((d.bytes || 0) / 1024) + ' KB</b>peso<small>límite 102 KB</small></span>' +
             '<span><b>' + (d.palabras || 0) + '</b>palabras</span>' +
             '<span><b>' + (d.imagenes || 0) + '</b>imágenes</span>' +
             '<span><b>' + (d.enlaces || 0) + '</b>enlaces</span></div>';
        c.innerHTML = h;
        r.textContent = ch.errores.length
            ? ch.errores.length + ' a corregir'
            : (ch.avisos.length ? ch.avisos.length + ' para revisar' : 'sin problemas');
        r.className = 'small ' + (ch.errores.length ? 'danger' : (ch.avisos.length ? 'warn' : 'muted'));
    }

    // --------------------------------------------------------
    //  Eventos
    //
    //  Un solo listener de click en todo el documento, por delegación: los
    //  elementos se vuelven a crear en cada repintado, así que no se les
    //  puede colgar un listener propio. El orden de los `if` importa: los
    //  botones de un bloque están DENTRO del bloque y se atienden antes que
    //  el click que lo selecciona.
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-agregar]');
        if (add) {
            // Cada bloque nuevo arranca con un contenido de ejemplo, para que
            // se vea en la vista previa apenas se agrega.
            var t = add.dataset.agregar;
            var nuevo = { tipo: t };
            if (t === 'texto')  { nuevo.texto = 'Escribí acá tu mensaje.'; }
            if (t === 'titulo') { nuevo.texto = 'Un título'; nuevo.nivel = 2; }
            if (t === 'lista')  { nuevo.texto = 'Primer punto\nSegundo punto'; }
            if (t === 'boton')  { nuevo.texto = 'Ver más'; nuevo.enlace = ''; }
            if (t === 'agenda') { nuevo.texto = 'Agendar una reunión'; }
            if (t === 'whatsapp') {
                nuevo.texto = 'Escribinos por WhatsApp';
                nuevo.mensaje = 'Hola, me interesa conocer más sobre sus soluciones.';
                nuevo.fondo = '#25D366'; nuevo.color = '#ffffff';
            }
            if (t === 'firma')  { nuevo.cierre = 'Saludos cordiales,'; nuevo.firma = '{{remitente}}'; }
            if (t === 'espacio'){ nuevo.alto = 20; }
            estado.bloques.push(nuevo);
            seleccion = estado.bloques.length - 1;
            pintarLienzo(); pintarProps(); refrescar();
            return;
        }

        var op = e.target.closest('[data-op]');
        if (op) {
            var i = +op.closest('.ed-bloque').dataset.i;
            var acc = op.dataset.op;
            if (acc === 'subir' && i > 0) { intercambiar(i, i - 1); seleccion = i - 1; }
            if (acc === 'bajar' && i < estado.bloques.length - 1) { intercambiar(i, i + 1); seleccion = i + 1; }
            if (acc === 'copiar') { estado.bloques.splice(i + 1, 0, JSON.parse(JSON.stringify(estado.bloques[i]))); seleccion = i + 1; }
            if (acc === 'borrar') { estado.bloques.splice(i, 1); seleccion = null; }
            pintarLienzo(); pintarProps(); refrescar();
            return;
        }

        var bl = e.target.closest('.ed-bloque');
        if (bl) { seleccion = +bl.dataset.i; pintarLienzo(); pintarProps(); return; }

        var seg = e.target.closest('.ed-seg .seg-item');
        if (seg) {
            destino()[seg.dataset.campo] = seg.dataset.valor;
            seg.parentNode.querySelectorAll('.seg-item').forEach(function (x) { x.classList.remove('active'); });
            seg.classList.add('active');
            pintarLienzo(); refrescar();
            return;
        }

        var eleg = e.target.closest('[data-elegir]');
        if (eleg) { elegirImagen(eleg.dataset.elegir); return; }

        var vst = e.target.closest('[data-vista]');
        if (vst) {
            document.querySelectorAll('[data-vista]').forEach(function (x) { x.classList.remove('active'); });
            vst.classList.add('active');
            document.getElementById('preview').classList.toggle('movil', vst.dataset.vista === 'movil');
            return;
        }

        // Las chapitas de variables ({{nombre}}…) se copian al portapapeles.
        // Sin API de portapapeles (HTTP plano) no copia, pero igual parpadea.
        var vr = e.target.closest('.ed-var');
        if (vr) {
            navigator.clipboard && navigator.clipboard.writeText(vr.dataset.var);
            vr.classList.add('copiado');
            setTimeout(function () { vr.classList.remove('copiado'); }, 900);
        }
    });

    /** Intercambia dos bloques de lugar (subir y bajar). */
    function intercambiar(a, b) {
        var t = estado.bloques[a]; estado.bloques[a] = estado.bloques[b]; estado.bloques[b] = t;
    }

    // Cada tecla en el panel escribe directo en el estado. Se repinta el
    // lienzo (por el resumen) pero no el panel, para no perder el foco ni la
    // posición del cursor. Los números quedan guardados como texto.
    propC.addEventListener('input', function (e) {
        var c = e.target.dataset.campo;
        if (!c) { return; }
        var d = destino();
        d[c] = e.target.value;
        // El campo de color y su texto se copian entre sí.
        propC.querySelectorAll('[data-campo="' + c + '"]').forEach(function (x) {
            if (x !== e.target) { x.value = e.target.value; }
        });
        pintarLienzo();
        refrescar();
    });

    var asunto = document.getElementById('campoAsunto');
    if (asunto) { asunto.addEventListener('input', refrescar); }

    // --------------------------------------------------------
    //  Reordenar arrastrando
    //
    //  Se saca el bloque de su lugar y se inserta en la posición del bloque
    //  sobre el que se soltó. Arrastrando hacia abajo queda DESPUÉS de ese
    //  bloque (el índice ya se corrió uno al sacarlo); hacia arriba, antes.
    // --------------------------------------------------------
    var origen = null;
    lienzo.addEventListener('dragstart', function (e) {
        var b = e.target.closest('.ed-bloque');
        if (!b) { return; }
        origen = +b.dataset.i;
        b.classList.add('arrastrando');
        e.dataTransfer.effectAllowed = 'move';
    });
    lienzo.addEventListener('dragover', function (e) {
        e.preventDefault();
        var b = e.target.closest('.ed-bloque');
        lienzo.querySelectorAll('.ed-bloque').forEach(function (x) { x.classList.remove('encima'); });
        if (b) { b.classList.add('encima'); }
    });
    lienzo.addEventListener('drop', function (e) {
        e.preventDefault();
        var b = e.target.closest('.ed-bloque');
        if (!b || origen === null) { return; }
        var destinoI = +b.dataset.i;
        var mov = estado.bloques.splice(origen, 1)[0];
        estado.bloques.splice(destinoI, 0, mov);
        seleccion = destinoI;
        origen = null;
        pintarLienzo(); pintarProps(); refrescar();
    });
    lienzo.addEventListener('dragend', function () {
        origen = null;
        lienzo.querySelectorAll('.ed-bloque').forEach(function (x) {
            x.classList.remove('arrastrando', 'encima');
        });
    });

    // --------------------------------------------------------
    //  Selector de imágenes
    // --------------------------------------------------------
    /**
     * Abre la galería de imágenes subidas y, al elegir una, guarda su URL
     * absoluta en la propiedad `clave` del bloque (o de los ajustes). Sin
     * imágenes, ofrece ir a la pantalla de Imágenes en otra pestaña. El modal
     * se arma acá mismo y se destruye al cerrar.
     *
     * @param {string} clave propiedad a llenar ('src' o 'logo')
     */
    function elegirImagen(clave) {
        if (!cfg.imagenes.length) {
            window.crmConfirmar({
                titulo: 'Todavía no subiste imágenes',
                texto: 'Para usar una imagen primero hay que subirla. ¿Vamos a la pantalla de imágenes?',
                aceptar: 'Ir a Imágenes'
            }).then(function (ok) { if (ok) { window.open(cfg.urlImagenes, '_blank'); } });
            return;
        }
        var ov = document.createElement('div');
        ov.className = 'modal-overlay open';
        var h = '<div class="modal" style="max-width:min(720px,94vw)"><div class="modal-head">' +
                '<span class="modal-ic info">🖼</span><h2>Elegí una imagen</h2></div>' +
                '<div class="modal-body"><div class="ed-galeria">';
        cfg.imagenes.forEach(function (im) {
            h += '<button type="button" class="ed-gal" data-url="' + escapar(im.url) + '">' +
                 '<img src="' + escapar(im.url) + '" alt=""><span>' + escapar(im.nombre) + '</span></button>';
        });
        h += '</div></div><div class="modal-foot">' +
             '<a class="btn btn-ghost" href="' + escapar(cfg.urlImagenes) + '" target="_blank">Subir otra</a>' +
             '<button type="button" class="btn btn-ghost" data-cerrar>Cancelar</button></div></div>';
        ov.innerHTML = h;
        document.body.appendChild(ov);
        ov.addEventListener('click', function (e) {
            var g = e.target.closest('.ed-gal');
            if (g) {
                destino()[clave] = g.dataset.url;
                pintarProps(); pintarLienzo(); refrescar();
                ov.remove();
                return;
            }
            if (e.target.closest('[data-cerrar]') || e.target === ov) { ov.remove(); }
        });
    }

    // --------------------------------------------------------
    //  Arranque: se pinta todo con el chequeo que trajo la página y se pide
    //  una vista previa fresca.
    // --------------------------------------------------------
    pintarLienzo();
    pintarProps();
    pintarChequeo(cfg.chequeo);
    refrescar();
})();
