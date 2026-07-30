<?php
/** @var array $p @var array $notas @var array $actividades @var array $linea */
$nombre = trim(($p['nombre'] ?? '') . ' ' . $p['apellido']);
$dato = function (string $etiqueta, $valor, string $tipo = 'texto') {
    $v = trim((string)$valor);
    echo '<div><div class="k">' . e($etiqueta) . '</div><div class="v' . ($v === '' ? ' empty' : '') . '">';
    if ($v === '')            { echo '—'; }
    elseif ($tipo === 'mail') { echo '<a href="mailto:' . e($v) . '">' . e($v) . '</a>'; }
    elseif ($tipo === 'tel')  { echo '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $v)) . '">' . e($v) . '</a>'; }
    elseif ($tipo === 'url')  { $h = preg_match('#^https?://#i', $v) ? $v : 'https://' . $v;
                                echo '<a href="' . e($h) . '" target="_blank" rel="noopener">' . e(recortar($v, 40)) . '</a>'; }
    else                      { echo e($v); }
    echo '</div></div>';
};
?>
<div class="detail-head">
    <span class="avatar-fallback"><?= e(iniciales($nombre)) ?></span>
    <div style="flex:1;min-width:0">
        <h1><?= e($nombre) ?></h1>
        <div class="sub"><?= e(implode(' · ', array_filter([$p['cargo'], $p['empresa']]))) ?></div>
        <div style="margin-top:.4rem;display:flex;gap:.4rem;flex-wrap:wrap">
            <span class="badge badge-info"><?= e($p['estado']) ?></span>
            <?php if ($p['calificacion']): ?><span class="badge badge-pend"><?= e($p['calificacion']) ?></span><?php endif; ?>
            <?php if ($p['fuente']): ?><span class="badge badge-neutral"><?= e($p['fuente']) ?></span><?php endif; ?>
            <?php if ($p['convertido_en']): ?><span class="badge badge-ok">Convertido el <?= fecha_corta($p['convertido_en']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <?php if (!$p['convertido_en']): ?>
            <a class="btn btn-accent" href="<?= e(url('posible-convertir?id=' . (int)$p['id'])) ?>"><?= icon('convertir', 16) ?>Convertir</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('actividad-form?posible_cliente_id=' . (int)$p['id'])) ?>"><?= icon('tareas', 16) ?>Nueva actividad</a>
        <a class="btn btn-primary" href="<?= e(url('posible-form?id=' . (int)$p['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<?php if ($p['convertido_en']): ?>
    <div class="flash flash-success">
        Este posible cliente ya se convirtió.
        <?php if ($p['cuenta_id']): ?> Empresa: <a href="<?= e(url('cuenta-ver?id=' . (int)$p['cuenta_id'])) ?>">ver</a>.<?php endif; ?>
        <?php if ($p['contacto_id']): ?> Contacto: <a href="<?= e(url('contacto-ver?id=' . (int)$p['contacto_id'])) ?>">ver</a>.<?php endif; ?>
        <?php if ($p['negocio_id']): ?> Negocio: <a href="<?= e(url('negocio-ver?id=' . (int)$p['negocio_id'])) ?>">ver</a>.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="detail">
    <div style="display:flex;flex-direction:column;gap:1rem;min-width:0">
        <?php if (!$p['convertido_en']): ?>
        <div class="card">
            <div class="card-head"><h2>Avanzar el estado</h2></div>
            <form method="post" class="urow">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="estado">
                <select name="estado" style="min-width:220px">
                    <?php foreach (estados_posible_cliente() as $x): ?>
                        <option value="<?= e($x) ?>" <?= $p['estado'] === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm"><?= icon('ok', 15) ?>Actualizar</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head"><h2>Datos</h2></div>
            <div class="kv">
                <?php
                $dato('Empresa', $p['empresa']);
                $dato('Cargo', $p['cargo']);
                $dato('Correo', $p['email'], 'mail');
                $dato('Correo secundario', $p['email2'], 'mail');
                $dato('Teléfono directo', $p['telefono'], 'tel');
                $dato('Móvil', $p['movil'], 'tel');
                $dato('Conmutador', $p['conmutador'], 'tel');
                $dato('Sitio web', $p['sitio_web'], 'url');
                $dato('LinkedIn', $p['linkedin'], 'url');
                $dato('Sector', $p['sector']);
                $dato('Empleados', $p['empleados']);
                $dato('Propietario', $p['propietario']);
                $dato('Localidad', $p['localidad']);
                $dato('Provincia', $p['provincia']);
                $dato('País', $p['pais']);
                $dato('Última actividad', $p['ultima_actividad_en'] ? tiempo_relativo($p['ultima_actividad_en']) : '');
                ?>
            </div>
        </div>

        <?php if (trim((string)$p['descripcion']) !== ''): ?>
        <div class="card">
            <div class="card-head"><h2>Notas del registro</h2></div>
            <p style="white-space:pre-wrap;margin:0"><?= e($p['descripcion']) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:1rem;min-width:0">
        <?php include APP . '/views/_notas.php'; ?>
        <?php include APP . '/views/_actividades_panel.php'; ?>
        <div class="card">
            <div class="card-head"><h2>Historial</h2></div>
            <?php if (!$linea): ?>
                <p class="muted small">Sin movimientos registrados.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($linea as $x): ?>
                        <div class="tl-item <?= e($x['tipo']) ?>">
                            <div class="tl-when"><?= fecha_hora($x['cuando']) ?> · <?= e($x['actor'] ?: 'sistema') ?></div>
                            <div class="tl-title"><?= e($x['titulo']) ?></div>
                            <?php if ($x['detalle']): ?><div class="tl-detail"><?= e($x['detalle']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
