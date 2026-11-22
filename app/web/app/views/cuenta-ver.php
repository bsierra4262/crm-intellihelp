<?php
// ============================================================
//  Vista: ficha de una empresa.
//
//  La dibuja pages/cuenta-ver.php. Arriba, las tarjetas de resumen
//  (contactos, embudo abierto, ganado, alta); a la izquierda los datos, los
//  contactos y los negocios; a la derecha etiquetas, adjuntos, notas,
//  actividades e historial.
//
//  Variables que recibe:
//    $c            array  cuenta_get() (trae `propietario`)
//    $contactos    array  contactos de la empresa, sin eliminar
//    $negocios     array  negocios con etapa (`etapa_tipo`: abierta/ganada/perdida)
//    $notas        array  notas_de('cuenta', id)
//    $actividades  array  actividades_de('cuenta_id', id)
//    $linea        array  timeline('cuenta', id): sólo auditoría
//    $verNegocios, $verActividades, $verAdjuntos  bool  módulos encendidos
//
//  Las tarjetas de embudo abierto y ganado sólo salen con el módulo de
//  negocios encendido, y suman cada moneda por separado
//  (cuenta_totales_negocios()): no se convierte de dólares a pesos.
// ============================================================
/** @var array $c @var array $contactos @var array $negocios @var array $notas
 *  @var array $actividades @var array $linea */
// Totales de las tarjetas, separados por moneda. Sin negocios, «$ 0,00».
$totales = cuenta_totales_negocios($negocios);
$porMoneda = static function (array $t): string {
    if (!$t) { return e(money(0)); }
    return implode('<br>', array_map(fn($mon, $v) => e(money($v, $mon)), array_keys($t), $t));
};
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
        <?php // contacto-form toma `cuenta_id` de la dirección: la empresa llega elegida. ?>
        <a class="btn btn-ghost" href="<?= e(url('contacto-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('contactos', 16) ?>Nuevo contacto</a>
        <?php if ($verNegocios): ?>
            <a class="btn btn-ghost" href="<?= e(url('negocio-form?cuenta_id=' . (int)$c['id'])) ?>"><?= icon('negocios', 16) ?>Nuevo negocio</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('cuenta-form?id=' . (int)$c['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Contactos</span>
        <span class="stat-value"><?= count($contactos) ?></span>
    </div>
    <?php if ($verNegocios): ?>
    <div class="card stat">
        <span class="stat-label">Embudo abierto</span>
        <span class="stat-value"><?= $porMoneda($totales['abierto']) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Ganado histórico</span>
        <span class="stat-value accent"><?= $porMoneda($totales['ganado']) ?></span>
    </div>
    <?php endif; ?>
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
                dato('Código postal', $c['codigo_postal']);
                dato('Provincia', $c['provincia']);
                dato('País', $c['pais']);
                ?>
            </div>
        </div>

        <?php if (trim((string)($c['descripcion'] ?? '')) !== ''): ?>
        <div class="card">
            <div class="card-head"><h2>Notas de la empresa</h2></div>
            <p class="pre-wrap m-0"><?= e($c['descripcion']) ?></p>
        </div>
        <?php endif; ?>

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

        <?php if ($verNegocios): ?>
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
        <?php endif; ?>
    </div>

    <div class="col-stack">

<?php // Los parciales toman la entidad y el id de estas variables del ámbito de la vista. ?>
<?php $etqEntidad = 'cuenta'; $etqId = (int)$c['id'];
      include APP . '/views/_etiquetas.php'; ?>
        <?php if ($verAdjuntos): $adjEntidad = 'cuenta'; $adjId = (int)$c['id'];
            include APP . '/views/_adjuntos.php';
        endif; ?>
        <?php include APP . '/views/_notas.php'; ?>
        <?php if ($verActividades): include APP . '/views/_actividades_panel.php'; endif; ?>
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
