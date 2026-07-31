<?php /** @var ?array $aviso @var array $rows @var ?array $editar @var array $usuarios */
$e2 = fn(string $k, $def = '') => e($editar[$k] ?? $def);
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Cuentas de correo</h1>
        <p class="muted">Desde qué casillas salen las campañas</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('campanas')) ?>"><?= icon('campanas', 16) ?>Campañas</a>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>

<div class="flash flash-info">
    Con <strong>varias cuentas</strong> se pueden correr campañas en paralelo. Hay dos razones para
    hacerlo así: cada casilla tiene su <strong>propio tope diario</strong>, y la <strong>reputación
    es por remitente</strong> — si una campaña sale mal, se quema esa casilla y no las demás.
</div>

<?php
/* Una cuenta cuyo «Responder a» apunta a una casilla que el sistema NO lee deja
   las respuestas invisibles: le llegan a esa persona y el CRM no las registra,
   no marca el envío como respondido y no avisa. Es un error de configuración
   que no se nota hasta que alguien pregunta por qué no aparece ninguna
   respuesta, así que se avisa acá, que es donde nace. */
$malConfig = [];
foreach ($rows as $rc) {
    if (empty($rc['activo'])) { continue; }
    $lee = normalizar_email((string)(cuenta_a_imap($rc)['user'] ?? ''));
    $vuelve = normalizar_email((string)($rc['responder_a'] ?? '')) ?: normalizar_email((string)$rc['remitente']);
    if ($lee !== '' && $vuelve !== $lee) { $malConfig[] = [$rc, $vuelve, $lee]; }
}
?>
<?php foreach ($malConfig as [$rc, $vuelve, $lee]): ?>
    <div class="flash flash-warn">
        <strong>«<?= e($rc['nombre']) ?>»: las respuestas no se van a detectar.</strong>
        Quien conteste un correo de esta cuenta le va a escribir a <strong><?= e($vuelve) ?></strong>,
        pero el sistema lee <strong><?= e($lee) ?></strong>. Esas respuestas le van a llegar a esa
        persona y el CRM <strong>no las va a registrar ni te va a avisar</strong>.
        Dejá «Responder a» vacío, o poné <?= e($lee) ?>.
    </div>
<?php endforeach; ?>

<div class="card table-card mb-4" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr><th>Cuenta</th><th>Hoy</th><th>Estado</th><th class="prio-2">Servidor</th>
                    <th class="prio-3">Dueño</th><th class="acts">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">Todavía no hay cuentas cargadas. Agregá la primera con el formulario de abajo.</td></tr>
            <?php else: foreach ($rows as $c):
                $pct = $c['limite_diario'] > 0
                     ? min(100, (int)round(100 * $c['enviados_hoy'] / $c['limite_diario'])) : 0;
            ?>
                <tr>
                    <td data-label="Cuenta">
                        <div class="cell-main">
                            <strong><?= e($c['nombre']) ?>
                                <?php if ($c['predeterminada']): ?><span class="chip">por defecto</span><?php endif; ?>
                            </strong>
                            <small><?= e($c['remitente']) ?></small>
                        </div>
                    </td>
                    <td data-label="Hoy" class="col-avance">
                        <div class="progreso"><i class="<?= $pct >= 100 ? '' : 'ok' ?>" style="width:<?= $pct ?>%"></i></div>
                        <span class="small muted"><?= (int)$c['enviados_hoy'] ?> / <?= (int)$c['limite_diario'] ?></span>
                    </td>
                    <td data-label="Estado">
                        <?php if (!$c['activo']): ?>
                            <span class="badge badge-neutral">Inactiva</span>
                        <?php elseif ($c['ultimo_error']): ?>
                            <span class="badge badge-rej" title="<?= e($c['ultimo_error']) ?>">Con error</span>
                        <?php elseif ($c['verificada_en']): ?>
                            <span class="badge badge-ok">Verificada</span>
                        <?php else: ?>
                            <span class="badge badge-pend">Sin probar</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Servidor" class="prio-2"><span class="mono"><?= e($c['smtp_host']) ?>:<?= (int)$c['smtp_port'] ?></span></td>
                    <td data-label="Dueño" class="prio-3"><?= e($c['usuario'] ?: 'compartida') ?></td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="probar">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="act" title="Probar la conexión"><?= icon('refrescar', 16) ?></button>
                        </form>
                        <a class="act" href="<?= e(url('cuentas-correo?editar=' . (int)$c['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="act danger" title="Dar de baja" data-peligro
                                    data-confirmar-titulo="Dar de baja la cuenta"
                                    data-confirmar="Las campañas que la estén usando no van a poder seguir enviando."
                                    data-confirmar-ok="Dar de baja"><?= icon('papelera', 16) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">

    <div class="card form-card">
        <h2 class="sec"><?= !empty($editar['id']) ? 'Editar la cuenta' : 'Agregar una cuenta' ?></h2>
        <p class="muted small card-intro">
            Con Google Workspace hay que usar una <strong>contraseña de aplicación</strong>, no la
            contraseña normal. Se genera desde la seguridad de la cuenta de Google.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Nombre interno <i class="req">*</i></span>
                <input name="nombre" value="<?= $e2('nombre') ?>" maxlength="120" required placeholder="Comercial, RRHH, Carlos…">
                <i class="hint">Para reconocerla en la lista al elegir desde dónde sale una campaña. Por ejemplo «Comercial» o «RRHH».</i>
            </label>
            <label class="fld"><span>Dirección del remitente <i class="req">*</i></span>
                <input type="email" name="remitente" value="<?= $e2('remitente') ?>" maxlength="190" required>
                <i class="hint">La dirección que ve el destinatario como remitente.</i>
            </label>
            <label class="fld"><span>Nombre que ve el destinatario</span>
                <input name="remitente_nombre" value="<?= $e2('remitente_nombre') ?>" maxlength="160">
                <i class="hint">El nombre que se lee en negrita en la bandeja, antes del asunto.</i>
            </label>
            <label class="fld"><span>Responder a</span>
                <input type="email" name="responder_a" value="<?= $e2('responder_a') ?>" maxlength="190">
                <i class="hint">Adónde va la respuesta cuando aprietan Responder. Si lo dejás vacío, se responde a la dirección del remitente.</i>
            </label>

            <label class="fld"><span>Servidor de salida</span>
                <input name="smtp_host" value="<?= $e2('smtp_host', 'smtp.gmail.com') ?>" maxlength="120">
                <i class="hint">El servidor por el que sale el correo. Con Gmail o Google Workspace: smtp.gmail.com</i>
            </label>
            <label class="fld"><span>Puerto</span>
                <input type="number" name="smtp_port" value="<?= $e2('smtp_port', '587') ?>">
                <i class="hint">587 con STARTTLS, que es lo normal. 465 si elegís SSL directo.</i>
            </label>
            <label class="fld"><span>Seguridad</span>
                <select name="smtp_seguridad">
                    <option value="tls" <?= ($editar['smtp_seguridad'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl" <?= ($editar['smtp_seguridad'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL directo (465)</option>
                </select>
                <i class="hint">Cómo se cifra la conexión. Con Gmail, STARTTLS en el 587.</i>
            </label>
            <label class="fld"><span>Usuario</span>
                <input name="smtp_user" value="<?= $e2('smtp_user') ?>" autocomplete="off" maxlength="190">
                <i class="hint">La casilla con la que el sistema se conecta a Google. Va la dirección completa.</i>
            </label>
            <label class="fld"><span>Contraseña de aplicación</span>
                <input type="password" name="smtp_pass" autocomplete="new-password"
                       placeholder="<?= !empty($editar['smtp_pass']) ? '•••••• (vacío = no cambiar)' : '' ?>">
                       <i class="hint">Con Google Workspace hace falta una contraseña de aplicación, no la que usás para entrar a Google. Son 16 letras en cuatro grupos. Se guarda cifrada y no se vuelve a mostrar.</i>
                   </label>

            <label class="fld"><span>Tope diario de esta cuenta</span>
                <input type="number" name="limite_diario" value="<?= $e2('limite_diario', '300') ?>" min="1" max="5000">
                <i class="hint">Google corta cerca de los 2.000 por día; conviene quedarse bien por debajo.</i>
            </label>
            <label class="fld"><span>Dueño</span>
                <select name="usuario_id">
                    <option value="">Compartida</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($editar['usuario_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Quién puede usarla. «Compartida» la habilita para todo el equipo.</i>
            </label>
        </div>

        <h3 class="mt-6 mb-3">Casilla de entrada (rebotes y respuestas)</h3>
        <p class="muted small card-intro">Si la dejás vacía se usa la misma cuenta de salida.</p>
        <div class="grid-fields">
            <label class="fld"><span>Servidor</span><input name="imap_host" value="<?= $e2('imap_host', 'imap.gmail.com') ?>" maxlength="120">
                <i class="hint">De dónde se leen los rebotes y las respuestas. Con Gmail: imap.gmail.com</i>
            </label>
            <label class="fld"><span>Puerto</span><input type="number" name="imap_port" value="<?= $e2('imap_port', '993') ?>">
                <i class="hint">993, que es el puerto seguro de IMAP.</i>
            </label>
            <label class="fld"><span>Usuario</span><input name="imap_user" value="<?= $e2('imap_user') ?>" autocomplete="off" maxlength="190">
                <i class="hint">Si la dejás vacía se usa la misma cuenta de salida, que es lo habitual.</i>
            </label>
            <label class="fld"><span>Contraseña</span>
                <input type="password" name="imap_pass" autocomplete="new-password"
                       placeholder="<?= !empty($editar['imap_pass']) ? '•••••• (vacío = no cambiar)' : 'igual que la de salida' ?>">
                       <i class="hint">La misma contraseña de aplicación de arriba, salvo que la casilla de entrada sea otra cuenta.</i>
                   </label>
        </div>

        <div class="filter-actions">
            <label class="switch">
                <input type="checkbox" name="activo" value="1" <?= !isset($editar['activo']) || $editar['activo'] ? 'checked' : '' ?>>
                <span class="track"></span><span>Activa</span>
            </label>
            <label class="switch">
                <input type="checkbox" name="predeterminada" value="1" <?= !empty($editar['predeterminada']) ? 'checked' : '' ?>>
                <span class="track"></span><span>Usar por defecto</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?>Guardar y probar</button>
        <?php if (!empty($editar['id'])): ?>
            <a class="btn btn-ghost" href="<?= e(url('cuentas-correo')) ?>">Cancelar</a>
        <?php endif; ?>
    </div>
</form>
