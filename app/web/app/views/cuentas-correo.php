<?php /** @var ?array $aviso @var array $rows @var ?array $editar @var array $usuarios */
$e2 = fn(string $k, $def = '') => e($editar[$k] ?? $def);
?>
<div class="page-head">
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

<div class="card table-card" style="margin-bottom:1rem">
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
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Con Google Workspace hay que usar una <strong>contraseña de aplicación</strong>, no la
            contraseña normal. Se genera desde la seguridad de la cuenta de Google.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Nombre interno <i class="req">*</i></span>
                <input name="nombre" value="<?= $e2('nombre') ?>" maxlength="120" required placeholder="Comercial, RRHH, Carlos…">
            </label>
            <label class="fld"><span>Dirección del remitente <i class="req">*</i></span>
                <input type="email" name="remitente" value="<?= $e2('remitente') ?>" maxlength="190" required>
            </label>
            <label class="fld"><span>Nombre que ve el destinatario</span>
                <input name="remitente_nombre" value="<?= $e2('remitente_nombre') ?>" maxlength="160">
            </label>
            <label class="fld"><span>Responder a</span>
                <input type="email" name="responder_a" value="<?= $e2('responder_a') ?>" maxlength="190">
            </label>

            <label class="fld"><span>Servidor de salida</span>
                <input name="smtp_host" value="<?= $e2('smtp_host', 'smtp.gmail.com') ?>">
            </label>
            <label class="fld"><span>Puerto</span>
                <input type="number" name="smtp_port" value="<?= $e2('smtp_port', '587') ?>">
            </label>
            <label class="fld"><span>Seguridad</span>
                <select name="smtp_seguridad">
                    <option value="tls" <?= ($editar['smtp_seguridad'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl" <?= ($editar['smtp_seguridad'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL directo (465)</option>
                </select>
            </label>
            <label class="fld"><span>Usuario</span>
                <input name="smtp_user" value="<?= $e2('smtp_user') ?>" autocomplete="off">
            </label>
            <label class="fld"><span>Contraseña de aplicación</span>
                <input type="password" name="smtp_pass" autocomplete="new-password"
                       placeholder="<?= !empty($editar['smtp_pass']) ? '•••••• (vacío = no cambiar)' : '' ?>">
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
            </label>
        </div>

        <h3 style="margin:1.4rem 0 .6rem">Casilla de entrada (rebotes y respuestas)</h3>
        <p class="muted small" style="margin:-.3rem 0 .8rem">Si la dejás vacía se usa la misma cuenta de salida.</p>
        <div class="grid-fields">
            <label class="fld"><span>Servidor</span><input name="imap_host" value="<?= $e2('imap_host', 'imap.gmail.com') ?>"></label>
            <label class="fld"><span>Puerto</span><input type="number" name="imap_port" value="<?= $e2('imap_port', '993') ?>"></label>
            <label class="fld"><span>Usuario</span><input name="imap_user" value="<?= $e2('imap_user') ?>" autocomplete="off"></label>
            <label class="fld"><span>Contraseña</span>
                <input type="password" name="imap_pass" autocomplete="new-password"
                       placeholder="<?= !empty($editar['imap_pass']) ? '•••••• (vacío = no cambiar)' : 'igual que la de salida' ?>">
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
