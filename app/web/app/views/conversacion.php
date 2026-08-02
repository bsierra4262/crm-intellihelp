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
        <?php foreach ($mensajes as $m):
            $suyo  = $m['direccion'] === 'entrante';
            $texto = (string)($m['cuerpo_texto'] ?? '');
            // Si sólo vino en HTML, se saca el texto para poder leerlo sin
            // meter el HTML de otro adentro de nuestra pantalla.
            if (trim($texto) === '' && trim((string)$m['cuerpo_html']) !== '') {
                $texto = trim(html_entity_decode(strip_tags((string)$m['cuerpo_html']), ENT_QUOTES, 'UTF-8'));
            }
            $partes = mensaje_partir_cita($texto);
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
                <div class="msg-cuerpo"><?= nl2br(e($partes['limpio'])) ?></div>
                <?php if ($partes['citado'] !== ''): ?>
                    <?php /* La cita del mensaje original va plegada: si no, una
                             respuesta de dos líneas queda enterrada bajo la
                             campaña entera. */ ?>
                    <details class="msg-cita">
                        <summary>Mostrar el mensaje anterior</summary>
                        <div><?= nl2br(e($partes['citado'])) ?></div>
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
