<?php
declare(strict_types=1);

// ============================================================
//  Piezas comunes a todos los módulos: notas, etiquetas y adjuntos.
//  Son polimórficas (entidad + entidad_id) para no repetir una tabla y un
//  formulario por cada módulo del CRM.
//
//  Tablas: `notas`, `etiquetas` + `etiqueta_registro`, `adjuntos`. Como la
//  relación es polimórfica, no hay clave foránea: la base no impide guardar
//  una nota para un id que no existe. Los controladores lo validan con
//  registro_vivo() antes de escribir.
//
//  Lo usan las fichas (contacto-ver, cuenta-ver, posible-ver, negocio-ver)
//  a través de las vistas parciales _notas, _etiquetas y _adjuntos, y los
//  controladores nota-guardar, nota-eliminar, etiquetas-guardar, adjunto y
//  adjunto-subir. También los badges de estado de campañas y envíos.
//  Los adjuntos son un módulo apagable ('adjuntos' en modulos.php).
// ============================================================

/**
 * Entidades que aceptan notas, etiquetas y adjuntos.
 * Es la lista blanca contra la que se valida lo que llega por POST.
 */
function entidades_validas(): array
{
    return ['contacto', 'cuenta', 'posible_cliente', 'negocio', 'campana'];
}

/** ¿La entidad está en la lista blanca? */
function entidad_valida(string $e): bool
{
    return in_array($e, entidades_validas(), true);
}

/**
 * URL de la ficha de un registro cualquiera (ya con BASE_PATH, lista para
 * una cabecera Location o, envuelta en e(), para un href).
 * Una entidad desconocida lleva al panel.
 */
function url_registro(string $entidad, int $id): string
{
    return url(match ($entidad) {
        'contacto'        => 'contacto-ver?id=' . $id,
        'cuenta'          => 'cuenta-ver?id=' . $id,
        'posible_cliente' => 'posible-ver?id=' . $id,
        'negocio'         => 'negocio-ver?id=' . $id,
        'campana'         => 'campana-ver?id=' . $id,
        default           => 'dashboard',
    });
}

/**
 * Tabla real de una entidad: la de notas/etiquetas/adjuntos y, además, la de
 * cualquier entidad de la papelera (tablas_papelera() de crm.php). Null si no
 * se conoce. Es lista blanca: el nombre se pega en el SQL.
 */
function entidad_tabla(string $entidad): ?string
{
    $propias = [
        'contacto'        => 'contactos',
        'cuenta'          => 'cuentas',
        'posible_cliente' => 'posibles_clientes',
        'negocio'         => 'negocios',
        'campana'         => 'campanas',
    ];
    if (isset($propias[$entidad])) { return $propias[$entidad]; }
    if (function_exists('tablas_papelera')) {
        return tablas_papelera()[$entidad]['tabla'] ?? null;
    }
    return null;
}

/**
 * Módulo apagable al que pertenece una entidad (clave de modulos()), o null
 * si es del núcleo (contactos y empresas, que no se apagan).
 *
 * Las rutas compartidas (registro-eliminar, nota-guardar, etiquetas-guardar,
 * adjunto, buscar) no figuran en ningún módulo: con esto controlan que el
 * módulo del registro sobre el que actúan esté encendido.
 */
function entidad_modulo(string $entidad): ?string
{
    return match ($entidad) {
        'posible_cliente'                => 'posibles',
        'negocio'                        => 'negocios',
        'actividad'                      => 'actividades',
        'campana', 'lista', 'plantilla'  => 'campanas',
        default                          => null,
    };
}

/** ¿El módulo de esa entidad está encendido? El núcleo siempre lo está. */
function entidad_habilitada(string $entidad): bool
{
    $m = entidad_modulo($entidad);
    return $m === null || !function_exists('modulo_activo') || modulo_activo($m);
}

/**
 * La fila de un registro que existe y no está en la papelera, o null.
 *
 * Notas, etiquetas y adjuntos son polimórficos y no tienen clave foránea: sin
 * esto se podía colgar una nota de un id que no existe o de algo borrado.
 */
function registro_vivo(string $entidad, int $id): ?array
{
    $tabla = entidad_tabla($entidad);
    if ($tabla === null || $id <= 0) { return null; }
    return q1("SELECT * FROM `$tabla` WHERE id = ? AND eliminado_en IS NULL", [$id]);
}

/**
 * ¿Esta persona puede mandar el registro a la papelera?
 *
 * Regla del sistema: el administrador, siempre; si no, el propietario. Un
 * registro sin propietario (los importados, por ejemplo) lo puede mandar
 * cualquiera, porque la base se comparte en el equipo. Las listas y las
 * plantillas no tienen propietario: cuenta quien las creó. En una campaña
 * cuentan las dos cosas, su propietario y quien la creó.
 *
 * Es una función pura para que la usen igual el servidor y las vistas (los
 * botones que no corresponden se ocultan, y el servidor igual lo controla).
 *
 * @param array $fila la fila del registro (con propietario_id / creado_por)
 */
function puede_mandar_papelera(string $entidad, array $fila, ?int $uid, bool $admin): bool
{
    if ($admin) { return true; }
    $campos = match ($entidad) {
        'campana'           => ['propietario_id', 'creado_por'],
        'lista', 'plantilla'=> ['creado_por'],
        default             => ['propietario_id'],
    };
    $duenos = [];
    foreach ($campos as $c) {
        if (!empty($fila[$c])) { $duenos[] = (int)$fila[$c]; }
    }
    if (!$duenos) { return true; }
    return $uid !== null && in_array($uid, $duenos, true);
}

/**
 * Atajo para las vistas: ¿el usuario de la sesión puede mandar este registro
 * a la papelera?
 */
function puedo_mandar_papelera(string $entidad, array $fila): bool
{
    return puede_mandar_papelera($entidad, $fila, current_user_id(), auth_is_admin());
}

// ---- Notas ----

/** Notas no borradas de un registro, de la más nueva a la más vieja, con el nombre del autor. */
function notas_de(string $entidad, int $id, int $limite = 50): array
{
    return q('SELECT n.*, u.nombre AS autor FROM notas n
              LEFT JOIN usuarios u ON u.id = n.creado_por
              WHERE n.entidad = ? AND n.entidad_id = ? AND n.eliminado_en IS NULL
              ORDER BY n.creado_en DESC LIMIT ?', [$entidad, $id, $limite])->fetchAll();
}

/**
 * Crea una nota a nombre del usuario de la sesión, la deja en la auditoría y
 * devuelve su id. No valida la entidad ni que el registro exista.
 */
function nota_crear(string $entidad, int $id, string $cuerpo, ?string $titulo = null): int
{
    q('INSERT INTO notas (entidad, entidad_id, titulo, cuerpo, creado_por) VALUES (?, ?, ?, ?, ?)',
      [$entidad, $id, $titulo ?: null, $cuerpo, current_user_id()]);
    $nuevo = last_id();
    log_app($entidad . '.nota', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => 'Nota: ' . recortar($cuerpo, 120)]);
    return $nuevo;
}

/**
 * Borrado lógico de una nota (eliminado_en) y registro en la auditoría.
 *
 * No controla quién la borra: el permiso (autor o administrador) lo
 * comprueba pages/nota-eliminar.php. Las notas no figuran en la papelera,
 * así que no hay forma de restaurarlas desde la interfaz.
 */
function nota_eliminar(int $notaId): void
{
    $n = q1('SELECT entidad, entidad_id FROM notas WHERE id = ?', [$notaId]);
    q('UPDATE notas SET eliminado_en = NOW() WHERE id = ?', [$notaId]);
    if ($n) {
        log_app($n['entidad'] . '.nota_eliminar',
            ['entidad' => $n['entidad'], 'entidad_id' => (int)$n['entidad_id'], 'detalle' => 'Nota eliminada']);
    }
}

// ---- Etiquetas ----

/** Todas las etiquetas que existen, por nombre (para sugerir al escribir). */
function etiquetas_todas(): array
{
    return q('SELECT * FROM etiquetas ORDER BY nombre')->fetchAll();
}

/** Etiquetas asignadas a un registro. */
function etiquetas_de(string $entidad, int $id): array
{
    return q('SELECT et.* FROM etiquetas et
              JOIN etiqueta_registro er ON er.etiqueta_id = et.id
              WHERE er.entidad = ? AND er.entidad_id = ? ORDER BY et.nombre', [$entidad, $id])->fetchAll();
}

/**
 * Reemplaza las etiquetas de un registro por la lista dada (por nombre).
 *
 * Borra todas las asignaciones y vuelve a crear las de la lista; las
 * etiquetas que no existen se crean (nombre recortado a 60 caracteres). Las
 * etiquetas que quedan sin uso no se borran. Va en una transacción: si falla
 * a mitad, el registro queda con las etiquetas que tenía (si ya había una
 * transacción abierta, se suma a ella y la decide quien la abrió).
 *
 * @param array<int,string> $nombres
 */
function etiquetas_asignar(string $entidad, int $id, array $nombres): void
{
    $pdo    = db();
    $propia = !$pdo->inTransaction();
    if ($propia) { $pdo->beginTransaction(); }
    try {
        q('DELETE FROM etiqueta_registro WHERE entidad = ? AND entidad_id = ?', [$entidad, $id]);
        foreach (array_unique(array_filter(array_map('trim', $nombres))) as $nombre) {
            // Se busca por el nombre tal cual se guarda (recortado a 60): si no,
            // un nombre largo no se encontraba nunca y se volvía a crear.
            $nombre = mb_substr($nombre, 0, 60);
            $et = q1('SELECT id FROM etiquetas WHERE nombre = ?', [$nombre]);
            if (!$et) {
                q('INSERT INTO etiquetas (nombre) VALUES (?)', [$nombre]);
                $etId = last_id();
            } else {
                $etId = (int)$et['id'];
            }
            q('INSERT IGNORE INTO etiqueta_registro (etiqueta_id, entidad, entidad_id) VALUES (?, ?, ?)',
              [$etId, $entidad, $id]);
        }
        if ($propia) { $pdo->commit(); }
    } catch (\Throwable $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

// ---- Presentación ----

/**
 * Badge de estado de un envío de campaña (HTML listo para imprimir).
 * Un estado desconocido se muestra tal cual, escapado.
 */
function badge_envio(string $estado): string
{
    $mapa = [
        'pendiente' => ['badge-neutral', 'En cola'],
        'enviando'  => ['badge-info',    'Enviando'],
        'enviado'   => ['badge-ok',      'Enviado'],
        'rebotado'  => ['badge-rej',     'Rebotó'],
        'rechazado' => ['badge-rej',     'Rechazado'],
        'omitido'   => ['badge-neutral', 'Omitido'],
        'error'     => ['badge-pend',    'Error'],
    ];
    [$clase, $texto] = $mapa[$estado] ?? ['badge-neutral', $estado];
    return '<span class="badge ' . $clase . '">' . e($texto) . '</span>';
}

/** Badge de estado de una campaña (HTML listo para imprimir). */
function badge_campana(string $estado): string
{
    $mapa = [
        'borrador'   => ['badge-neutral', 'Borrador'],
        'programada' => ['badge-info',    'Programada'],
        'enviando'   => ['badge-ok',      'Enviando'],
        'pausada'    => ['badge-pend',    'Pausada'],
        'completada' => ['badge-ok',      'Completada'],
        'cancelada'  => ['badge-rej',     'Cancelada'],
    ];
    [$clase, $texto] = $mapa[$estado] ?? ['badge-neutral', $estado];
    return '<span class="badge ' . $clase . '">' . e($texto) . '</span>';
}

// ------------------------------------------------------------
//  Adjuntos
//  Los archivos viven fuera del web root: se sirven por una ruta que
//  comprueba la sesión, para que un presupuesto no quede accesible con sólo
//  adivinar la dirección.
// ------------------------------------------------------------

/** Carpeta raíz de los adjuntos (STORAGE/adjuntos). */
function adjuntos_dir(): string
{
    return rtrim(STORAGE, '/') . '/adjuntos';
}

/** Adjuntos no borrados de un registro, del más nuevo al más viejo. */
function adjuntos_de(string $entidad, int $id): array
{
    return q('SELECT * FROM adjuntos
              WHERE entidad = ? AND entidad_id = ? AND eliminado_en IS NULL
              ORDER BY creado_en DESC', [$entidad, $id])->fetchAll();
}

/**
 * Extensiones con las que un adjunto se guarda en disco. Cualquier otra queda
 * como «.bin».
 *
 * El nombre original (con su extensión) se conserva en la base y es el que se
 * usa en la descarga, así que esto no le cambia nada a quien baja el archivo.
 * Lo que evita es que en disco quede un «.php» o un «.phtml» que el servidor
 * pudiera ejecutar si la carpeta de almacenamiento terminara, por un error de
 * configuración, dentro de lo que se sirve por HTTP.
 */
function adjunto_ext_disco(string $nombreOriginal): string
{
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $seguras = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt',
                'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'rar', '7z', 'eml', 'msg', 'xml'];
    return in_array($ext, $seguras, true) ? $ext : 'bin';
}

/**
 * Guarda un archivo subido y lo registra en `adjuntos`.
 *
 * En disco queda como STORAGE/adjuntos/AAAA/MM/<32 hex>.<ext>; la columna
 * `ruta` guarda la parte relativa y `nombre` el nombre original (sólo para
 * mostrar y para la descarga). En disco la extensión sale de una lista de
 * tipos de documento (adjunto_ext_disco): un «.php» queda como «.bin». Además
 * el archivo queda fuera del web root, con nombre aleatorio, y se descarga
 * siempre como octet-stream (pages/adjunto.php). La carpeta lleva su propio
 * .htaccess que niega todo, por si alguna vez quedara dentro del web root.
 * El `mime` que se guarda es el que declaró el navegador.
 *
 * @param array $archivo una entrada de $_FILES
 * @return int id del adjunto
 * @throws RuntimeException sin archivo, más de 10 MB o error al moverlo
 */
function adjunto_guardar(string $entidad, int $id, array $archivo): int
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No llegó ningún archivo.');
    }
    if ($archivo['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('El archivo pesa ' . peso_legible((int)$archivo['size'])
            . '. El máximo son 10 MB.');
    }

    // El nombre del archivo en disco lo elegimos nosotros: el que manda el
    // navegador puede traer barras o «..» y salirse de la carpeta.
    $dir = adjuntos_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $ext  = adjunto_ext_disco((string)$archivo['name']);
    $ruta = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!is_dir($dir . '/' . dirname($ruta))) { @mkdir($dir . '/' . dirname($ruta), 0750, true); }
    if (!@move_uploaded_file($archivo['tmp_name'], $dir . '/' . $ruta)) {
        throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    }

    q('INSERT INTO adjuntos (entidad, entidad_id, nombre, ruta, mime, bytes, creado_por)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$entidad, $id, mb_substr((string)$archivo['name'], 0, 255), $ruta,
       mb_substr((string)($archivo['type'] ?? ''), 0, 100), (int)$archivo['size'], current_user_id()]);
    $nuevo = last_id();
    log_app($entidad . '.adjunto', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => 'Se subió ' . $archivo['name'] . ' (' . peso_legible((int)$archivo['size']) . ')']);
    return $nuevo;
}

/**
 * ¿Esta persona puede borrar el adjunto? Su autor o un administrador. Un
 * adjunto sin autor registrado sólo lo borra un administrador.
 */
function puede_borrar_adjunto(array $adjunto, ?int $uid, bool $admin): bool
{
    if ($admin) { return true; }
    $autor = (int)($adjunto['creado_por'] ?? 0);
    return $autor > 0 && $uid !== null && $autor === $uid;
}

/**
 * Borra un adjunto: borrado LÓGICO, como todo en el sistema. La fila queda
 * con eliminado_en y el archivo sigue en el disco; un administrador lo puede
 * restaurar desde la papelera (solapa Archivos) hasta que el cron lo purga
 * con adjuntos_purgar(), pasado el plazo de la papelera.
 *
 * No controla quién lo borra: eso lo hace pages/adjunto-subir.php con
 * puede_borrar_adjunto().
 */
function adjunto_borrar(int $adjuntoId): void
{
    $a = q1('SELECT * FROM adjuntos WHERE id = ? AND eliminado_en IS NULL', [$adjuntoId]);
    if (!$a) { return; }
    q('UPDATE adjuntos SET eliminado_en = NOW() WHERE id = ?', [$adjuntoId]);
    log_app($a['entidad'] . '.adjunto_borrar', ['entidad' => $a['entidad'],
        'entidad_id' => (int)$a['entidad_id'], 'detalle' => 'Se mandó a la papelera ' . $a['nombre']]);
}

/**
 * Saca un adjunto de la papelera. Devuelve false si ya no se puede: no existe,
 * no estaba borrado o el archivo ya no está en el disco (los que se borraron
 * antes de que el borrado fuera lógico se eliminaban del disco en el acto).
 */
function adjunto_restaurar(int $adjuntoId): bool
{
    $a = q1('SELECT * FROM adjuntos WHERE id = ? AND eliminado_en IS NOT NULL', [$adjuntoId]);
    if (!$a || !is_file(adjuntos_dir() . '/' . $a['ruta'])) { return false; }
    q('UPDATE adjuntos SET eliminado_en = NULL WHERE id = ?', [$adjuntoId]);
    log_app($a['entidad'] . '.adjunto_restaurar', ['entidad' => $a['entidad'],
        'entidad_id' => (int)$a['entidad_id'], 'detalle' => 'Se restauró ' . $a['nombre']]);
    return true;
}

/**
 * Borra definitivamente los adjuntos que llevan más de $dias en la papelera:
 * el archivo del disco y la fila. Lo llama el cron junto con
 * purgar_papelera(). Devuelve cuántos purgó.
 *
 * Si el archivo no se puede borrar del disco (permisos), la fila se deja para
 * reintentar en la purga siguiente; si ya no estaba, la fila se borra igual.
 */
function adjuntos_purgar(?int $dias = null): int
{
    $dias = $dias ?? (function_exists('dias_papelera') ? dias_papelera() : 60);
    $filas = q('SELECT id, ruta, entidad, nombre FROM adjuntos
                WHERE eliminado_en IS NOT NULL AND eliminado_en < NOW() - INTERVAL ? DAY', [$dias])->fetchAll();
    $n = 0;
    foreach ($filas as $a) {
        $f = adjuntos_dir() . '/' . $a['ruta'];
        if (is_file($f) && !@unlink($f)) { continue; }
        q('DELETE FROM adjuntos WHERE id = ?', [(int)$a['id']]);
        $n++;
    }
    if ($n > 0) {
        log_app('papelera.purga', ['tipo' => 'sistema', 'actor' => 'cron', 'entidad' => 'adjunto',
            'detalle' => $n . ' archivo(s) eliminados definitivamente tras ' . $dias . ' días en la papelera']);
    }
    return $n;
}
