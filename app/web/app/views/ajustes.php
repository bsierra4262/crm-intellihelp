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
        <h2 class="sec">Cuenta desde la que salen los correos</h2>
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Con Google Workspace hay que usar una <strong>contraseña de aplicación</strong>, no la contraseña
            normal de la cuenta. Se genera desde la seguridad de la cuenta de Google.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Servidor</span><input name="smtp_host" value="<?= e($s('smtp_host', 'smtp.gmail.com')) ?>"></label>
            <label class="fld"><span>Puerto</span><input name="smtp_port" value="<?= e($s('smtp_port', '587')) ?>"></label>
            <label class="fld"><span>Seguridad</span>
                <select name="smtp_seguridad">
                    <option value="tls" <?= $s('smtp_seguridad', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl" <?= $s('smtp_seguridad') === 'ssl' ? 'selected' : '' ?>>SSL directo (465)</option>
                </select>
            </label>
            <label class="fld"><span>Usuario</span><input name="smtp_user" value="<?= e($s('smtp_user')) ?>" autocomplete="off"></label>
            <label class="fld"><span>Contraseña de aplicación</span>
                <input type="password" name="smtp_pass" value="" autocomplete="new-password"
                       placeholder="<?= $s('smtp_pass') !== '' ? '•••••• (dejala vacía para no cambiarla)' : '' ?>">
            </label>
            <label class="fld"><span>Remitente (From)</span><input name="smtp_from" value="<?= e($s('smtp_from')) ?>"></label>
            <label class="fld"><span>Nombre del remitente</span><input name="smtp_from_name" value="<?= e($s('smtp_from_name', 'INTELLIHELP')) ?>"></label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Casilla de entrada (rebotes y respuestas)</h2>
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Si la dejás vacía se usa la misma cuenta de salida. Es de donde se leen los rebotes
            (<code class="mono">mailer-daemon</code>, <code class="mono">postmaster</code>) y las respuestas de los clientes.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Servidor</span><input name="imap_host" value="<?= e($s('imap_host', 'imap.gmail.com')) ?>"></label>
            <label class="fld"><span>Puerto</span><input name="imap_port" value="<?= e($s('imap_port', '993')) ?>"></label>
            <label class="fld"><span>Usuario</span><input name="imap_user" value="<?= e($s('imap_user')) ?>" autocomplete="off"></label>
            <label class="fld"><span>Contraseña</span>
                <input type="password" name="imap_pass" value="" autocomplete="new-password"
                       placeholder="<?= $s('imap_pass') !== '' ? '•••••• (dejala vacía para no cambiarla)' : 'igual que la de salida' ?>">
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Ritmo por defecto de las campañas</h2>
        <div class="grid-fields">
            <label class="fld"><span>Pausa mínima (seg)</span><input type="number" name="campana_pausa_min" value="<?= e($s('campana_pausa_min', '25')) ?>" min="5"></label>
            <label class="fld"><span>Pausa máxima (seg)</span><input type="number" name="campana_pausa_max" value="<?= e($s('campana_pausa_max', '45')) ?>" min="10"></label>
            <label class="fld"><span>Límite diario</span><input type="number" name="campana_limite_diario" value="<?= e($s('campana_limite_diario', '300')) ?>" min="1"></label>
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
