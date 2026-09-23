<?php /** @var ?array $resultado @var ?string $error @var array $usuarios @var array $listas
 *  @var array $lotes @var string $maxSubida */ ?>
<div class="page-head">
    <div>
        <h1>Importar datos</h1>
        <p class="muted">Traer la base de Zoho CRM o cargar una lista de correos</p>
    </div>
</div>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($resultado): ?>
    <div class="flash <?= !empty($resultado['simulado']) ? 'flash-info' : 'flash-success' ?>">
        <?php if (($resultado['modo'] ?? '') === 'lista'): ?>
            Se leyeron <?= (int)$resultado['filas'] ?> filas:
            <strong><?= (int)$resultado['agregados'] ?></strong> correos agregados,
            <?= (int)$resultado['omitidos'] ?> repetidos o inválidos.
        <?php else: ?>
            <?= !empty($resultado['simulado']) ? '<strong>Simulación</strong> (no se guardó nada): ' : '' ?>
            <?= (int)$resultado['filas'] ?> filas leídas ·
            <strong><?= (int)$resultado['creados'] ?></strong> contactos nuevos ·
            <?= (int)$resultado['actualizados'] ?> actualizados ·
            <?= (int)$resultado['cuentas_nuevas'] ?> empresas nuevas ·
            <?= (int)$resultado['omitidos'] ?> omitidos ·
            <?= (int)$resultado['errores'] ?> errores.
        <?php endif; ?>
    </div>
    <?php if (!empty($resultado['detalle_omitidos'])): ?>
        <div class="card mb-4">
            <div class="card-head"><h2>Qué se omitió y por qué</h2></div>
            <?php foreach ($resultado['detalle_omitidos'] as $motivo => $n): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= e($motivo) ?></span>
                    <span class="bar-track"><i class="bar-fill" style="width:<?= min(100, round(100 * $n / max(1, (int)$resultado['filas']))) ?>%"></i></span>
                    <span class="bar-val"><?= (int)$n ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Contactos de Zoho CRM</h2>
        <p class="muted small card-intro">
            Subí el CSV tal como sale del exportador de Zoho. El sistema:
            crea las <strong>empresas únicas</strong> y les engancha los contactos (no las duplica),
            normaliza <strong>provincia, país y localidad</strong>, descarta la basura de los campos mal
            cargados y unifica <strong>Fax</strong> con <strong>Conmutador</strong> cuando traen el mismo número.
            Si volvés a subir el mismo archivo, <strong>actualiza</strong> en vez de duplicar.
        </p>
        <div class="grid-fields">
            <div class="fld wide"><span>Archivo CSV</span>
                <label class="filefield">
                    <input type="file" name="archivo" accept=".csv,text/csv" required>
                    <span class="btn btn-ghost btn-sm"><?= icon('subir', 15) ?>Elegir archivo</span>
                    <span class="nombre">Ningún archivo seleccionado</span>
                </label>
                <i class="hint">Tamaño máximo del servidor: <?= e($maxSubida) ?>.</i>
            </div>
            <label class="fld"><span>Qué importar</span>
            <?php /* Importar a una lista sólo tiene sentido con las campañas en
                     marcha: ofrecerlo apagado sería prometer un destino al que
                     el archivo no puede llegar. */
            $conCampanas = ruta_habilitada('campanas'); ?>
            <label class="fld"><span>Qué importar</span>
                <select name="destino">
                    <option value="contactos">Contactos y empresas</option>
                    <?php if ($conCampanas): ?>
                        <option value="lista">Correos para una lista de campaña</option>
                    <?php endif; ?>
                </select>
                <i class="hint">
                    <?php if ($conCampanas): ?>
                        «Contactos y empresas» carga la ficha completa. «Correos a una lista» sólo suma direcciones a una lista de campaña.
                    <?php else: ?>
                        Carga la ficha completa: nombre, apellido, correo, teléfono, empresa y dirección.
                    <?php endif; ?>
                </i>
            </label>
            <?php if ($conCampanas): ?>
            <label class="fld"><span>Lista destino (si corresponde)</span>
                <select name="lista_id">
                    <option value="">— Ninguna —</option>
                    <?php foreach ($listas as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"><?= e($l['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">A qué lista van los correos. Sólo hace falta si elegiste ese destino.</i>
            </label>
            <?php endif; ?>
            <label class="fld"><span>Propietario por defecto</span>
                <select name="propietario_id">
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)current_user_id() ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">
                    Si el archivo trae un propietario que existe como usuario del CRM, manda ese.
                    Para el resto se usa éste. <strong>Nunca quedan sin dueño</strong>: un contacto
                    sin propietario no aparece en «Lo mío» de nadie.
                </i>
            </label>
            <div class="fld wide abajo">
                <label class="switch">
                    <input type="checkbox" name="simular" value="1" checked>
                    <span class="track"></span>
                    <span>Simular primero (no guarda nada)</span>
                </label>
            </div>
        </div>
    </div>

    <?php /* Importar unos miles de filas lleva su tiempo y la pantalla se queda
             quieta: sin nada que lo indique, la reacción natural es apretar de
             nuevo —y ahí se importa dos veces— o cerrar la pestaña a la mitad.
             El aviso lo arma el script; acá sólo se marca el formulario. */ ?>
    <div class="form-actions">
        <button class="btn btn-primary" data-cargando="Importando el archivo…"
                data-cargando-detalle="Estamos leyendo cada fila y cruzándola con lo que ya está cargado. No cierres esta pestaña.">
            <?= icon('importar', 16) ?>Procesar archivo
        </button>
    </div>
</form>

<?php if ($lotes): ?>
<div class="card mt-4">
    <div class="card-head"><h2>Importaciones anteriores</h2></div>
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Cuándo</th><th>Archivo</th><th class="num">Filas</th><th class="num">Nuevos</th>
                       <th class="num">Actualizados</th><th class="num">Errores</th><th class="prio-3">Quién</th></tr></thead>
            <tbody>
            <?php foreach ($lotes as $l): ?>
                <tr>
                    <td data-label="Cuándo"><?= fecha_hora($l['creado_en']) ?></td>
                    <td data-label="Archivo"><?= e(recortar($l['archivo'], 34)) ?></td>
                    <td data-label="Filas" class="num"><?= (int)$l['filas'] ?></td>
                    <td data-label="Nuevos" class="num strong"><?= (int)$l['creados'] ?></td>
                    <td data-label="Actualizados" class="num"><?= (int)$l['actualizados'] ?></td>
                    <td data-label="Errores" class="num"><?= (int)$l['errores'] ?></td>
                    <td data-label="Quién" class="prio-3"><?= e($l['usuario'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
