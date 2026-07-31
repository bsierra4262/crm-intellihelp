<?php
declare(strict_types=1);

// ============================================================
//  Motor de bloques del editor de campañas.
//
//  El usuario arma el correo con bloques (encabezado, título, texto, imagen,
//  botón…) y acá se traduce a HTML de correo. La traducción NO es libre: el
//  HTML de un correo no es el de una página web y hay reglas que no se pueden
//  negociar sin perder el mensaje en el camino:
//
//   · **Todo con tablas.** Outlook usa el motor de Word: ignora flexbox, grid
//     y `border-radius`. Un diseño con `div` + flex se ve destruido.
//   · **CSS en línea.** Gmail sanea el correo y descarta buena parte de las
//     reglas de una hoja de estilos.
//   · **Menos de 102 KB.** Pasado ese tamaño Gmail recorta el mensaje y
//     muestra «mensaje recortado»: el destinatario nunca ve el botón final.
//   · **Fuentes seguras.** Las fuentes web no cargan en la mayoría de los
//     clientes; se declara la familia con respaldo a Arial.
//   · **Sin imágenes de fondo, sin JavaScript, sin formularios, sin video.**
//     No funcionan y además suben el puntaje de spam.
//
//  Por eso el editor ofrece sólo lo que sobrevive en el correo: es más
//  limitado que un editor de páginas a propósito.
// ============================================================

/** Ancho del correo. 600 px es el estándar que entra en todos los clientes. */
const CORREO_ANCHO = 600;

/** Familia tipográfica: siempre con respaldo a una fuente segura. */
const CORREO_FUENTE = "'Saira', Arial, Helvetica, sans-serif";

/** Catálogo de bloques disponibles en el editor. */
function bloques_tipos(): array
{
    return [
        'encabezado' => ['nombre' => 'Encabezado con logo', 'icono' => 'plantillas',
                         'ayuda'  => 'El logo arriba y, opcionalmente, el sitio web a la derecha.'],
        'hero'       => ['nombre' => 'Portada de color',     'icono' => 'panel',
                         'ayuda'  => 'Banda de color con volanta, título grande y bajada.'],
        'titulo'     => ['nombre' => 'Título',               'icono' => 'plantillas',
                         'ayuda'  => 'Un título de sección.'],
        'texto'      => ['nombre' => 'Párrafo',              'icono' => 'nota',
                         'ayuda'  => 'Texto corrido. Acepta variables como {{nombre}}.'],
        'lista'      => ['nombre' => 'Lista de puntos',      'icono' => 'listas',
                         'ayuda'  => 'Viñetas para enumerar beneficios o servicios.'],
        'imagen'     => ['nombre' => 'Imagen',               'icono' => 'subir',
                         'ayuda'  => 'Una imagen con ancho y alineación. Siempre con texto alternativo.'],
        'boton'      => ['nombre' => 'Botón',                'icono' => 'enlace',
                         'ayuda'  => 'Llamado a la acción. Se dibuja para que también se vea en Outlook.'],
        'destacado'  => ['nombre' => 'Recuadro destacado',   'icono' => 'alerta',
                         'ayuda'  => 'Bloque con fondo suave para resaltar una idea.'],
        'contacto'   => ['nombre' => 'Datos de contacto',    'icono' => 'contactos',
                         'ayuda'  => 'Web, teléfono, WhatsApp y correo en un recuadro.'],
        'firma'      => ['nombre' => 'Firma',                'icono' => 'editar',
                         'ayuda'  => 'El cierre y la firma de quien manda.'],
        'separador'  => ['nombre' => 'Separador',            'icono' => 'menu',
                         'ayuda'  => 'Una línea fina para separar secciones.'],
        'espacio'    => ['nombre' => 'Espacio',              'icono' => 'menu',
                         'ayuda'  => 'Aire entre bloques.'],
    ];
}

/** Ajustes generales del correo, con sus valores por defecto. */
function bloques_ajustes_defecto(): array
{
    return [
        'fondo'        => '#eef0f4',
        'tarjeta'      => '#ffffff',
        'marca'        => '#5b2d91',
        'acento'       => '#8bc53f',
        'texto'        => '#4a4a55',
        'titulo'       => '#241a33',
        'preencabezado' => '',
    ];
}

// ------------------------------------------------------------
//  Utilidades
// ------------------------------------------------------------

function _c(?string $v, string $def): string
{
    $v = trim((string)$v);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $def;
}

function _n($v, int $def, int $min = 0, int $max = 9999): int
{
    $n = (int)$v;
    return ($v === '' || $v === null) ? $def : max($min, min($max, $n));
}

/** Escapa y convierte los saltos de línea en <br>. */
function _parr(string $texto): string
{
    return nl2br(e($texto), false);
}

/** Alineación válida. */
function _al(?string $v, string $def = 'left'): string
{
    return in_array($v, ['left', 'center', 'right'], true) ? $v : $def;
}

/**
 * URL segura para un correo: sólo http(s), mailto y las variables de plantilla.
 *
 * Cualquier otro esquema se descarta por completo. `javascript:`, `data:` y
 * `vbscript:` no tienen nada que hacer en un correo y son el vector clásico
 * para inyectar algo en el cliente del destinatario.
 */
function _url(?string $v): string
{
    $v = trim((string)$v);
    if ($v === '') { return ''; }

    // Las variables se resuelven después del render; se dejan pasar tal cual.
    if (preg_match('/^\{\{[a-z_]+\}\}$/i', $v)) { return $v; }

    // Se mira el esquema sin espacios ni caracteres de control, que es como se
    // suele disfrazar un `java\tscript:`.
    $limpio = preg_replace('/[\s\x00-\x1F]+/', '', $v) ?? $v;
    if (preg_match('/^(javascript|data|vbscript|file|about):/i', $limpio)) { return ''; }

    if (preg_match('#^https?://#i', $limpio)) { return $v; }
    if (preg_match('/^mailto:/i', $limpio))   { return $v; }
    if (str_contains($v, '@') && !str_contains($v, '/')) { return 'mailto:' . $v; }
    if (str_contains($v, ':')) { return ''; }   // esquema desconocido: se descarta
    return 'https://' . ltrim($v, '/');
}

/** Una fila de la tabla exterior, con el relleno lateral estándar. */
function _fila(string $contenido, string $padding = '0 44px'): string
{
    return '<tr><td class="px" style="padding:' . $padding . ';">' . $contenido . '</td></tr>';
}

// ------------------------------------------------------------
//  Render de cada bloque
// ------------------------------------------------------------

function bloque_html(array $b, array $a, array $ctx): string
{
    $f   = CORREO_FUENTE;
    $tip = (string)($b['tipo'] ?? '');

    switch ($tip) {
        case 'encabezado':
            $logo = trim((string)($b['logo'] ?? ($ctx['logo'] ?? '')));
            $alto = _n($b['alto'] ?? '', 42, 16, 120);
            $der  = trim((string)($b['derecha'] ?? ''));
            $izq  = $logo !== ''
                ? '<img src="' . e($logo) . '" alt="' . e($ctx['empresa'] ?? 'Logo') . '" height="' . $alto . '"'
                  . ' style="height:' . $alto . 'px;width:auto;display:block;border:0;outline:none;text-decoration:none;">'
                : '<span style="font-family:' . $f . ';font-size:22px;font-weight:bold;color:' . _c($a['marca'], '#5b2d91')
                  . ';letter-spacing:.5px;">' . e(mb_strtoupper((string)($ctx['empresa'] ?? 'INTELLIHELP'))) . '</span>';
            return _fila(
                '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>'
                . '<td align="left" valign="middle">' . $izq . '</td>'
                . ($der !== ''
                    ? '<td align="right" valign="middle" style="font-family:' . $f . ';font-size:13px;color:#9a9aa6;letter-spacing:.3px;">' . e($der) . '</td>'
                    : '')
                . '</tr></table>', '26px 40px 22px 40px');

        case 'hero':
            $bg = _c($b['fondo'] ?? '', _c($a['marca'], '#5b2d91'));
            $volanta = trim((string)($b['volanta'] ?? ''));
            $titulo  = trim((string)($b['titulo'] ?? ''));
            $bajada  = trim((string)($b['bajada'] ?? ''));
            $h  = '<tr><td class="hero-px" bgcolor="' . e($bg) . '" style="background-color:' . e($bg) . ';padding:36px 44px 38px 44px;">';
            if ($volanta !== '') {
                $h .= '<p style="margin:0 0 12px 0;font-family:' . $f . ';font-size:12px;font-weight:bold;letter-spacing:2px;'
                    . 'text-transform:uppercase;color:' . _c($a['acento'], '#8bc53f') . ';">' . e($volanta) . '</p>';
            }
            if ($titulo !== '') {
                $h .= '<h1 class="h1" style="margin:0 0 14px 0;font-family:' . $f . ';font-size:30px;line-height:1.22;'
                    . 'font-weight:bold;color:#ffffff;">' . _parr($titulo) . '</h1>';
            }
            if ($bajada !== '') {
                $h .= '<p style="margin:0;font-family:' . $f . ';font-size:16px;line-height:1.6;color:#e3d8f2;">'
                    . _parr($bajada) . '</p>';
            }
            return $h . '</td></tr>';

        case 'titulo':
            $niv = in_array((int)($b['nivel'] ?? 2), [1, 2, 3], true) ? (int)$b['nivel'] : 2;
            $tam = [1 => 26, 2 => 21, 3 => 17][$niv];
            return _fila('<p style="margin:0;font-family:' . $f . ';font-size:' . $tam . 'px;line-height:1.3;'
                . 'font-weight:bold;color:' . _c($a['titulo'], '#241a33') . ';text-align:' . _al($b['alineacion'] ?? '') . ';">'
                . _parr((string)($b['texto'] ?? '')) . '</p>', '22px 44px 6px 44px');

        case 'texto':
            return _fila('<p style="margin:0;font-family:' . $f . ';font-size:16px;line-height:1.7;'
                . 'color:' . _c($a['texto'], '#4a4a55') . ';text-align:' . _al($b['alineacion'] ?? '') . ';">'
                . _parr((string)($b['texto'] ?? '')) . '</p>', '10px 44px 10px 44px');

        case 'lista':
            $items = array_filter(array_map('trim', explode("\n", (string)($b['texto'] ?? ''))));
            if (!$items) { return ''; }
            // Sin <ul>: los clientes le ponen márgenes distintos. Se arma con
            // una tabla y el símbolo como celda, que se ve igual en todos.
            $filas = '';
            foreach ($items as $it) {
                $filas .= '<tr>'
                    . '<td valign="top" width="22" style="font-family:' . $f . ';font-size:16px;line-height:1.7;'
                    . 'color:' . _c($a['acento'], '#8bc53f') . ';font-weight:bold;">&bull;</td>'
                    . '<td valign="top" style="font-family:' . $f . ';font-size:16px;line-height:1.7;padding-bottom:8px;'
                    . 'color:' . _c($a['texto'], '#4a4a55') . ';">' . _parr($it) . '</td></tr>';
            }
            return _fila('<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0">'
                . $filas . '</table>', '6px 44px 10px 44px');

        case 'imagen':
            $src = trim((string)($b['src'] ?? ''));
            if ($src === '') { return ''; }
            $ancho = _n($b['ancho'] ?? '', 512, 40, CORREO_ANCHO);
            $alt   = trim((string)($b['alt'] ?? ''));
            $enl   = _url($b['enlace'] ?? '');
            $img = '<img src="' . e($src) . '" alt="' . e($alt) . '" width="' . $ancho . '"'
                 . ' style="width:100%;max-width:' . $ancho . 'px;height:auto;display:block;border:0;'
                 . 'outline:none;text-decoration:none;border-radius:8px;">';
            if ($enl !== '') {
                $img = '<a href="' . e($enl) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
            }
            return _fila('<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>'
                . '<td align="' . _al($b['alineacion'] ?? '', 'center') . '">' . $img . '</td></tr></table>',
                '14px 44px 14px 44px');

        case 'boton':
            $texto = trim((string)($b['texto'] ?? 'Ver más'));
            $enl   = _url($b['enlace'] ?? '');
            if ($enl === '') { return ''; }
            $bg    = _c($b['fondo'] ?? '', _c($a['marca'], '#5b2d91'));
            $fg    = _c($b['color'] ?? '', '#ffffff');
            $al    = _al($b['alineacion'] ?? '', 'center');
            // Botón «a prueba de balas»: la versión VML es la que hace que
            // Outlook lo dibuje como botón y no como un enlace suelto.
            return _fila(
                '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td align="' . $al . '">'
                . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" '
                . 'href="' . e($enl) . '" style="height:46px;v-text-anchor:middle;width:280px;" arcsize="20%" '
                . 'strokecolor="' . e($bg) . '" fillcolor="' . e($bg) . '"><w:anchorlock/>'
                . '<center style="color:' . e($fg) . ';font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">'
                . e($texto) . '</center></v:roundrect><![endif]-->'
                . '<!--[if !mso]><!-- -->'
                . '<a class="btn" href="' . e($enl) . '" target="_blank" style="display:inline-block;background-color:' . e($bg) . ';'
                . 'color:' . e($fg) . ';font-family:' . $f . ';font-size:16px;font-weight:bold;line-height:46px;'
                . 'text-align:center;text-decoration:none;width:280px;border-radius:10px;">' . e($texto) . '</a>'
                . '<!--<![endif]-->'
                . '</td></tr></table>', '12px 44px 12px 44px');

        case 'destacado':
            $bg = _c($b['fondo'] ?? '', '#f5f3fa');
            $t  = trim((string)($b['titulo'] ?? ''));
            $x  = trim((string)($b['texto'] ?? ''));
            $h  = '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
                . 'style="background-color:' . e($bg) . ';border-radius:12px;"><tr><td align="'
                . _al($b['alineacion'] ?? '', 'center') . '" style="padding:26px 26px 28px 26px;">';
            if ($t !== '') {
                $h .= '<p style="margin:0 0 6px 0;font-family:' . $f . ';font-size:21px;font-weight:bold;'
                    . 'color:' . _c($a['marca'], '#5b2d91') . ';">' . _parr($t) . '</p>';
            }
            if ($x !== '') {
                $h .= '<p style="margin:0;font-family:' . $f . ';font-size:15px;line-height:1.6;color:#6b6b76;">'
                    . _parr($x) . '</p>';
            }
            return _fila($h . '</td></tr></table>', '14px 44px 8px 44px');

        case 'contacto':
            $filas = '';
            foreach ([['Web', 'web'], ['WhatsApp', 'whatsapp'], ['Teléfono', 'telefono'], ['Email', 'email']] as [$et, $k]) {
                $v = trim((string)($b[$k] ?? ($ctx['empresa_' . $k] ?? '')));
                if ($v === '') { continue; }
                $valor = e($v);
                if ($k === 'web')   { $valor = '<a href="' . e(_url($v)) . '" target="_blank" style="color:' . _c($a['titulo'], '#241a33') . ';font-weight:bold;text-decoration:none;">' . e($v) . '</a>'; }
                if ($k === 'email') { $valor = '<a href="mailto:' . e($v) . '" style="color:' . _c($a['titulo'], '#241a33') . ';font-weight:bold;text-decoration:none;">' . e($v) . '</a>'; }
                if ($k !== 'web' && $k !== 'email') { $valor = '<span style="font-weight:bold;">' . e($v) . '</span>'; }
                $filas .= '<tr><td width="96" valign="top" style="font-family:' . $f . ';font-size:13px;color:#9a9aa6;'
                    . 'padding:5px 0;text-transform:uppercase;letter-spacing:.5px;">' . e($et) . '</td>'
                    . '<td valign="top" style="font-family:' . $f . ';font-size:15px;color:' . _c($a['texto'], '#4a4a55')
                    . ';padding:5px 0;">' . $valor . '</td></tr>';
            }
            if ($filas === '') { return ''; }
            return _fila('<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
                . 'style="border:1px solid #ececf2;border-radius:10px;"><tr><td style="padding:20px 26px;">'
                . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0">' . $filas
                . '</table></td></tr></table>', '18px 44px 8px 44px');

        case 'firma':
            $cierre = trim((string)($b['cierre'] ?? 'Saludos cordiales,'));
            $quien  = trim((string)($b['firma'] ?? '{{remitente}}'));
            $cargo  = trim((string)($b['cargo'] ?? ''));
            $h = '<p style="margin:0;font-family:' . $f . ';font-size:15px;color:' . _c($a['texto'], '#4a4a55') . ';">'
               . _parr($cierre) . '</p>';
            if ($quien !== '') {
                $h .= '<p style="margin:6px 0 0 0;font-family:' . $f . ';font-size:16px;font-weight:bold;'
                    . 'color:' . _c($a['marca'], '#5b2d91') . ';">' . _parr($quien) . '</p>';
            }
            if ($cargo !== '') {
                $h .= '<p style="margin:2px 0 0 0;font-family:' . $f . ';font-size:13.5px;color:#9a9aa6;">' . _parr($cargo) . '</p>';
            }
            return _fila($h, '20px 44px 30px 44px');

        case 'separador':
            return _fila('<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>'
                . '<td style="border-top:1px solid #ececf2;font-size:0;line-height:0;">&nbsp;</td></tr></table>',
                '10px 44px');

        case 'espacio':
            $alto = _n($b['alto'] ?? '', 20, 4, 90);
            return '<tr><td style="height:' . $alto . 'px;font-size:0;line-height:0;">&nbsp;</td></tr>';
    }
    return '';
}

// ------------------------------------------------------------
//  Documento completo
// ------------------------------------------------------------

/**
 * Arma el HTML del correo a partir de los bloques.
 *
 * @param array $bloques lista de bloques del editor
 * @param array $ajustes colores y preencabezado
 * @param array $ctx     datos de la empresa y del remitente
 */
function bloques_render(array $bloques, array $ajustes = [], array $ctx = []): string
{
    $a = array_merge(bloques_ajustes_defecto(), $ajustes);
    $f = CORREO_FUENTE;

    $cuerpo = '';
    foreach ($bloques as $b) {
        if (is_array($b)) { $cuerpo .= bloque_html($b, $a, $ctx); }
    }

    $pre = trim((string)($a['preencabezado'] ?? ''));
    $preHtml = $pre !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;'
          . 'color:' . e(_c($a['fondo'], '#eef0f4')) . ';opacity:0;">' . e($pre)
          . str_repeat('&#847;&zwnj;&nbsp;', 5) . '</div>'
        : '';

    // La media query es lo único que va en <style>: sirve para el celular en
    // los clientes que la soportan, y donde no, el correo igual se ve porque
    // todo lo importante está en línea.
    $estilos = '<style type="text/css">'
        . '@media only screen and (max-width:620px){'
        . '.cont{width:100%!important;}'
        . '.px{padding-left:24px!important;padding-right:24px!important;}'
        . '.hero-px{padding-left:24px!important;padding-right:24px!important;}'
        . '.h1{font-size:25px!important;line-height:1.25!important;}'
        . '.btn{width:100%!important;}'
        . '}</style>';

    return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n"
        . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" '
        . 'xmlns:o="urn:schemas-microsoft-com:office:office" lang="es"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
        . '<meta name="x-apple-disable-message-reformatting">'
        . '<title>' . e((string)($ctx['empresa'] ?? 'INTELLIHELP')) . '</title>'
        . '<!--[if mso]><style type="text/css">table{border-collapse:collapse;}'
        . 'body,table,td{font-family:Arial,Helvetica,sans-serif !important;}</style><![endif]-->'
        . $estilos . '</head>'
        . '<body style="margin:0;padding:0;background-color:' . e(_c($a['fondo'], '#eef0f4')) . ';'
        . '-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
        . $preHtml
        . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
        . 'style="background-color:' . e(_c($a['fondo'], '#eef0f4')) . ';"><tr>'
        . '<td align="center" style="padding:34px 12px;">'
        . '<table role="presentation" class="cont" width="' . CORREO_ANCHO . '" border="0" cellpadding="0" cellspacing="0" '
        . 'style="width:' . CORREO_ANCHO . 'px;max-width:' . CORREO_ANCHO . 'px;background-color:'
        . e(_c($a['tarjeta'], '#ffffff')) . ';border-radius:14px;overflow:hidden;">'
        // Barra bicolor superior: es la firma visual de la marca.
        . '<tr><td style="padding:0;font-size:0;line-height:0;">'
        . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="66%" style="height:6px;background-color:' . e(_c($a['marca'], '#5b2d91')) . ';font-size:0;line-height:0;">&nbsp;</td>'
        . '<td width="34%" style="height:6px;background-color:' . e(_c($a['acento'], '#8bc53f')) . ';font-size:0;line-height:0;">&nbsp;</td>'
        . '</tr></table></td></tr>'
        . $cuerpo
        . '</table></td></tr></table></body></html>';
}

/**
 * Versión en texto plano.
 *
 * No es un adorno: un correo sólo-HTML es una señal clásica de spam, y hay
 * clientes que directamente muestran esta versión.
 */
function bloques_texto(array $bloques, array $ctx = []): string
{
    $out = [];
    foreach ($bloques as $b) {
        $t = (string)($b['tipo'] ?? '');
        switch ($t) {
            case 'hero':
                foreach (['volanta', 'titulo', 'bajada'] as $k) {
                    $v = trim((string)($b[$k] ?? ''));
                    if ($v !== '') { $out[] = $k === 'titulo' ? mb_strtoupper($v) : $v; }
                }
                $out[] = '';
                break;
            case 'titulo':
                $out[] = mb_strtoupper(trim((string)($b['texto'] ?? ''))); $out[] = '';
                break;
            case 'texto':
                $out[] = trim((string)($b['texto'] ?? '')); $out[] = '';
                break;
            case 'lista':
                foreach (array_filter(array_map('trim', explode("\n", (string)($b['texto'] ?? '')))) as $it) {
                    $out[] = '  - ' . $it;
                }
                $out[] = '';
                break;
            case 'boton':
                $enl = _url($b['enlace'] ?? '');
                if ($enl !== '') { $out[] = trim((string)($b['texto'] ?? 'Ver más')) . ': ' . $enl; $out[] = ''; }
                break;
            case 'destacado':
                foreach (['titulo', 'texto'] as $k) {
                    $v = trim((string)($b[$k] ?? ''));
                    if ($v !== '') { $out[] = $v; }
                }
                $out[] = '';
                break;
            case 'contacto':
                foreach ([['Web', 'web'], ['WhatsApp', 'whatsapp'], ['Teléfono', 'telefono'], ['Email', 'email']] as [$et, $k]) {
                    $v = trim((string)($b[$k] ?? ($ctx['empresa_' . $k] ?? '')));
                    if ($v !== '') { $out[] = "$et: $v"; }
                }
                $out[] = '';
                break;
            case 'firma':
                $out[] = trim((string)($b['cierre'] ?? 'Saludos cordiales,'));
                $q = trim((string)($b['firma'] ?? ''));
                if ($q !== '') { $out[] = $q; }
                $c = trim((string)($b['cargo'] ?? ''));
                if ($c !== '') { $out[] = $c; }
                $out[] = '';
                break;
            case 'separador':
                $out[] = str_repeat('-', 40); $out[] = '';
                break;
        }
    }
    return trim(implode("\n", $out));
}

// ------------------------------------------------------------
//  Verificador de entregabilidad
// ------------------------------------------------------------

/**
 * Palabras que disparan los filtros. Las de urgencia y las de plata son las
 * que más pesan en los modelos de 2026: con tres o más el correo casi no
 * llega a la bandeja principal.
 */
function palabras_riesgosas(): array
{
    return [
        'gratis', 'gratuito', '100% gratis', 'sin costo alguno', 'oferta limitada',
        'oferta exclusiva', 'actúa ahora', 'actua ahora', 'no te lo pierdas',
        'última oportunidad', 'ultima oportunidad', 'urgente', 'sólo por hoy',
        'solo por hoy', 'gana dinero', 'ingresos extra', 'hacete rico',
        'sin riesgo', 'garantizado', 'felicitaciones', 'ganaste', 'premio',
        'haga clic aquí', 'haz clic aquí', 'click aquí', 'compre ahora',
        'descuento del 100', 'dinero fácil', 'dinero facil', 'crédito gratis',
        'free', 'act now', 'limited time', 'risk free', 'buy now', 'click here',
        'make money', 'cash bonus', 'winner', 'congratulations', 'guaranteed',
    ];
}

/**
 * Revisa el correo armado y devuelve los problemas encontrados.
 *
 * Cada hallazgo tiene nivel `error` (hay que arreglarlo antes de mandar) o
 * `aviso` (conviene revisarlo). Todo está justificado con el motivo: la idea
 * es que el usuario entienda por qué, no que obedezca.
 *
 * @return array{errores:array, avisos:array, datos:array}
 */
function bloques_verificar(array $bloques, string $asunto, string $html, string $texto): array
{
    $errores = [];
    $avisos  = [];

    // --- Tamaño ---
    $bytes = strlen($html);
    if ($bytes > 102000) {
        $errores[] = ['El correo pesa ' . round($bytes / 1024) . ' KB',
            'Gmail recorta todo lo que pasa de 102 KB y muestra «mensaje recortado»: el destinatario no llega a ver el final. Sacá bloques o acortá el texto.'];
    } elseif ($bytes > 80000) {
        $avisos[] = ['El correo pesa ' . round($bytes / 1024) . ' KB',
            'Estás cerca del límite de 102 KB en el que Gmail recorta el mensaje.'];
    }

    // --- Texto vs. imagen ---
    $palabras = str_word_count(strip_tags($texto), 0, 'áéíóúñÁÉÍÓÚÑüÜ');
    $imagenes = substr_count($html, '<img ');
    if ($palabras < 40 && $imagenes > 0) {
        $errores[] = ['Muy poco texto para la cantidad de imágenes',
            'Con menos de 40 palabras y ' . $imagenes . ' imagen(es), los filtros lo leen como un correo hecho de imágenes. Lo recomendado es al menos 60 % de texto.'];
    } elseif ($imagenes >= 4 && $palabras < 120) {
        $avisos[] = [$imagenes . ' imágenes con poco texto',
            'Pasado el 40 % de imagen los proveedores empiezan a mirar el correo con lupa.'];
    }

    // --- Enlaces ---
    $enlaces = substr_count($html, '<a ');
    if ($palabras > 0 && $enlaces > max(3, (int)ceil($palabras / 100) + 2)) {
        $avisos[] = [$enlaces . ' enlaces para ' . $palabras . ' palabras',
            'Muchos enlaces sobre poco texto suben el puntaje de spam. La referencia es cerca de un enlace cada 100 palabras.'];
    }

    // --- Asunto ---
    $asuntoLimpio = trim($asunto);
    if ($asuntoLimpio === '') {
        $errores[] = ['Falta el asunto', 'Un correo sin asunto va directo a spam.'];
    } else {
        if (mb_strlen($asuntoLimpio) > 70) {
            $avisos[] = ['El asunto tiene ' . mb_strlen($asuntoLimpio) . ' caracteres',
                'En el celular se corta cerca de los 45. Conviene que lo importante entre al principio.'];
        }
        if ($asuntoLimpio === mb_strtoupper($asuntoLimpio) && mb_strlen($asuntoLimpio) > 10) {
            $errores[] = ['El asunto está TODO EN MAYÚSCULAS',
                'Es uno de los patrones que más rápido marcan los filtros.'];
        }
        if (substr_count($asuntoLimpio, '!') > 1 || str_contains($asuntoLimpio, '!!')) {
            $avisos[] = ['Signos de exclamación de más en el asunto',
                'Los signos repetidos son una señal clásica de correo masivo.'];
        }
    }

    // --- Palabras riesgosas ---
    $todo = mb_strtolower($asuntoLimpio . ' ' . strip_tags($texto));
    $encontradas = [];
    foreach (palabras_riesgosas() as $p) {
        if (str_contains($todo, mb_strtolower($p))) { $encontradas[] = $p; }
    }
    if (count($encontradas) >= 3) {
        $errores[] = ['Palabras que disparan los filtros: ' . implode(', ', array_slice($encontradas, 0, 6)),
            'Con tres o más de estas expresiones la llegada a la bandeja principal cae por debajo del 25 %.'];
    } elseif ($encontradas) {
        $avisos[] = ['Cuidado con: ' . implode(', ', $encontradas),
            'Son expresiones que los filtros miran con desconfianza. Si podés, escribilo de otra forma.'];
    }

    // --- Cosas que directamente no funcionan en un correo ---
    foreach ([
        '/<script/i'                => ['JavaScript', 'Ningún cliente de correo ejecuta JavaScript y su sola presencia dispara los filtros.'],
        '/<form/i'                  => ['Formularios', 'Los formularios no funcionan dentro de un correo.'],
        '/<(video|iframe|embed)/i'  => ['Video o contenido incrustado', 'No se reproduce en el correo. Conviene una imagen que enlace al video.'],
        '/background-image\s*:/i'   => ['Imagen de fondo por CSS', 'Outlook no la muestra: el bloque queda sin fondo.'],
        '/display\s*:\s*(flex|grid)/i' => ['Flexbox o grid', 'Outlook usa el motor de Word y los ignora: el diseño se desarma.'],
        '/position\s*:\s*(absolute|fixed)/i' => ['Posicionamiento absoluto', 'No se respeta en la mayoría de los clientes.'],
    ] as $re => [$que, $porque]) {
        if (preg_match($re, $html)) { $errores[] = [$que . ' en el correo', $porque]; }
    }

    // --- Baja de suscripción ---
    if (!str_contains($html, '{{baja}}') && !preg_match('/\bbaja\b|desuscrib|unsubscribe/i', $html)) {
        $avisos[] = ['No se ve el enlace de baja',
            'El sistema lo agrega igual al final, pero incluirlo en el diseño con {{baja}} queda mejor y mejora la reputación del remitente.'];
    }

    // --- Personalización ---
    if (!str_contains($html, '{{nombre}}') && !str_contains($asuntoLimpio, '{{')) {
        $avisos[] = ['El correo no está personalizado',
            'Usar {{nombre}} o {{empresa}} mejora bastante la tasa de respuesta y baja el riesgo de que lo marquen como masivo.'];
    }

    // --- Imágenes sin texto alternativo ---
    if (preg_match_all('/<img (?![^>]*alt=")[^>]*>/i', $html, $m)) {
        $avisos[] = [count($m[0]) . ' imagen(es) sin texto alternativo',
            'Muchos clientes bloquean las imágenes por defecto: sin el texto alternativo, ahí no se ve nada.'];
    }

    return [
        'errores' => $errores,
        'avisos'  => $avisos,
        'datos'   => ['bytes' => $bytes, 'palabras' => $palabras,
                      'imagenes' => $imagenes, 'enlaces' => $enlaces],
    ];
}
