<?php
/** @var array $c @var array $contactos @var array $negocios @var array $notas
 *  @var array $actividades @var array $linea */
$abierto = 0.0; $ganado = 0.0;
foreach ($negocios as $n) {
    if ($n['etapa_tipo'] === 'abierta') { $abierto += (float)$n['importe']; }
    if ($n['etapa_tipo'] === 'ganada')  { $ganado  += (float)$n['importe']; }
}
?>
<div class="detail-head">
    <span class="avatar-fallback"><?= e(iniciales($c['nombre'])) ?></span>
    <div class="grow">
        <h1><?= e($c['nombre']) ?></h1>
        <div class="sub">
            <?= e(implode(' · ', array_filter([$c['tipo'], $c['sector'], $c['localidad'], $c['provincia']]))) ?: 'Sin datos adicionales' ?>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('contacto-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('contactos', 16) ?>Nuevo contacto</a>
        <a class="btn btn-ghost" href="<?= e(url('negocio-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('negocios', 16) ?>Nuevo negocio</a>
        <a class="btn btn-primary" href="<?= e(url('cuenta-form?id=' . (int)$c['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Contactos</span>
        <span class="stat-value"><?= count($contactos) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Embudo abierto</span>
        <span class="stat-value"><?= money($abierto) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Ganado histórico</span>
        <span class="stat-value accent"><?= money($ganado) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Alta</span>
        <span class="stat-value sm"><?= fecha_corta($c['creado_en']) ?></span>
    </div>
</div>

<div class="detail">
    <div class="col-stack">
        <div class="card">
            <div class="card-head"><h2>Datos de la empresa</h2></div>
            <div class="kv">
                <?php
                dato('CUIT', $c['cuit'] ? formatear_cuit($c['cuit']) : '');
                dato('Condición IVA', $c['condicion_iva']);
                dato('Teléfono', $c['telefono'], 'tel');
                dato('Correo', $c['email'], 'mail');
                dato('Sitio web', $c['sitio_web'], 'url');
                dato('LinkedIn', $c['linkedin'], 'url');
                dato('Empleados', $c['empleados']);
                dato('Propietario', $c['propietario']);
                dato('Domicilio', $c['calle']);
                dato('Localidad', $c['localidad']);
                dato('Provincia', $c['provincia']);
                dato('País', $c['pais']);
                ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Contactos</h2>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('contacto-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('mas', 14) ?>Agregar</a>
            </div>
            <?php if (!$contactos): ?>
                <p class="muted small">Esta empresa todavía no tiene contactos cargados.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="grid">
                        <thead><tr><th>Persona</th><th>Cargo</th><th>Correo</th><th class="prio-2">Teléfono</th></tr></thead>
                        <tbody>
                        <?php foreach ($contactos as $p): ?>
                            <tr>
                                <td data-label="Persona"><a href="<?= e(url('contacto-ver?id=' . (int)$p['id'])) ?>"><strong><?= e($p['nombre_completo']) ?></strong></a></td>
                                <td data-label="Cargo"><?= e(recortar($p['cargo'], 30) ?: '—') ?></td>
                                <td data-label="Correo"><?= $p['email'] ? '<a href="mailto:' . e($p['email']) . '">' . e(recortar($p['email'], 28)) . '</a>' : '—' ?></td>
                                <td data-label="Teléfono" class="prio-2"><?= e(telefono_formato($p['telefono'] ?: $p['movil']) ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Negocios</h2>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('negocio-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('mas', 14) ?>Agregar</a>
            </div>
            <?php if (!$negocios): ?>
                <p class="muted small">Sin oportunidades registradas.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="grid">
                        <thead><tr><th>Negocio</th><th>Etapa</th><th class="num">Importe</th><th>Cierre</th></tr></thead>
                        <tbody>
                        <?php foreach ($negocios as $n): ?>
                            <tr>
                                <td data-label="Negocio"><a href="<?= e(url('negocio-ver?id=' . (int)$n['id'])) ?>"><?= e($n['nombre']) ?></a></td>
                                <td data-label="Etapa"><span class="chip" style="background:<?= e($n['color']) ?>1f;color:<?= e($n['color']) ?>"><?= e($n['etapa']) ?></span></td>
                                <td data-label="Importe" class="num"><?= money((float)$n['importe'], $n['moneda']) ?></td>
                                <td data-label="Cierre"><?= fecha_corta($n['fecha_cierre']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-stack">

<?php $etqEntidad = 'cuenta'; $etqId = (int)$c['id'];
      include APP . '/views/_etiquetas.php'; ?>
        <?php $adjEntidad = 'cuenta'; $adjId = (int)$c['id'];
      include APP . '/views/_adjuntos.php'; ?>
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
