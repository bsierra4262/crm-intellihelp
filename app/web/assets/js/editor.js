/* ============================================================
   Editor visual de plantillas de correo.

   El estado es una lista de bloques y unos ajustes generales. El editor NUNCA
   arma el HTML del correo: lo pide al servidor, que es quien tiene el único
   renderizador. Así la vista previa es exactamente lo que se va a enviar, y no
   una aproximación que después no coincide.
   ============================================================ */
(function () {
    'use strict';

    var cfg = window.__editor;
    if (!cfg) { return; }

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

    // --------------------------------------------------------
    //  Qué campos tiene cada bloque. Es el espejo del renderizador de PHP:
    //  si allá se agrega una propiedad, acá se agrega el campo.
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

    function escapar(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // --------------------------------------------------------
    //  Panel de propiedades
    // --------------------------------------------------------
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

    function destino() {
        return propC.dataset.destino === 'ajustes' ? estado.ajustes : estado.bloques[seleccion];
    }

    // --------------------------------------------------------
    //  Vista previa: la arma el servidor, no el navegador
    // --------------------------------------------------------
    var pendiente = null;
    function refrescar() {
        campo.value = JSON.stringify(estado);
        clearTimeout(pendiente);
        pendiente = setTimeout(function () {
            var cuerpo = new URLSearchParams();
            cuerpo.set('_csrf', cfg.csrf);
            cuerpo.set('bloques', campo.value);
            cuerpo.set('asunto', (document.getElementById('campoAsunto') || {}).value || '');
            fetch(cfg.urlVista, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: cuerpo.toString()
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) { return; }
                marco.srcdoc = d.html;
                document.getElementById('vistaAsunto').textContent = d.asunto || '(sin asunto)';
                pintarChequeo(d.chequeo);
            }).catch(function () {});
        }, 400);
    }

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
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-agregar]');
        if (add) {
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

        var vr = e.target.closest('.ed-var');
        if (vr) {
            navigator.clipboard && navigator.clipboard.writeText(vr.dataset.var);
            vr.classList.add('copiado');
            setTimeout(function () { vr.classList.remove('copiado'); }, 900);
        }
    });

    function intercambiar(a, b) {
        var t = estado.bloques[a]; estado.bloques[a] = estado.bloques[b]; estado.bloques[b] = t;
    }

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
    //  Arranque
    // --------------------------------------------------------
    pintarLienzo();
    pintarProps();
    pintarChequeo(cfg.chequeo);
    refrescar();
})();
