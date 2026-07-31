<?php /** @var ?array $aviso @var callable $s @var bool $smtp @var bool $imap @var string $cron */ ?>
<div class="page-head">
    <div>
        <h1>Ajustes</h1>
        <p class="muted">Cuenta de correo, ritmo de las campañas y modo de trabajo</p>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Correo de salida</span>
        <span class="stat-value" style="font-size:18px"><?= $smtp ? 'Configurado' : 'Sin configurar' ?></span>
        <span class="stat-foot"><?= $smtp ? 'Las campañas pueden enviarse' : 'Las campañas no van a salir' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Casilla de entrada</span>
        <span class="stat-value" style="font-size:18px"><?= $imap ? 'Configurada' : 'Sin configurar' ?></span>
        <span class="stat-foot"><?= $imap ? 'Se leen rebotes y respuestas' : 'No se detectan rebotes' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Modo</span>
        <span class="stat-value" style="font-size:18px"><?= $s('app_entorno', 'testing') === 'testing' ? 'Prueba' : 'Producción' ?></span>
        <span class="stat-foot"><?= $s('app_entorno', 'testing') === 'testing' ? 'Sólo se escribe a la lista interna' : 'Los correos salen a clientes reales' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Última corrida del cron</span>
        <span class="stat-value" style="font-size:18px"><?= $cron ? fecha_hora($cron) : 'nunca' ?></span>
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
            </label>
            <label class="fld"><span>Modo de trabajo</span>
                <select name="app_entorno">
                    <option value="testing"    <?= $s('app_entorno', 'testing') === 'testing' ? 'selected' : '' ?>>Prueba (no se escribe a clientes)</option>
                    <option value="produccion" <?= $s('app_entorno') === 'produccion' ? 'selected' : '' ?>>Producción</option>
                </select>
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
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Son los que usan las plantillas de campaña: el bloque de contacto, el botón de
            WhatsApp y las variables <code class="mono">{{remitente_empresa}}</code> y
            <code class="mono">{{empresa_web}}</code>.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Nombre de la empresa</span>
                <input name="empresa_nombre" value="<?= e($s('empresa_nombre', 'INTELLIHELP')) ?>" maxlength="120">
            </label>
            <label class="fld"><span>Sitio web</span>
                <input name="empresa_web" value="<?= e($s('empresa_web')) ?>" maxlength="190" placeholder="intellihelp.tech">
            </label>
            <label class="fld"><span>Teléfono</span>
                <input name="empresa_telefono" value="<?= e($s('empresa_telefono')) ?>" maxlength="60">
            </label>
            <label class="fld"><span>WhatsApp</span>
                <input name="empresa_whatsapp" value="<?= e($s('empresa_whatsapp')) ?>" maxlength="60" placeholder="5491150274827">
                <i class="hint">Sólo números, con código de país y sin el «+». Es el que usa el botón de WhatsApp.</i>
            </label>
            <label class="fld wide"><span>Correo de contacto</span>
                <input name="empresa_email" value="<?= e($s('empresa_email')) ?>" maxlength="190" placeholder="info@intellihelp.tech">
                <i class="hint">El que se muestra en el correo para que te escriban. No es el mismo que la casilla desde la que se envía.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cuenta desde la que salen los correos</h2>
        <div class="flash flash-info" style="margin:-.3rem 0 1rem">
            <strong>Con Google Workspace hace falta una «contraseña de aplicación»</strong>, no la
            contraseña con la que entrás a Google. Se genera en
            <em>Cuenta de Google → Seguridad → Verificación en dos pasos → Contraseñas de aplicaciones</em>
            y son 16 letras en cuatro grupos.
        </div>
        <div class="grid-fields">
            <label class="fld wide"><span>Dirección de correo de la cuenta</span>
                <input name="smtp_user" value="<?= e($s('smtp_user')) ?>" autocomplete="off"
                       placeholder="ithos@intellihelp.tech" type="email">
                <i class="hint">La casilla con la que el sistema se conecta a Google para enviar. Va la dirección completa.</i>
            </label>
            <label class="fld wide"><span>Contraseña de aplicación</span>
                <input type="password" name="smtp_pass" value="" autocomplete="new-password"
                       placeholder="<?= $s('smtp_pass') !== '' ? '•••••• · ya está guardada, dejala vacía para no cambiarla' : 'xxxx xxxx xxxx xxxx' ?>">
                <i class="hint">La de 16 letras que genera Google. Se guarda cifrada y no se vuelve a mostrar.</i>
            </label>

            <label class="fld wide"><span>Dirección que ve el destinatario</span>
                <input name="smtp_from" value="<?= e($s('smtp_from')) ?>" type="email"
                       placeholder="la misma de arriba">
                <i class="hint">La que aparece como remitente en la bandeja del cliente. Si la dejás vacía se usa la de arriba. Sólo tiene sentido cambiarla si Google tiene configurado un alias.</i>
            </label>
            <label class="fld wide"><span>Nombre que ve el destinatario</span>
                <input name="smtp_from_name" value="<?= e($s('smtp_from_name', 'INTELLIHELP')) ?>">
                <i class="hint">Lo que se lee en negrita en la bandeja, antes del asunto. Por ejemplo «INTELLIHELP» o «Brian de INTELLIHELP».</i>
            </label>

            <label class="fld"><span>Servidor de salida</span>
                <input name="smtp_host" value="<?= e($s('smtp_host', 'smtp.gmail.com')) ?>">
                <i class="hint">Con Gmail o Workspace: <code class="mono">smtp.gmail.com</code>.</i>
            </label>
            <label class="fld"><span>Puerto</span><input name="smtp_port" value="<?= e($s('smtp_port', '587')) ?>"></label>
            <label class="fld"><span>Seguridad</span>
                <select name="smtp_seguridad">
                    <option value="tls" <?= $s('smtp_seguridad', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (puerto 587)</option>
                    <option value="ssl" <?= $s('smtp_seguridad') === 'ssl' ? 'selected' : '' ?>>SSL directo (puerto 465)</option>
                </select>
                <i class="hint">Con Gmail, STARTTLS en el 587.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Casilla donde llegan los rebotes y las respuestas</h2>
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Es la <strong>bandeja de entrada</strong> de la misma cuenta. De ahí el sistema lee los avisos
            de correo no entregado (<code class="mono">mailer-daemon</code>, <code class="mono">postmaster</code>)
            y las respuestas de los clientes, para marcarlas en el CRM.
            <strong>Si la dejás vacía se usa la cuenta de salida</strong>, que es lo habitual.
        </p>
        <div class="grid-fields">
            <label class="fld wide"><span>Dirección de correo</span>
                <input name="imap_user" value="<?= e($s('imap_user')) ?>" autocomplete="off" type="email"
                       placeholder="la misma de salida">
            </label>
            <label class="fld wide"><span>Contraseña de aplicación</span>
                <input type="password" name="imap_pass" value="" autocomplete="new-password"
                       placeholder="<?= $s('imap_pass') !== '' ? '•••••• · ya está guardada' : 'la misma de salida' ?>">
            </label>
            <label class="fld"><span>Servidor de entrada</span>
                <input name="imap_host" value="<?= e($s('imap_host', 'imap.gmail.com')) ?>">
                <i class="hint">Con Gmail o Workspace: <code class="mono">imap.gmail.com</code>.</i>
            </label>
            <label class="fld"><span>Puerto</span><input name="imap_port" value="<?= e($s('imap_port', '993')) ?>"></label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Ritmo por defecto de las campañas</h2>
        <div class="grid-fields">
            <label class="fld"><span>Pausa mínima (seg)</span><input type="number" name="campana_pausa_min" value="<?= e($s('campana_pausa_min', '25')) ?>" min="5"></label>
            <label class="fld"><span>Pausa máxima (seg)</span><input type="number" name="campana_pausa_max" value="<?= e($s('campana_pausa_max', '45')) ?>" min="10"></label>
            <label class="fld"><span>Límite diario</span><input type="number" name="campana_limite_diario" value="<?= e($s('campana_limite_diario', '300')) ?>" min="1"></label>
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

<div class="card" style="margin-top:1rem">
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

<div class="card" style="margin-top:1rem">
    <div class="card-head"><h2>Tarea automática</h2></div>
    <p class="muted small">
        Para que las campañas se manden solas, que se lean los rebotes y que salgan los recordatorios
        de las reuniones, hay que agendar la tarea del sistema cada 5 minutos en el panel del hosting.
        Si preferís, se puede disparar por URL usando la clave de arriba.
    </p>
</div>
