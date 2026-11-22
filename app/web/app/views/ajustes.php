<?php
// ============================================================
//  Vista de Ajustes. La dibuja pages/ajustes.php (sólo administradores).
//
//  Variables que recibe:
//    · $aviso   ?array [tipo, texto]: resultado de la última acción, o null.
//    · $s       callable($clave, $defecto): lee un ajuste de app_settings.
//    · $smtp    bool: hay correo de salida configurado.
//    · $imap    bool: hay casilla de entrada configurada.
//    · $cron    string: fecha de la última corrida del cron ('' si nunca).
//    · $cuentas array: las casillas de cuentas_correo (id, nombre, remitente,
//      activo, predeterminada).
//  Además consulta por su cuenta datos_prueba_resumen(), modulos(),
//  notif_tipos() y setting_get().
//
//  Formularios: uno grande (accion=guardar, con el botón de borrar los datos
//  de prueba adentro) y, al final, tres chicos para probar el correo y
//  revisar las casillas. Todos llevan su token CSRF.
//
//  Todo dato que sale de la base o del usuario se imprime con e(); los
//  números con (int).
//
//  Los «hint» de cada campo explican la regla, pero los límites (min, max,
//  pattern, maxlength) los controla sólo el navegador: el servidor guarda lo
//  que llegue.
// ============================================================
/** @var ?array $aviso @var callable $s @var bool $smtp @var bool $imap @var string $cron @var array $cuentas */
?>
<div class="page-head">
    <div>
        <h1>Ajustes</h1>
        <p class="muted">Cuenta de correo, ritmo de las campañas y modo de trabajo</p>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>
<?php include APP . '/views/_aviso_gcal.php'; ?>

<?php // Tablero de estado: correo, casilla, modo y cron, de un vistazo. ?>
<div class="cards">
    <div class="card stat">
        <span class="stat-label">Correo de salida</span>
        <span class="stat-value sm"><?= $smtp ? 'Configurado' : 'Sin configurar' ?></span>
        <span class="stat-foot"><?= $smtp ? 'Las campañas pueden enviarse' : 'Las campañas no van a salir' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Casilla de entrada</span>
        <span class="stat-value sm"><?= $imap ? 'Configurada' : 'Sin configurar' ?></span>
        <span class="stat-foot"><?= $imap ? 'Se leen rebotes y respuestas' : 'No se detectan rebotes' ?></span>
    </div>
    <div class="card stat">
        <?php // Cualquier valor de app_entorno distinto de 'testing' se muestra (y se comporta) como Producción. ?>
        <span class="stat-label">Modo</span>
        <span class="stat-value sm"><?= $s('app_entorno', 'testing') === 'testing' ? 'Prueba' : 'Producción' ?></span>
        <span class="stat-foot"><?= $s('app_entorno', 'testing') === 'testing'
            ? 'Los correos salen igual; lo de prueba son los datos'
            : 'En marcha, con datos reales' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Última corrida del cron</span>
        <span class="stat-value sm"><?= $cron ? fecha_hora($cron) : 'nunca' ?></span>
        <span class="stat-foot"><?= $cron ? tiempo_relativo($cron) : 'Falta agendarlo en el hosting' ?></span>
    </div>
</div>

<?php // El formulario grande: todo lo que se guarda con «Guardar ajustes». ?>
<form method="post" class="form-wrap">
    <?= csrf_field() ?>
    <?php // Va antes que el botón de «Borrar los datos de prueba»: si se envía con ese botón, PHP se queda con el último `accion` (el del botón). ?>
    <input type="hidden" name="accion" value="guardar">

    <div class="card form-card">
        <h2 class="sec">General</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre del sistema</span>
                <input name="app_nombre" value="<?= e($s('app_nombre', 'CRM INTELLIHELP')) ?>" maxlength="80" placeholder="CRM INTELLIHELP">
                <i class="hint">Lo que se lee en la pestaña del navegador y en el pie de las pantallas.</i>
            </label>
            <?php // Esta es la única traba contra «reiniciar todo»: cualquier administrador puede volver a «Prueba» desde acá, y el cambio no pide confirmación aparte. ?>
            <label class="fld"><span>Modo de trabajo</span>
                <select name="app_entorno">
                    <option value="testing"    <?= $s('app_entorno', 'testing') === 'testing' ? 'selected' : '' ?>>Prueba (se puede reiniciar todo)</option>
                    <option value="produccion" <?= $s('app_entorno') === 'produccion' ? 'selected' : '' ?>>Producción</option>
                </select>
                <i class="hint">
                    El sistema funciona igual en los dos modos: en «Prueba» los correos salen de
                    verdad, se agendan reuniones de verdad y se detectan las respuestas igual que en
                    producción. La diferencia es que sólo en «Prueba» se puede reiniciar todo para
                    volver a empezar el circuito de cero. En «Producción» esa opción se bloquea, así
                    que no hay forma de borrar la base por accidente.
                </i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Qué está en marcha</h2>
        <p class="muted small card-intro">
            El sistema se usa de verdad mucho antes de estar terminado, y está bien: la carga de
            contactos y empresas ya sirve aunque las campañas todavía se estén puliendo. Lo que no
            sirve es entrar a una pantalla a medio hacer creyendo que está lista, cargar algo ahí y
            que después no aparezca donde esperabas. Encendé cada parte cuando esté lista.
            <br><br>
            <strong>Contactos, Empresas y el registro de actividad están siempre.</strong> Son el
            núcleo: sin ellos no queda sistema.
            <?php if ($s('app_entorno', 'testing') === 'testing'): ?>
                <br><br>En <strong>modo prueba está todo encendido igual</strong>, sin importar lo
                que marques acá: es el lugar donde se termina lo que falta. Esto empieza a valer
                cuando pases a Producción.
            <?php endif; ?>
        </p>
        <?php // Un interruptor por módulo de modulos.php (name="modulo_<clave>"). Apagado no se envía: la página lo guarda en '0' por no recibirlo. ?>
        <div class="modulos">
            <?php foreach (modulos() as $clave => $m): ?>
                <label class="modulo">
                    <span class="switch">
                        <input type="checkbox" name="modulo_<?= e($clave) ?>" value="1"
                               <?= $s('modulo_' . $clave, '0') === '1' ? 'checked' : '' ?>>
                        <span class="track"></span>
                    </span>
                    <span class="modulo-txt">
                        <strong><?= e($m['nombre']) ?></strong>
                        <small><?= e($m['ayuda']) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // Qué queda de la prueba. La instalación es UNA sola: los contactos
    // ficticios que se sembraron para probar el circuito no pueden quedar
    // mezclados con los clientes de verdad, pero tampoco se pueden borrar a
    // ojo. Se sabe cuáles son porque quedaron anotados al sembrarlos.
    $prueba = datos_prueba_resumen();
    ?>
    <?php if ($prueba['total'] > 0): ?>
        <div class="card form-card">
            <h2 class="sec">Datos de prueba</h2>
            <p class="muted small card-intro">
                Esto lo cargó el sistema para poder probar el circuito, y se sabe exactamente
                cuáles son: se anotaron al crearlos. <strong>Lo que cargaste vos no se toca</strong>,
                ni por accidente.
            </p>
            <ul class="lista-datos">
                <?php foreach ($prueba['detalle'] as $qué => $n): ?>
                    <li><strong><?= (int)$n ?></strong> <?= e($qué) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted small mt-3">
                Antes de empezar a usarlo con clientes reales, conviene sacarlos: si no, «Dominio
                Inexistente» y compañía van a aparecer entre tus contactos. Las plantillas
                <strong>no</strong> se borran: son el punto de partida de las campañas de verdad.
            </p>
            <?php
            // Está DENTRO del formulario grande y se distingue sólo por su
            // name/value. formnovalidate deja borrar aunque algún campo de
            // ajustes no pase la validación del navegador. Con data-confirmar,
            // app.js confirma y envía con form.submit(), que no incluye el
            // valor del botón: ver la nota en pages/ajustes.php.
            ?>
            <div class="mt-3">
                <button class="btn btn-ghost" name="accion" value="limpiar_prueba" formnovalidate
                        data-peligro data-confirmar-titulo="Borrar los datos de prueba"
                        data-confirmar="Se borran los <?= (int)$prueba['total'] ?> registros que sembró el sistema para probar, y las campañas que los usaron. Lo que cargaste vos queda intacto. No se puede deshacer."
                        data-confirmar-ok="Borrarlos">
                    <?= icon('papelera', 16) ?>Borrar los datos de prueba
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="card form-card">
        <h2 class="sec">Datos de la empresa</h2>
        <p class="muted small card-intro">
            Son los que usan las plantillas de campaña: el bloque de contacto, el botón de
            WhatsApp y las variables <code class="mono">{{remitente_empresa}}</code> y
            <code class="mono">{{empresa_web}}</code>.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Nombre de la empresa</span>
                <input name="empresa_nombre" value="<?= e($s('empresa_nombre', 'INTELLIHELP')) ?>" maxlength="120" placeholder="INTELLIHELP">
                <i class="hint">Sale en los correos, en la firma y en la variable {{remitente_empresa}}.</i>
            </label>
            <label class="fld"><span>Sitio web</span>
                <input name="empresa_web" value="<?= e($s('empresa_web')) ?>" maxlength="190" placeholder="intellihelp.tech">
                <i class="hint">Va en el encabezado del correo y en la variable {{empresa_web}}. Sin http:// adelante.</i>
            </label>
            <?php // type="tel": campos.js borra al vuelo lo que no sea número o + ( ) . - y espacio. El pattern es TELEFONO_PATRON (crm.php), sólo del lado del navegador. ?>
            <label class="fld"><span>Teléfono</span>
                <input type="tel" inputmode="tel" name="empresa_telefono" value="<?= e($s('empresa_telefono')) ?>" maxlength="60" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios." placeholder="11 7700-6868">
                <i class="hint">El fijo, para el bloque de datos de contacto del correo. El de WhatsApp va en el campo de al lado.</i>
            </label>
            <?php // El hint pide sin «+», pero el pattern y campos.js lo aceptan: nada lo impide. ?>
            <label class="fld"><span>WhatsApp</span>
                <input type="tel" inputmode="tel" name="empresa_whatsapp" value="<?= e($s('empresa_whatsapp')) ?>" maxlength="60" placeholder="5491150274827" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">Sólo números, con código de país y sin el «+». Es el que usa el botón de WhatsApp.</i>
            </label>
            <?php // Campo de texto común, no type="email": no hay ninguna validación de formato, ni acá ni en el servidor. ?>
            <label class="fld wide"><span>Correo de contacto</span>
                <input name="empresa_email" value="<?= e($s('empresa_email')) ?>" maxlength="190" placeholder="info@intellihelp.tech">
                <i class="hint">El que se muestra en el correo para que te escriban. No es el mismo que la casilla desde la que se envía.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Casillas de correo</h2>
        <p class="muted small card-intro">
            Las casillas se cargan en <a href="<?= e(url('cuentas-correo')) ?>">Cuentas de correo</a>,
            que es el único lugar donde se configuran. Acá sólo se elige <strong>cuál se usa por
            defecto</strong>: la que manda los avisos del sistema, las confirmaciones de reunión y
            las pruebas de esta pantalla. Cada campaña puede salir por otra distinta, y eso se
            elige al armarla.
        </p>
        <?php if (!$cuentas): ?>
            <div class="flash flash-warn">
                Todavía no hay ninguna casilla cargada, así que el sistema no puede mandar correo.
                <a href="<?= e(url('cuentas-correo')) ?>">Cargá la primera acá</a>.
            </div>
        <?php else: ?>
            <div class="grid-fields">
                <label class="fld wide"><span>Casilla por defecto</span>
                    <?php
                    // Si ninguna casilla está marcada como predeterminada, el
                    // navegador deja elegida la primera y «Guardar ajustes» la
                    // convierte en la predeterminada sin que nadie la haya
                    // elegido. Las inactivas también se ofrecen.
                    ?>
                    <select name="cuenta_predeterminada">
                        <?php foreach ($cuentas as $cu): ?>
                            <option value="<?= (int)$cu['id'] ?>" <?= !empty($cu['predeterminada']) ? 'selected' : '' ?>>
                                <?= e($cu['nombre']) ?> · <?= e($cu['remitente']) ?>
                                <?= empty($cu['activo']) ? ' (inactiva)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="hint">De acá salen los correos que no son de campaña. Es también la
                        que se usa al apretar «Probar el correo de salida» más abajo.</i>
                </label>
            </div>
            <p class="muted small mt-3">
                Los rebotes se leen de <strong>todas</strong> las casillas activas, no sólo de esta:
                si una campaña sale por la casilla de RRHH, sus rebotes llegan a la casilla de RRHH
                y ahí se leen. Hoy hay <strong><?= count(array_filter($cuentas, fn($x) => !empty($x['activo']))) ?></strong>
                casilla(s) activa(s).
            </p>
        <?php endif; ?>
    </div>


    <div class="card form-card" id="alertas">
        <h2 class="sec">De qué te avisamos por correo</h2>
        <p class="muted small card-intro">
            Todo aparece igual en la campana de la barra, esté tildado o no. Esto es sólo si
            además querés que te llegue un correo, para enterarte sin tener el CRM abierto.
            Se agrupan: cinco cosas en diez minutos son un correo, no cinco.
        </p>
        <?php
        // Un interruptor por tipo de alerta, salvo 'sistema', que no se
        // ofrece. Como la página pone en '0' todo tipo que no llega en el
        // POST, guardar esta pantalla apaga siempre los avisos de 'sistema'.
        // Los valores por defecto (todo encendido menos 'rebote') tienen que
        // coincidir con los de notif_enviar_pendientes() en notificaciones.php.
        ?>
        <?php foreach (notif_tipos() as $k => $t): if ($k === 'sistema') continue; ?>
            <label class="switch bloque mb-3">
                <input type="checkbox" name="<?= e($t['ajuste']) ?>" value="1"
                       <?= (string) setting_get($t['ajuste'], $k === 'rebote' ? '0' : '1') === '1' ? 'checked' : '' ?>>
                <span class="track"></span>
                <span><strong><?= e($t['nombre']) ?></strong><br>
                    <span class="small muted"><?= e($t['ayuda']) ?></span></span>
            </label>
        <?php endforeach; ?>
        <div class="grid-fields">
            <?php
            // Lista separada por comas, guardada sin validar. Al mandar los
            // avisos se descartan las direcciones inválidas; si TODAS lo son,
            // los avisos se marcan como enviados sin mandarse (no se cae a los
            // administradores, eso pasa sólo con el campo vacío).
            ?>
            <label class="fld wide"><span>A qué dirección avisamos</span>
                <input name="avisar_email_a" value="<?= e($s('avisar_email_a')) ?>" maxlength="500"
                       placeholder="bsierra@intellihelp.tech, ventas@intellihelp.tech">
                <i class="hint">Separadas por coma. Si lo dejás vacío se les avisa a todos los
                    administradores activos.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Ritmo por defecto de las campañas</h2>
        <?php
        // Ningún campo de esta tarjeta es obligatorio y el servidor no los
        // valida: se puede guardar vacío, o una pausa mínima mayor que la
        // máxima. Vaciar «Días en la papelera» deja '' guardado, que
        // campanas.php lee como 0 y lleva a 1 día (max(1, ...)).
        // La clave del cron se muestra en claro (sólo la ven administradores)
        // y viaja en la URL del cron por web.
        ?>
        <div class="grid-fields">
            <label class="fld"><span>Pausa mínima (seg)</span><input type="number" name="campana_pausa_min" value="<?= e($s('campana_pausa_min', '25')) ?>" min="5">
                <i class="hint">El valor con el que arranca cada campaña nueva. Después se puede cambiar campaña por campaña.</i>
            </label>
            <label class="fld"><span>Pausa máxima (seg)</span><input type="number" name="campana_pausa_max" value="<?= e($s('campana_pausa_max', '45')) ?>" min="10">
                <i class="hint">Ídem: la espera real es un número al azar entre el mínimo y este.</i>
            </label>
            <label class="fld"><span>Límite diario</span><input type="number" name="campana_limite_diario" value="<?= e($s('campana_limite_diario', '300')) ?>" min="1">
                <i class="hint">Tope diario por defecto. Google corta cerca de los 2.000 por día en Workspace; conviene quedarse bien por debajo.</i>
            </label>
            <label class="fld"><span>Días en la papelera</span>
                <input type="number" name="papelera_dias" value="<?= e($s('papelera_dias', '60')) ?>" min="1" max="365">
                <i class="hint">Cuánto se conserva lo archivado antes de borrarlo definitivamente.</i>
            </label>
            <label class="fld"><span>Clave del cron por URL</span>
                <input name="cron_clave" value="<?= e($s('cron_clave')) ?>" maxlength="60" placeholder="una clave larga y dificil de adivinar">
                <i class="hint">Sólo hace falta si el hosting no permite ejecutar el cron por línea de comandos.</i>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?>Guardar ajustes</button>
    </div>
</form>

<?php // Acciones sueltas, fuera del formulario grande: cada botón tiene su propio formulario con su `accion`. No guardan los ajustes que se hayan cambiado arriba sin guardar. ?>
<div class="card mt-4">
    <div class="card-head"><h2>Probar la configuración</h2></div>
    <div class="urow">
        <form class="inline-form" method="post"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="probar_smtp">
            <button class="btn btn-ghost"><?= icon('campanas', 16) ?>Probar el correo de salida</button>
        </form>
        <form class="inline-form" method="post"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="probar_imap">
            <button class="btn btn-ghost"><?= icon('importar', 16) ?>Probar la casilla de entrada</button>
        </form>
        <form class="inline-form" method="post"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="revisar_casilla">
            <button class="btn btn-ghost"><?= icon('refrescar', 16) ?>Revisar rebotes y respuestas ahora</button>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-head"><h2>Tarea automática</h2></div>
    <p class="muted small">
        Para que las campañas se manden solas, que se lean los rebotes y que salgan los recordatorios
        de las reuniones, hay que agendar la tarea del sistema cada 5 minutos en el panel del hosting.
        Si preferís, se puede disparar por URL usando la clave de arriba.
    </p>
</div>
