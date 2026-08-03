<?php /** @var string $email @var array $mensajes @var ?array $contacto @var ?array $ultimo */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1><?= e($contacto['nombre_completo'] ?? $email) ?></h1>
        <p class="muted">
            <?= e($email) ?>
            <?php if ($contacto && $contacto['cargo']): ?> · <?= e($contacto['cargo']) ?><?php endif; ?>
            · <?= count($mensajes) ?> <?= count($mensajes) === 1 ? 'mensaje' : 'mensajes' ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($contacto): ?>
            <a class="btn btn-ghost" href="<?= e(url('contacto-ver?id=' . (int)$contacto['id'])) ?>">
                <?= icon('contactos', 16) ?>Ver la ficha</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('alertas')) ?>">Volver</a>
    </div>
</div>

<div class="card mb-4" data-vivo="hilo">
    <div class="hilo">
        <?php
        // El correo se muestra como lo mandaron: con su diseño, sus colores y
        // su firma, igual que en el cliente de correo. Antes se le sacaban las
        // etiquetas y quedaba una pared de texto con la cita en crudo, línea
        // por línea con «>» adelante, que es justo lo que ningún cliente de
        // correo muestra así.
        //
        // Va dentro de un marco aislado. Es como lo hacen Gmail y Outlook, y
        // por el mismo motivo: el HTML lo escribió alguien de afuera y no puede
        // pisar los estilos de la pantalla ni ejecutar nada. El marco va sin
        // permiso de scripts, así que aunque quedara alguno, no corre.
        $marco = function (string $html, string $id): void {
            $doc = '<!doctype html><meta charset="utf-8">'
                 . '<base target="_blank" rel="noopener">'
                 . '<style>html,body{margin:0;padding:0}'
                 . 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#2a2733;'
                 . 'word-break:break-word;overflow-wrap:anywhere}'
                 . 'img{max-width:100%;height:auto}table{max-width:100%}'
                 . 'a{color:#663399}</style>'
                 . mensaje_html_seguro($html);
            echo '<iframe class="msg-marco" data-marco="' . e($id) . '" title="Contenido del mensaje" loading="lazy"'
               . ' sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"'
               . ' srcdoc="' . e($doc) . '"></iframe>';
        };
        ?>
        <?php foreach ($mensajes as $i => $m):
            $suyo = $m['direccion'] === 'entrante';
            $html = trim((string)($m['cuerpo_html'] ?? ''));
            $partes = $html !== ''
                ? mensaje_partir_cita_html($html)
                : mensaje_partir_cita((string)($m['cuerpo_texto'] ?? ''));
        ?>
            <article class="msg <?= $suyo ? 'msg-in' : 'msg-out' ?>">
                <header class="msg-cab">
                    <strong><?= e($suyo ? ($m['de_nombre'] ?: $m['de']) : ($m['usuario'] ?: 'Vos')) ?></strong>
                    <span class="muted small"><?= e($suyo ? $m['de'] : 'a ' . $m['para']) ?></span>
                    <time class="muted small" title="<?= e(fecha_hora($m['fecha'])) ?>"><?= e(tiempo_relativo($m['fecha'])) ?></time>
                </header>
                <?php if ($m['asunto']): ?>
                    <p class="msg-asunto"><?= e($m['asunto']) ?></p>
                <?php endif; ?>

                <?php if ($html !== ''): ?>
                    <?php $marco($partes['limpio'], 'm' . (int)$m['id']); ?>
                <?php else: ?>
                    <div class="msg-cuerpo"><?= nl2br(e($partes['limpio'])) ?></div>
                <?php endif; ?>

                <?php if (trim((string)$partes['citado']) !== ''): ?>
                    <?php /* La cita del mensaje original va plegada: si no, una
                             respuesta de dos líneas queda enterrada bajo la
                             campaña entera. */ ?>
                    <details class="msg-cita">
                        <summary>Mostrar el mensaje anterior</summary>
                        <?php if ($html !== ''): ?>
                            <?php $marco($partes['citado'], 'c' . (int)$m['id']); ?>
                        <?php else: ?>
                            <div><?= nl2br(e($partes['citado'])) ?></div>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($ultimo): ?>
    <div class="card">
        <div class="card-head"><h2>Responder</h2></div>
        <p class="muted small card-intro">
            Sale desde la misma casilla que recibió el mensaje y le llega pegado a esta
            conversación, no como un correo suelto. Lo que contestes queda guardado acá:
            el que atienda mañana va a ver todo lo que se habló.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($email) ?>">
            <input type="hidden" name="mensaje_id" value="<?= (int)$ultimo['id'] ?>">
            <label class="fld">
                <textarea name="texto" rows="7" required autofocus
                          placeholder="Escribí tu respuesta…"></textarea>
                <i class="hint">Se manda tal cual lo escribas. Los saltos de línea se respetan.</i>
            </label>
            <div class="mt-3">
                <button class="btn btn-primary"><?= icon('campanas', 16) ?>Enviar respuesta</button>
            </div>
        </form>
    </div>
<?php endif; ?>
