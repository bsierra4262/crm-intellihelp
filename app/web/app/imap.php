<?php
declare(strict_types=1);

// ============================================================
//  Lectura de la casilla (IMAP): rebotes y respuestas.
//
//  Misma lógica que Pulsemail, con el porqué de cada decisión:
//
//   · Los rebotes se reconocen por el remitente (`mailer-daemon`,
//     `postmaster`, `mail-daemon`) Y, como red de seguridad, por asuntos
//     típicos («Delivery Status Notification», «Undelivered Mail Returned to
//     Sender»…): hay servidores que no firman como daemon pero sí ponen esos
//     asuntos.
//   · Del cuerpo del rebote se extrae la dirección que falló; sólo cuenta si
//     esa dirección está en NUESTRAS campañas. Así los rebotes personales de
//     la misma casilla no ensucian el CRM.
//   · Se guarda el último UID leído por buzón: la primera pasada mira 30 días,
//     las siguientes sólo lo nuevo. Sin eso, el cron cada 5 minutos releería
//     la casilla entera.
// ============================================================

const REMITENTES_REBOTE = ['mailer-daemon', 'postmaster', 'mail-daemon'];
const ASUNTOS_REBOTE = [
    'delivery status notification',
    'undelivered mail returned to sender',
    'mail delivery failed',
    'mail delivery failure',
    'returned mail',
    'undeliverable',
    'no se pudo entregar',
    'correo no entregado',
];

function imap_cfg(): array
{
    $c = (array)($GLOBALS['CONFIG']['imap'] ?? []);
    foreach (['host', 'port', 'user', 'pass'] as $k) {
        $v = setting_get('imap_' . $k, null);
        if ($v !== null && $v !== '') { $c[$k] = $v; }
    }
    // Por defecto se usa la misma cuenta con la que se manda.
    $smtp = smtp_cfg();
    $c += ['host' => 'imap.gmail.com', 'port' => 993,
           'user' => $smtp['user'] ?? '', 'pass' => $smtp['pass'] ?? ''];
    return $c;
}

function imap_configurado(): bool
{
    $c = imap_cfg();
    return function_exists('imap_open') && !empty($c['user']) && !empty($c['pass']);
}

/** Abre la conexión a un buzón. Devuelve el recurso o null. */
function imap_conectar(string $buzon = 'INBOX', ?string &$err = null)
{
    if (!function_exists('imap_open')) {
        $err = 'La extensión IMAP de PHP no está disponible en este servidor.';
        return null;
    }
    $c   = imap_cfg();
    $ruta = '{' . $c['host'] . ':' . (int)$c['port'] . '/imap/ssl}' . $buzon;
    $mbox = @imap_open($ruta, (string)$c['user'], (string)$c['pass'], 0, 1);
    if (!$mbox) {
        $err = 'No se pudo abrir la casilla: ' . (imap_last_error() ?: 'sin detalle');
        imap_errors();
        return null;
    }
    return $mbox;
}

/** Último UID procesado de un buzón. */
function imap_ultimo_uid(string $buzon): int
{
    return (int) (qv('SELECT ultimo_uid FROM imap_estado WHERE buzon = ?', [$buzon]) ?: 0);
}

function imap_guardar_uid(string $buzon, int $uid): void
{
    q('INSERT INTO imap_estado (buzon, ultimo_uid, ultima_corrida) VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE ultimo_uid = GREATEST(ultimo_uid, VALUES(ultimo_uid)), ultima_corrida = NOW()',
      [$buzon, $uid]);
}

/** Decodifica un encabezado MIME a texto plano. */
function imap_texto(?string $s): string
{
    if ($s === null || $s === '') { return ''; }
    $out = '';
    foreach (imap_mime_header_decode($s) as $p) {
        $juego = strtolower((string)$p->charset);
        $texto = (string)$p->text;
        $out  .= ($juego === 'default' || $juego === 'utf-8')
            ? $texto
            : (@mb_convert_encoding($texto, 'UTF-8', $juego) ?: $texto);
    }
    return trim($out);
}

/** ¿El mensaje es un rebote? */
function es_rebote(string $de, string $asunto): bool
{
    $de = strtolower($de);
    foreach (REMITENTES_REBOTE as $r) {
        if (str_contains($de, $r)) { return true; }
    }
    $asunto = strtolower($asunto);
    foreach (ASUNTOS_REBOTE as $a) {
        if (str_contains($asunto, $a)) { return true; }
    }
    return false;
}

/** Saca del cuerpo del rebote la dirección que falló. */
function extraer_destinatario_rebote(string $cuerpo): array
{
    $encontrados = [];
    // Los servidores serios usan la cabecera estándar del DSN.
    if (preg_match_all('/Final-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (preg_match_all('/Original-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (!$encontrados) {
        // Último recurso: cualquier dirección del cuerpo. Se filtra después
        // contra nuestras campañas, así que un falso positivo no hace daño.
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $cuerpo, $m)) {
            $encontrados = $m[0];
        }
    }
    return array_values(array_unique(array_map('normalizar_email', $encontrados)));
}

/**
 * Escanea la casilla y actualiza rebotes y respuestas.
 *
 * @return array{rebotes:int, respuestas:int, revisados:int, error:?string}
 */
function imap_revisar(int $diasPrimeraVez = 30): array
{
    $res = ['rebotes' => 0, 'respuestas' => 0, 'revisados' => 0, 'error' => null];
    if (!imap_configurado()) {
        $res['error'] = 'IMAP sin configurar.';
        return $res;
    }
    $err  = null;
    $mbox = imap_conectar('INBOX', $err);
    if (!$mbox) {
        $res['error'] = $err;
        return $res;
    }

    try {
        $ultimo = imap_ultimo_uid('INBOX');
        if ($ultimo > 0) {
            $uids = imap_search($mbox, 'UID ' . ($ultimo + 1) . ':*', SE_UID) ?: [];
        } else {
            $uids = imap_search($mbox, 'SINCE "' . date('d-M-Y', strtotime("-$diasPrimeraVez days")) . '"', SE_UID) ?: [];
        }
        // Tope por corrida: el cron pasa cada pocos minutos, no hace falta
        // procesar miles de mensajes de una y arriesgar el tiempo de ejecución.
        $uids = array_slice($uids, -300);
        $propio = normalizar_email((string)(imap_cfg()['user'] ?? ''));
        $maxUid = $ultimo;

        foreach ($uids as $uid) {
            $uid = (int)$uid;
            $maxUid = max($maxUid, $uid);
            $res['revisados']++;

            $cab = imap_fetchheader($mbox, $uid, FT_UID);
            if ($cab === false) { continue; }
            $info   = imap_rfc822_parse_headers($cab);
            $de     = '';
            if (!empty($info->from[0])) {
                $de = strtolower(($info->from[0]->mailbox ?? '') . '@' . ($info->from[0]->host ?? ''));
            }
            $asunto = imap_texto($info->subject ?? '');

            if ($de === '' || $de === $propio) { continue; }

            if (es_rebote($de, $asunto)) {
                $cuerpo = (string) imap_body($mbox, $uid, FT_UID | FT_PEEK);
                foreach (extraer_destinatario_rebote($cuerpo) as $direccion) {
                    if ($direccion === $propio) { continue; }
                    // Sólo cuentan los rebotes de NUESTRAS campañas.
                    if (!qv('SELECT 1 FROM envios WHERE email = ? LIMIT 1', [$direccion])) { continue; }
                    marcar_rebote($direccion, recortar($asunto, 200));
                    desuscribir($direccion, 'rebote_duro', null, recortar($asunto, 200));
                    $res['rebotes']++;
                }
                continue;
            }

            // Respuesta de un destinatario de campaña.
            if (qv("SELECT 1 FROM envios WHERE email = ? AND estado = 'enviado' LIMIT 1", [$de])) {
                marcar_respuesta($de, $asunto);
                $res['respuestas']++;
            }
        }

        imap_guardar_uid('INBOX', $maxUid);
    } finally {
        imap_close($mbox);
        imap_errors();
    }

    // Los contadores de las campañas afectadas se recalculan de una sola vez.
    foreach (q("SELECT DISTINCT campana_id FROM envios WHERE rebotado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                   OR respondido_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchAll() as $r) {
        campana_recontar((int)$r['campana_id']);
    }
    return $res;
}

/** Prueba la conexión IMAP sin procesar nada. */
function imap_probar(?string &$err = null): bool
{
    $mbox = imap_conectar('INBOX', $err);
    if (!$mbox) { return false; }
    imap_close($mbox);
    imap_errors();
    return true;
}
