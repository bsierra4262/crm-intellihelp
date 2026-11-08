<?php /** @var array $rows @var ?string $nuevaClave @var ?string $nuevoSecreto @var ?string $nuevoScope @var bool $escrituraOk */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>API</h1>
        <p class="muted">Claves para que otros sistemas (ITHOS, Jarvis, la web) se conecten con el CRM</p>
    </div>
</div>

<?php if ($nuevaClave): ?>
    <div class="flash flash-success">
        <strong>Copiala ahora:</strong> es la única vez que se muestra completa.<br>
        <code class="mono clave"><?= e($nuevaClave) ?></code>
        <?php if ($nuevoSecreto): ?>
            <br><br><strong>Secreto para firmar los pedidos:</strong> tampoco se vuelve a mostrar.
            Guardalo junto con la clave en la configuración de la web, fuera de la carpeta pública,
            y no lo mandes por correo ni por chat.<br>
            <code class="mono clave"><?= e($nuevoSecreto) ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-head"><h2>Cómo se usa</h2></div>
    <p class="muted small card-intro">
        Hay dos permisos. <strong>Sólo lectura</strong> sirve para consultar el estado del CRM con
        la clave sola. <strong>Escritura (web)</strong> es la que usa intellihelp.tech para mostrar
        los horarios libres, reservar reuniones y dejar las consultas del formulario: además de la
        clave lleva un secreto, y cada pedido va firmado con él.
    </p>

    <h3 class="sub">Sólo lectura</h3>
    <div class="kv">
        <?php
        dato('Dirección', url_abs('api/estado'));
        dato('Encabezado', 'X-API-Key: <tu-clave>');
        dato('Alternativa', 'Authorization: Bearer <tu-clave>');
        dato('Método', 'GET');
        ?>
    </div>
    <p class="muted small mt-3">
        Ejemplo:
        <code class="mono">curl -H "X-API-Key: TU_CLAVE" <?= e(url_abs('api/estado')) ?></code>
    </p>

    <h3 class="sub mt-4">Escritura (web)</h3>
    <div class="kv">
        <?php
        dato('Horarios libres', 'GET ' . url_abs('api/v1/disponibilidad') . '?tipo=<slug>&vendedor=<usuario>&desde=AAAA-MM-DD&hasta=AAAA-MM-DD');
        dato('Reservar', 'POST ' . url_abs('api/v1/reserva'));
        dato('Consulta sin reunión', 'POST ' . url_abs('api/v1/entrada'));
        dato('Encabezados', 'X-ITH-Key · X-ITH-Timestamp · X-ITH-Signature · X-ITH-Idempotency (POST) · X-ITH-Client-IP-Hash (POST)');
        dato('Cuerpo', 'JSON (Content-Type: application/json), hasta 16 KB');
        ?>
    </div>
    <p class="muted small mt-3">
        La firma es <code class="mono">hex(HMAC-SHA256(secreto, hora + "\n" + MÉTODO + "\n" + ruta + "\n" + cuerpo))</code>.
        La hora va en segundos Unix y se acepta con hasta 5 minutos de diferencia. La ruta es la que
        viaja en el pedido, con la carpeta de esta instalación y la consulta incluidas
        (<code class="mono"><?= e((BASE_PATH ?: '') . '/api/v1/reserva') ?></code>): una firma hecha
        para otra instalación no sirve acá. En un GET el cuerpo va vacío.
    </p>
    <p class="muted small">
        La clave de idempotencia es única por formulario: si la web reintenta con la misma, el CRM
        devuelve la respuesta original y no duplica la reunión. El detalle completo está en
        <code class="mono">md/INTEGRACION-WEB.md</code>.
    </p>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Nueva clave</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">
        <div class="filter-grid">
            <label><span>Para qué sistema es</span>
                <input name="nombre" placeholder="Web intellihelp.tech" maxlength="120" required>
            </label>
            <label><span>Permisos</span>
                <select name="scope">
                    <option value="lectura">Sólo lectura</option>
                    <option value="escritura" <?= $escrituraOk ? '' : 'disabled' ?>>Escritura (web)</option>
                </select>
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-sm"><?= icon('clave', 15) ?>Generar la clave</button>
            <span class="hint">
                <?= $escrituraOk
                    ? 'Se muestra una sola vez: copiala en ese momento.'
                    : 'La escritura se habilita cuando la instalación tiene su secreto propio y la base está actualizada.' ?>
            </span>
        </div>
    </form>
</div>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Clave</th><th>Permiso</th><th>Estado</th><th class="num prio-2">Usos</th>
                       <th class="prio-3">Último uso</th><th class="prio-4">Creada por</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="empty">Todavía no hay claves. Se generan para que otro sistema
                    pueda leer los datos del CRM sin usar tu usuario. Si no vas a conectar nada, no hace falta ninguna.</td></tr>
            <?php else: foreach ($rows as $t): ?>
                <?php [$permClase, $permTexto] = match ($t['scope']) {
                    'root'      => ['badge-rej',  'Acceso total'],
                    'escritura' => ['badge-warn', 'Escritura (web)'],
                    default     => ['badge-info', 'Sólo lectura'],
                }; ?>
                <tr>
                    <td data-label="Clave">
                        <div class="cell-main">
                            <strong><?= e($t['nombre']) ?></strong>
                            <small class="mono"><?= e($t['prefijo']) ?>…</small>
                        </div>
                    </td>
                    <td data-label="Permiso">
                        <span class="badge <?= $permClase ?>"><?= e($permTexto) ?></span>
                    </td>
                    <td data-label="Estado">
                        <span class="badge <?= $t['activo'] ? 'badge-ok' : 'badge-neutral' ?>"><?= $t['activo'] ? 'Activa' : 'Dada de baja' ?></span>
                    </td>
                    <td data-label="Usos" class="num prio-2"><?= (int)$t['usos'] ?></td>
                    <td data-label="Último uso" class="prio-3"><?= $t['ultimo_uso'] ? fecha_hora($t['ultimo_uso']) : 'nunca' ?></td>
                    <td data-label="Creada por" class="prio-4"><?= e($t['creador'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <?php if ($t['activo']): ?>
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="baja">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="act danger" title="Dar de baja" data-peligro
                                        data-confirmar-titulo="Dar de baja la clave"
                                        data-confirmar="El sistema que la use va a dejar de tener acceso al instante."
                                        data-confirmar-ok="Dar de baja"><?= icon('x', 16) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
