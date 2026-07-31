<?php /** @var ?array $aviso @var callable $s @var bool $smtp @var bool $imap @var string $cron @var array $cuentas */ ?>
<div class="page-head">
    <div>
        <h1>Ajustes</h1>
        <p class="muted">Cuenta de correo, ritmo de las campañas y modo de trabajo</p>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>
<?php include APP . '/views/_aviso_gcal.php'; ?>

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
        <span class="stat-label">Modo</span>
        <span class="stat-value sm"><?= $s('app_entorno', 'testing') === 'testing' ? 'Prueba' : 'Producción' ?></span>
        <span class="stat-foot"><?= $s('app_entorno', 'testing') === 'testing' ? 'Sólo se escribe a la lista interna' : 'Los correos salen a clientes reales' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Última corrida del cron</span>
        <span class="stat-value sm"><?= $cron ? fecha_hora($cron) : 'nunca' ?></span>
        <span class="stat-foot"><?= $cron ? tiempo_relativo($cron) : 'Falta agendarlo en el hosting' ?></span>
    </div>
</div>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">

    <div class="card form-card">
        <h2 class="sec">General</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre del sistema</span>
                <input name="app_nombre" value="<?= e($s('app_nombre', 'CRM INTELLIHELP')) ?>" maxlength="80">
                <i class="hint">Lo que se lee en la pestaña del navegador y en el pie de las pantallas.</i>
            </label>
            <label class="fld"><span>Modo de trabajo</span>
                <select name="app_entorno">
                    <option value="testing"    <?= $s('app_entorno', 'testing') === 'testing' ? 'selected' : '' ?>>Prueba (no se escribe a clientes)</option>
                    <option value="produccion" <?= $s('app_entorno') === 'produccion' ? 'selected' : '' ?>>Producción</option>
                </select>
                <i class="hint">En «Prueba» sólo sale correo a las direcciones de abajo: podés armar y probar campañas sin riesgo de escribirle a un cliente real por error.</i>
            </label>
            <label class="fld full"><span>Destinatarios permitidos en modo prueba</span>
                <input name="testing_destinatarios" value="<?= e($s('testing_destinatarios')) ?>" maxlength="500"
                       placeholder="tu@intellihelp.tech, otro@intellihelp.tech">
                <i class="hint">En modo prueba, cualquier correo de campaña que no vaya a estas direcciones se omite.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Datos de la empresa</h2>
        <p class="muted small card-intro">
            Son los que usan las plantillas de campaña: el bloque de contacto, el botón de
            WhatsApp y las variables <code class="mono">{{remitente_empresa}}</code> y
            <code class="mono">{{empresa_web}}</code>.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Nombre de la empresa</span>
                <input name="empresa_nombre" value="<?= e($s('empresa_nombre', 'INTELLIHELP')) ?>" maxlength="120">
                <i class="hint">Sale en los correos, en la firma y en la variable {{remitente_empresa}}.</i>
            </label>
            <label class="fld"><span>Sitio web</span>
                <input name="empresa_web" value="<?= e($s('empresa_web')) ?>" maxlength="190" placeholder="intellihelp.tech">
                <i class="hint">Va en el encabezado del correo y en la variable {{empresa_web}}. Sin http:// adelante.</i>
            </label>
            <label class="fld"><span>Teléfono</span>
                <input type="tel" inputmode="tel" name="empresa_telefono" value="<?= e($s('empresa_telefono')) ?>" maxlength="60" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">El fijo, para el bloque de datos de contacto del correo. El de WhatsApp va en el campo de al lado.</i>
            </label>
            <label class="fld"><span>WhatsApp</span>
                <input type="tel" inputmode="tel" name="empresa_whatsapp" value="<?= e($s('empresa_whatsapp')) ?>" maxlength="60" placeholder="5491150274827" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">Sólo números, con código de país y sin el «+». Es el que usa el botón de WhatsApp.</i>
            </label>
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
                <input name="cron_clave" value="<?= e($s('cron_clave')) ?>" maxlength="60">
                <i class="hint">Sólo hace falta si el hosting no permite ejecutar el cron por línea de comandos.</i>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?>Guardar ajustes</button>
    </div>
</form>

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
