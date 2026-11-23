/* ============================================================
   CRM INTELLIHELP — comportamiento común de la interfaz.
   Sin dependencias ni build: se carga tal cual en todas las pantallas.
   ============================================================ */
// ------------------------------------------------------------
//  Quién lo carga: layout.php, sólo con sesión iniciada (todas las pantallas
//  internas; no el login ni las páginas públicas). Va con `defer`, después de
//  campos.js y selects.js y antes de idle.js, vivo.js y secciones.js.
//
//  Todo se engancha por delegación sobre `document` y no sobre cada elemento:
//  así sigue andando sin volver a correr nada cuando secciones.js reemplaza
//  una zona de la pantalla con HTML nuevo.
//
//  Atributos del HTML que escucha:
//    · data-confirmar (+ data-confirmar-titulo, data-confirmar-ok,
//      data-peligro): pide confirmación en una ventana propia antes de seguir
//      el enlace o enviar el formulario. Lo usan casi todas las vistas.
//    · data-toggle-nav / data-close-nav: cajón de la barra lateral en el
//      celular (botón de la topbar y velo, en layout.php).
//    · data-limpiar: el botón «Limpiar» de los filtros de los listados.
//    · data-auto: un select de filtro que envía su formulario al cambiar.
//    · data-cargando (+ data-cargando-detalle): botón de envío de un trabajo
//      largo (importar, campañas, actividades); tapa la pantalla con un aviso.
//    · iframe[data-marco]: los correos de conversacion.php; se ajusta su alto
//      con el que manda cada marco por postMessage.
//    · data-barra-seleccion, data-sel-n, data-marcar-todo e
//      input[name="ids[]"]: selección múltiple de contactos y empresas.
//    · .filefield input[type=file]: muestra el nombre del archivo elegido.
//
//  Escucha el evento `vivo:actualizado` que dispara secciones.js al
//  reemplazar una zona.
//  Expone window.crmConfirmar(opciones), que usa editor.js.
// ------------------------------------------------------------
(function () {
    'use strict';

    // --------------------------------------------------------
    //  Confirmación con ventana propia (nunca el confirm() del navegador)
    //  Uso:  <button data-confirmar="¿Eliminar el contacto?" ...>
    // --------------------------------------------------------

    /** La única ventana de confirmación de la página; se crea la primera vez que hace falta. */
    var overlay = null;

    /**
     * Crea (una sola vez) el marcado de la ventana de confirmación y lo cuelga
     * del body, oculto. Las siguientes llamadas devuelven el mismo elemento.
     * El innerHTML es fijo: no lleva ningún dato del servidor ni del usuario
     * (el título y el texto se ponen después con textContent).
     *
     * @returns {HTMLElement} el velo (.modal-overlay) con la ventana adentro.
     */
    function construirModal() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML =
            '<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confTit" aria-describedby="confTxt">' +
              '<div class="modal-head">' +
                '<span class="modal-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>' +
                '<h2 id="confTit"></h2>' +
              '</div>' +
              '<div class="modal-body" id="confTxt"></div>' +
              '<div class="modal-foot">' +
                '<button type="button" class="btn btn-ghost" data-accion="no">Cancelar</button>' +
                '<button type="button" class="btn btn-primary" data-accion="si">Confirmar</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    /**
     * Muestra la ventana de confirmación y espera la respuesta.
     *
     * Escape, Cancelar o un clic en el velo responden «no»; Confirmar responde
     * «sí». El foco arranca en «Confirmar» y Enter o Espacio activan el botón
     * que tiene el foco (el comportamiento normal de un botón): con el foco en
     * «Cancelar», Enter cancela. Tab y Mayús+Tab giran entre los dos botones
     * sin salir de la ventana, y al cerrarse el foco vuelve a donde estaba
     * (normalmente, el botón que pidió la confirmación).
     *
     * @param {Object}  opciones
     * @param {string}  [opciones.titulo]  título; por defecto «¿Confirmás?».
     * @param {string}  [opciones.texto]   explicación (se pone como texto, no HTML).
     * @param {string}  [opciones.aceptar] rótulo del botón de aceptar.
     * @param {boolean} [opciones.peligro] botón rojo e icono de advertencia.
     * @returns {Promise<boolean>} true si se aceptó.
     */
    function confirmar(opciones) {
        return new Promise(function (resolve) {
            var ov = construirModal();
            // Dónde estaba el foco antes de abrir, para devolverlo al cerrar.
            var previo = document.activeElement;
            ov.querySelector('h2').textContent = opciones.titulo || '¿Confirmás?';
            ov.querySelector('.modal-body').textContent = opciones.texto || '';
            var btnSi = ov.querySelector('[data-accion="si"]');
            var btnNo = ov.querySelector('[data-accion="no"]');
            btnSi.textContent = opciones.aceptar || 'Confirmar';
            btnSi.className = 'btn ' + (opciones.peligro ? 'btn-danger' : 'btn-primary');
            ov.querySelector('.modal-ic').className = 'modal-ic' + (opciones.peligro ? '' : ' info');
            ov.classList.add('open');
            btnSi.focus();

            // Los manejadores se quitan al cerrar: la ventana se reutiliza y,
            // si quedaran colgados, cada confirmación sumaría uno más.
            function cerrar(valor) {
                ov.classList.remove('open');
                document.removeEventListener('keydown', porTecla, true);
                ov.onclick = null;
                if (previo && typeof previo.focus === 'function' && document.contains(previo)) {
                    try { previo.focus(); } catch (e) {}
                }
                resolve(valor);
            }
            // En captura y frenando la propagación: mientras la ventana está
            // abierta, Escape y Tab son sólo de ella (no cierran el cajón ni
            // una lista de selects.js que hubiera quedado detrás).
            function porTecla(e) {
                if (e.key === 'Escape') {
                    e.preventDefault(); e.stopPropagation();
                    cerrar(false);
                    return;
                }
                if (e.key === 'Tab') {
                    // Encierra el foco entre los dos botones.
                    e.preventDefault(); e.stopPropagation();
                    (document.activeElement === btnSi ? btnNo : btnSi).focus();
                    return;
                }
                // Con el foco fuera de los botones (un clic en el texto, por
                // ejemplo), Enter no confirma nada: se devuelve el foco a la
                // ventana y la persona elige.
                if (e.key === 'Enter' && document.activeElement !== btnSi && document.activeElement !== btnNo) {
                    e.preventDefault();
                    btnSi.focus();
                }
            }
            ov.onclick = function (e) {
                var a = e.target.closest('[data-accion]');
                if (a) { cerrar(a.getAttribute('data-accion') === 'si'); }
                else if (e.target === ov) { cerrar(false); }
            };
            document.addEventListener('keydown', porTecla, true);
        });
    }

    /**
     * Envía el formulario de un botón ya confirmado como si se lo hubiera
     * apretado: con su name/value y pasando por el evento `submit` (validación
     * del navegador, aviso de data-cargando, secciones.js).
     *
     * requestSubmit(boton) hace justamente eso. En navegadores que no lo
     * tienen se agrega al formulario un campo oculto con el name/value del
     * botón (va al final, así que PHP se queda con ese si había otro campo
     * con el mismo nombre, como el `accion=guardar` de Ajustes) y se envía con
     * submit(), que no dispara `submit` ni valida.
     *
     * @param {HTMLFormElement} form
     * @param {HTMLElement} el el botón confirmado.
     */
    function enviarConBoton(form, el) {
        var esEnvio = (el.tagName === 'BUTTON' || el.tagName === 'INPUT') && el.type === 'submit';
        if (typeof form.requestSubmit === 'function') {
            // requestSubmit sólo acepta como «remitente» un botón de envío de
            // ese mismo formulario; si no lo es, se envía sin remitente.
            if (esEnvio) { form.requestSubmit(el); } else { form.requestSubmit(); }
            return;
        }
        var nombre = el.getAttribute('name');
        if (esEnvio && nombre) {
            var oculto = form.querySelector('input[type="hidden"][data-remitente]');
            if (!oculto) {
                oculto = document.createElement('input');
                oculto.type = 'hidden';
                oculto.setAttribute('data-remitente', '1');
                form.appendChild(oculto);
            }
            oculto.name  = nombre;
            oculto.value = el.value;
        }
        form.submit();
    }

    // Intercepta cualquier elemento con data-confirmar y frena la acción hasta
    // que el usuario acepte. Funciona con enlaces y con botones de formulario.
    // Se escucha en fase de captura para frenar el clic antes que cualquier
    // otro manejador.
    // Un botón de formulario se envía con enviarConBoton(): llega el
    // name/value del botón (los formularios que distinguen la acción por el
    // botón, como Ajustes, Feriados o «Aprobar las marcadas», dependen de eso)
    // y corre el evento `submit`. requestSubmit no vuelve a disparar `click`,
    // así que no se pregunta dos veces; igual, mientras se envía, el elemento
    // queda marcado con data-confirmado="1" y este manejador lo deja pasar.
    // Si el envío no salió (el navegador lo frenó por un campo inválido), la
    // marca se quita: el próximo clic vuelve a preguntar.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirmar]');
        if (!el || el.dataset.confirmado === '1') { return; }
        e.preventDefault();
        e.stopPropagation();
        confirmar({
            titulo:  el.getAttribute('data-confirmar-titulo') || '¿Confirmás la acción?',
            texto:   el.getAttribute('data-confirmar'),
            aceptar: el.getAttribute('data-confirmar-ok') || 'Confirmar',
            peligro: el.hasAttribute('data-peligro')
        }).then(function (ok) {
            if (!ok) { return; }
            el.dataset.confirmado = '1';
            if (el.tagName === 'A') { window.location.href = el.href; return; }
            try {
                if (el.form) { enviarConBoton(el.form, el); }
                else         { el.click(); }
            } finally {
                // El envío ya arrancó (o lo frenó la validación) dentro de
                // la llamada: la marca no hace falta más.
                delete el.dataset.confirmado;
            }
        });
    }, true);

    // --------------------------------------------------------
    //  Cajón de la barra lateral en pantallas chicas
    //
    //  Se abre con el botón de la topbar y se cierra con el velo, con Escape o
    //  al tocar cualquier cosa que no sea la barra. Si quedara abierto tapando
    //  la pantalla después de navegar, el primer gesto de cada pantalla sería
    //  cerrarlo a mano.
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-toggle-nav]')) {
            document.body.classList.toggle('nav-open');
            return;
        }
        if (!document.body.classList.contains('nav-open')) { return; }
        if (e.target.closest('[data-close-nav]') || !e.target.closest('.sidebar')) {
            document.body.classList.remove('nav-open');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { document.body.classList.remove('nav-open'); }
    });

    // El aviso y el cierre por inactividad son de idle.js (había acá un
    // bloque viejo que hacía una versión reducida y se quitó).

    // --------------------------------------------------------
    //  Filtros: el botón «Limpiar» vacía sin recargar dos veces
    //
    //  Vacía los campos visibles del formulario y lo envía una sola vez. Los
    //  ocultos se respetan: llevan la ruta, el orden o el tamaño de página.
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-limpiar]');
        if (!b) { return; }
        e.preventDefault();
        var form = b.closest('form');
        if (!form) { return; }
        form.querySelectorAll('input, select').forEach(function (c) {
            if (c.type === 'hidden') { return; }
            if (c.type === 'checkbox' || c.type === 'radio') { c.checked = false; }
            else { c.value = ''; }
        });
        form.submit();
    });

    // --------------------------------------------------------
    //  Campo de archivo: mostrar el nombre elegido
    // --------------------------------------------------------
    document.addEventListener('change', function (e) {
        var input = e.target.closest('.filefield input[type=file]');
        if (!input) { return; }
        var etiqueta = input.closest('.filefield').querySelector('.nombre');
        if (!etiqueta) { return; }
        etiqueta.textContent = input.files && input.files.length
            ? input.files[0].name
            : 'Ningún archivo seleccionado';
    });

    // --------------------------------------------------------
    //  Auto-envío de filtros al cambiar un select
    // --------------------------------------------------------
    document.addEventListener('change', function (e) {
        var s = e.target.closest('[data-auto]');
        if (s && s.form) { s.form.submit(); }
    });

    // --------------------------------------------------------
    //  Los correos de una conversación se muestran adentro de un marco
    //  aislado, para que su HTML no toque el de la pantalla. El marco no sabe
    //  cuánto mide su contenido: si no se le dice, queda con una altura fija y
    //  el mensaje aparece cortado con una barra de scroll adentro, que es
    //  exactamente lo que uno no quiere al leer un correo.
    //
    //  El marco va con sandbox SIN allow-same-origin (conversacion.php): desde
    //  acá no se puede leer su documento. Es el propio marco el que mide su
    //  alto y lo manda con postMessage; acá sólo se acepta el mensaje si viene
    //  de la ventana de uno de esos marcos (e.source), nunca por el origen,
    //  que en un marco aislado es «null».
    // --------------------------------------------------------
    window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || typeof d !== 'object' || typeof d.alto !== 'number' || !isFinite(d.alto)) { return; }
        var marcos = document.querySelectorAll('iframe[data-marco]');
        for (var i = 0; i < marcos.length; i++) {
            if (marcos[i].contentWindow === e.source) {
                // +2 px: sin ese margen algunos navegadores dejan una barra de
                // scroll de un píxel que se ve como un borde raro. Tope de
                // 20000 px para que un correo no pueda estirar la pantalla sin fin.
                marcos[i].style.height = Math.min(20000, Math.max(40, Math.ceil(d.alto))) + 2 + 'px';
                return;
            }
        }
    });

    // --------------------------------------------------------
    //  Lo que se recorta en la tabla, se puede leer igual
    //
    //  Las celdas cortan con puntos suspensivos para que la tabla entre en la
    //  pantalla sin barra horizontal. Pero un correo cortado a la mitad no
    //  sirve de nada si no hay forma de verlo entero, así que el valor completo
    //  queda en el `title` —y SÓLO en las celdas que de verdad se cortaron: un
    //  tooltip en cada celda es ruido que tapa la que importa.
    // --------------------------------------------------------
    /**
     * Pone o quita el `title` de cada celda de table.grid según se corte o no.
     * Mide la celda (o su texto principal dentro de .cell-main) comparando
     * scrollWidth con clientWidth; el pixel de margen evita falsos positivos
     * por redondeo.
     *
     * @param {ParentNode} [raiz] dónde buscar; por defecto toda la página.
     */
    function marcarRecortadas(raiz) {
        (raiz || document).querySelectorAll('table.grid td').forEach(function (td) {
            // El ancho de la tarjeta en el celular es otro: ahí no se corta nada.
            if (window.innerWidth <= 768) { td.removeAttribute('title'); return; }
            var el = td.querySelector('.cell-main strong, .cell-main a') || td;
            var corta = el.scrollWidth > el.clientWidth + 1;
            if (corta) {
                var txt = (td.textContent || '').trim().replace(/\s+/g, ' ');
                if (txt && td.getAttribute('title') !== txt) { td.setAttribute('title', txt); }
            } else if (td.hasAttribute('title')) {
                td.removeAttribute('title');
            }
        });
    }

    var relojRecorte = null;
    /**
     * Agenda marcarRecortadas() para dentro de 120 ms, descartando la agenda
     * anterior: al arrastrar el borde de la ventana llegan decenas de
     * `resize` por segundo y medir todas las celdas en cada uno traba la página.
     */
    function revisarRecortes() {
        clearTimeout(relojRecorte);
        relojRecorte = setTimeout(function () { marcarRecortadas(); }, 120);
    }
    window.addEventListener('resize', revisarRecortes);
    // El refresco automático trae filas nuevas, que todavía no pasaron por acá.
    document.addEventListener('vivo:actualizado', revisarRecortes);
    revisarRecortes();

    // --------------------------------------------------------
    //  Trabajos que tardan: decir que está pasando algo
    //
    //  Importar unos miles de filas lleva su tiempo y la pantalla se queda
    //  quieta. Sin nada que lo indique, la reacción natural es apretar de nuevo
    //  —y ahí se importa dos veces— o cerrar la pestaña a la mitad. El botón se
    //  bloquea y se muestra un aviso con los colores de la casa.
    // --------------------------------------------------------
    /**
     * Cubre la pantalla con el aviso de «procesando». No se quita nunca: la
     * página se reemplaza cuando responde el servidor.
     *
     * @param {string} titulo  texto principal (se pone con textContent).
     * @param {string} [detalle] texto secundario.
     * @returns {HTMLElement} la tapa agregada al body.
     */
    function tapaCargando(titulo, detalle) {
        var t = document.createElement('div');
        t.className = 'cargando-tapa';
        t.innerHTML =
            '<div class="cargando-caja">' +
              '<div class="cargando-aro" aria-hidden="true"><i></i><i></i><i></i></div>' +
              '<strong></strong><span></span>' +
            '</div>';
        t.querySelector('strong').textContent = titulo;
        t.querySelector('span').textContent = detalle || '';
        t.setAttribute('role', 'status');
        t.setAttribute('aria-live', 'polite');
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('visible'); });
        return t;
    }

    // Sólo actúa en formularios que tienen un botón con data-cargando. La marca
    // data-enviando no se borra: si el usuario vuelve con «Atrás» y el
    // navegador restaura la página desde su caché, el formulario queda sin tapa
    // pero con el botón deshabilitado.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.dataset.enviando === '1') { return; }
        var btn = form.querySelector('[data-cargando]');
        if (!btn) { return; }
        // Si el navegador frenó el envío por un campo inválido, no hay nada que
        // esperar: mostrar la tapa dejaría la pantalla bloqueada sin motivo.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }

        form.dataset.enviando = '1';
        btn.disabled = true;
        tapaCargando(btn.getAttribute('data-cargando') || 'Procesando…',
                     btn.getAttribute('data-cargando-detalle') || '');
    });

    // --------------------------------------------------------
    //  Selección múltiple en los listados
    //
    //  Normalizar una base importada es repasar cientos de fichas. Abriendo una
    //  por una nadie lo termina: se filtra por pendientes, se repasa la pantalla
    //  y se aprueba de a varias juntas. La barra aparece recién cuando hay algo
    //  marcado, porque fija ocuparía lugar todo el tiempo.
    // --------------------------------------------------------
    /**
     * Las casillas de selección de la página, como array.
     * @returns {HTMLInputElement[]}
     */
    function casillas() {
        return Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"]'));
    }

    /**
     * Sincroniza la barra de selección con las casillas: la muestra u oculta,
     * actualiza la cuenta, resalta las filas marcadas y deja la casilla de
     * «marcar todo» tildada, vacía o en estado intermedio.
     */
    function refrescarSeleccion() {
        var barra = document.querySelector('[data-barra-seleccion]');
        if (!barra) { return; }
        var todas = casillas();
        var marcadas = todas.filter(function (c) { return c.checked; });

        barra.hidden = marcadas.length === 0;
        var n = barra.querySelector('[data-sel-n]');
        if (n) { n.textContent = String(marcadas.length); }

        // La fila marcada se ve marcada: con la casilla sola, en una tabla de
        // cien filas se pierde de vista qué se eligió.
        todas.forEach(function (c) {
            var tr = c.closest('tr');
            if (tr) { tr.classList.toggle('marcada', c.checked); }
        });

        var todo = document.querySelector('[data-marcar-todo]');
        if (todo) {
            todo.checked = todas.length > 0 && marcadas.length === todas.length;
            // Ni todas ni ninguna: el estado intermedio lo dice mejor que un
            // tilde a medias.
            todo.indeterminate = marcadas.length > 0 && marcadas.length < todas.length;
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-marcar-todo]')) {
            var v = e.target.checked;
            casillas().forEach(function (c) { c.checked = v; });
            refrescarSeleccion();
            return;
        }
        if (e.target.matches && e.target.matches('input[name="ids[]"]')) { refrescarSeleccion(); }
    });

    // El refresco automático reemplaza la tabla: la selección se va con ella y
    // la barra tiene que dejar de prometer una cantidad que ya no existe.
    document.addEventListener('vivo:actualizado', refrescarSeleccion);
    refrescarSeleccion();

    // Para los scripts que necesitan confirmar algo que no es un clic en un
    // elemento con data-confirmar (por ejemplo, editor.js cuando se quiere
    // elegir una imagen y todavía no hay ninguna subida).
    window.crmConfirmar = confirmar;
})();
