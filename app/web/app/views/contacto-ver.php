<?php
/** @var array $c @var array $notas @var array $actividades @var array $negocios
 *  @var bool $verNegocios @var bool $verActividades @var bool $verCampanas @var bool $verAdjuntos
 *  @var array $envios @var array $linea */
?>
<div class="detail-head">
    <span class="avatar-fallback"><?= e(iniciales($c['nombre_completo'])) ?></span>
    <div class="grow">
        <h1><?= e($c['nombre_completo']) ?></h1>
        <div class="sub">
            <?= e($c['cargo'] ?: 'Sin cargo cargado') ?>
            <?php if ($c['cuenta_id']): ?>
                · <a href="<?= e(url('cuenta-ver?id=' . (int)$c['cuenta_id'])) ?>"><?= e($c['cuenta']) ?></a>
            <?php endif; ?>
        </div>
        <div class="tag-row">
            <?php if ($c['no_email']): ?><span class="badge badge-rej">Dado de baja</span><?php endif; ?>
            <?php if ($c['email_rebotado']): ?><span class="badge badge-pend">Correo rebotado</span><?php endif; ?>
            <?php if ($c['fuente']): ?><span class="badge badge-info"><?= e($c['fuente']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <?php if ($c['no_email'] && auth_is_admin()): ?>
            <form class="inline-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="reactivar">
                <button class="btn btn-ghost"
                        data-confirmar-titulo="Volver a habilitar"
                        data-confirmar="<?= e($c['email']) ?> se había dado de baja. Al habilitarlo vuelve a recibir campañas. Hacelo sólo si te lo pidió."
                        data-confirmar-ok="Habilitar"><?= icon('ok', 16) ?>Volver a habilitar</button>
            </form>
        <?php endif; ?>
        <?php if ($verActividades): ?>
            <a class="btn btn-ghost" href="<?= e(url('actividad-form?contacto_id=' . (int)$c['id'])) ?>"><?= icon('tareas', 16) ?>Nueva actividad</a>
        <?php endif; ?>
        <?php if ($verNegocios): ?>
            <a class="btn btn-ghost" href="<?= e(url('negocio-form?contacto_id=' . (int)$c['id'])) ?>"><?= icon('negocios', 16) ?>Nuevo negocio</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('contacto-form?id=' . (int)$c['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<div class="detail">
    <div class="col-stack">
        <div class="card">
            <div class="card-head"><h2>Datos de contacto</h2></div>
            <div class="kv">
                <?php
                dato('Correo principal', $c['email'], 'mail');
                dato('Correo secundario', $c['email2'], 'mail');
                dato('Correo terciario', $c['email3'], 'mail');
                dato('Teléfono directo', $c['telefono'], 'tel');
                dato('Móvil', $c['movil'], 'tel');
                dato('Conmutador', $c['conmutador'], 'tel');
                dato('LinkedIn', $c['linkedin'], 'url');
                dato('Sitio web', $c['sitio_web'], 'url');
                dato('Departamento', $c['departamento']);
                dato('Propietario', $c['propietario']);
                ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Ubicación</h2></div>
            <div class="kv">
                <?php
                dato('Domicilio', $c['calle']);
                dato('Localidad', $c['localidad']);
                dato('Provincia', $c['provincia']);
                dato('Código postal', $c['codigo_postal']);
                dato('País', $c['pais']);
                ?>
            </div>
        </div>

        <?php if (trim((string)$c['descripcion']) !== ''): ?>
        <div class="card">
            <div class="card-head"><h2>Notas del registro</h2></div>
            <p class="pre-wrap m-0"><?= e($c['descripcion']) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($verNegocios): ?>
        <div class="card">
            <div class="card-head"><h2>Negocios</h2>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('negocio-form?contacto_id=' . (int)$c['id'])) ?>"><?= icon('mas', 14) ?>Agregar</a>
            </div>
            <?php if (!$negocios): ?>
                <p class="muted small">Todavía no hay oportunidades asociadas.</p>
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

        <?php if ($verCampanas && $envios): ?>
        <div class="card">
            <div class="card-head"><h2>Campañas recibidas</h2></div>
            <div class="table-scroll">
                <table class="grid">
                    <thead><tr><th>Campaña</th><th>Estado</th><th>Enviado</th><th>Respondió</th></tr></thead>
                    <tbody>
                    <?php foreach ($envios as $en): ?>
                        <tr>
                            <td data-label="Campaña"><a href="<?= e(url('campana-ver?id=' . (int)$en['campana_id'])) ?>"><?= e($en['campana']) ?></a></td>
                            <td data-label="Estado"><?= badge_envio($en['estado']) ?></td>
                            <td data-label="Enviado"><?= fecha_hora($en['enviado_en']) ?></td>
                            <td data-label="Respondió"><?= $en['respondido_en'] ? fecha_hora($en['respondido_en']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-stack">

<?php $etqEntidad = 'contacto'; $etqId = (int)$c['id'];
      include APP . '/views/_etiquetas.php'; ?>
        <?php if ($verAdjuntos): $adjEntidad = 'contacto'; $adjId = (int)$c['id'];
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
