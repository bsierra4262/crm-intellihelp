<?php
// ============================================================
//  Vista de la conversación con una persona (la renderiza
//  pages/conversacion.php).
//
//  Recibe:
//    · $email    la dirección de la persona (ya normalizada y válida);
//    · $mensajes filas de `mensajes` en orden cronológico, con `usuario`
//                (nombre de quien contestó, en los salientes);
//    · $contacto la ficha del contacto con ese correo, o null;
//    · $ultimo   el último mensaje entrante, al que se contesta, o null;
//    · $limite   cuántos mensajes trae como mucho (los más recientes).
//
//  Cada mensaje se muestra con la cita del original plegada. Si tiene HTML,
//  se dibuja en un iframe aislado; si no, el texto escapado con saltos de
//  línea. El formulario de respuesta postea a la misma página.
// ============================================================
/** @var string $email @var array $mensajes @var ?array $contacto @var ?array $ultimo @var int $limite */
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1><?= e($contacto['nombre_completo'] ?? $email) ?></h1>
        <p class="muted">
            <?= e($email) ?>
            <?php if ($contacto && $contacto['cargo']): ?> · <?= e($contacto['cargo']) ?><?php endif; ?>
            · <?= count($mensajes) ?> <?= count($mensajes) === 1 ? 'mensaje' : 'mensajes' ?>
            <?php if (count($mensajes) >= (int)($limite ?? 100)): ?>
                · se muestran los <?= (int)($limite ?? 100) ?> más recientes
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($contacto): ?>
            <a class="btn btn-ghost" href="<?= e(url('contacto-ver?id=' . (int)$contacto['id'])) ?>">
                <?= icon('contactos', 16) ?>Ver la ficha</a>
        <?php endif; ?>
        <?php // «Volver» lleva a Alertas: es de donde se llega desde el aviso de respuesta. ?>
        <a class="btn btn-ghost" href="<?= e(url('alertas')) ?>">Volver</a>
    </div>
</div>

<div class="card mb-4" data-vivo="hilo">
    <div class="hilo">
        <?php
        // El correo se muestra como lo mandaron (diseño, colores y firma),
        // igual que en un cliente de correo.
        //
        // Va dentro de un marco aislado. Es como lo hacen Gmail y Outlook, y
        // por el mismo motivo: el HTML lo escribió alguien de afuera y no puede
        // pisar los estilos de la pantalla ni ejecutar nada.
        //
        // Tres capas:
        //   1. mensaje_html_seguro() reconstruye el HTML con una lista blanca;
        //   2. el sandbox va SIN `allow-same-origin`: el marco tiene un origen
        //      propio y opaco, no puede leer ni tocar nada del CRM (ni sus
        //      cookies, ni la pantalla que lo rodea) aunque algo se colara;
        //   3. adentro, una política de contenido (CSP) que sólo deja correr
        //      el script propio que lleva el nonce de este pedido: ni un
        //      script del correo, ni un on*, ni un enlace javascript: corren.
        //
        // `allow-scripts` está SOLO para ese script propio, que mide el alto
        // del contenido y se lo pasa a la pantalla con postMessage (app.js lo
        // recibe, comprueba que venga de uno de estos marcos y ajusta el
        // alto). Sin allow-same-origin, eso no le da acceso al CRM. Si el
        // script no llegara a correr, el marco queda con el alto del CSS y el
        // correo se lee con scroll adentro.
        //
        // `allow-popups` y `allow-popups-to-escape-sandbox` dejan que los
        // enlaces se abran en otra pestaña (por el <base target="_blank"> y el
        // target que pone el saneado) sin las restricciones del sandbox; el
        // saneado les agrega rel="noopener noreferrer".
        //
        // $marco($html, $id) imprime el iframe; $id identifica el marco en el
        // mensaje del alto.
        $nonce = bin2hex(random_bytes(12));
        $marco = function (string $html, string $id) use ($nonce): void {
            $csp = "default-src 'none'; img-src http: https: data: cid:; style-src 'unsafe-inline'; "
                 . "font-src http: https: data:; script-src 'nonce-" . $nonce . "'; form-action 'none'";
            $medir = '(function(){var id=' . json_encode($id) . ';'
                   . 'function m(){try{parent.postMessage({crmMarco:id,alto:document.documentElement.scrollHeight},"*");}catch(e){}}'
                   . 'addEventListener("load",m);addEventListener("resize",m);'
                   . 'if(window.ResizeObserver){new ResizeObserver(m).observe(document.documentElement);}'
                   . 'm();})();';
            $doc = '<!doctype html><meta charset="utf-8">'
                 . '<meta http-equiv="Content-Security-Policy" content="' . e($csp) . '">'
                 . '<meta name="referrer" content="no-referrer">'
                 . '<base target="_blank">'
                 . '<style>html,body{margin:0;padding:0}'
                 . 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#2a2733;'
                 . 'word-break:break-word;overflow-wrap:anywhere}'
                 . 'img{max-width:100%;height:auto}table{max-width:100%}'
                 . 'a{color:#663399}</style>'
                 . mensaje_html_seguro($html)
                 . '<script nonce="' . $nonce . '">' . $medir . '</script>';
            echo '<iframe class="msg-marco" data-marco="' . e($id) . '" title="Contenido del mensaje" loading="lazy"'
               . ' sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"'
               . ' srcdoc="' . e($doc) . '"></iframe>';
        };
        ?>
        <?php foreach ($mensajes as $i => $m):
            // Si hay HTML manda el HTML y la cita se corta con la versión
            // HTML; si no, el texto plano con la de texto.
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

<?php // Sin mensaje entrante no hay a quién contestar: no se muestra el formulario. ?>
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
