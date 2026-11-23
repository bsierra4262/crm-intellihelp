<?php
declare(strict_types=1);

// ============================================================
//  Importación del CSV de Zoho CRM.
//
//  Lo que hace, y por qué:
//   · Reconoce las columnas por su nombre en castellano (así salen del
//     exportador de Zoho) y también por los alias que ya usa el equipo. La
//     comparación ignora mayúsculas y tildes.
//   · Acepta el archivo en UTF-8 (con o sin BOM), en UTF-16 con BOM y en
//     Windows-1252, que es lo que guarda Excel en castellano como «CSV
//     (delimitado por comas)»: todo se pasa a UTF-8 antes de leerlo.
//   · Crea las CUENTAS únicas y enlaza los contactos: en la base real hay
//     12.249 nombres de empresa para 13.573 contactos, así que importar sin
//     esto duplicaría empresas por todos lados. Las empresas se comparan con
//     empresa_clave(): «ACME S.A.» y «Acme SA» son la misma.
//   · Normaliza provincia, país y localidad: en el CSV la misma provincia
//     aparece escrita de tres formas y la localidad trae basura de LinkedIn.
//   · Unifica `Fax` y `Conmutador`, que en la base real traen el MISMO número.
//   · Usa el `ID de registro` de Zoho como clave y, si no encuentra por ahí,
//     el correo (principal, secundario o terciario): reimportar el mismo
//     archivo actualiza en vez de duplicar. En una actualización no se
//     cambian el propietario ni la empresa que el contacto ya tenía.
//
//  Quién lo usa: sólo la pantalla «Importar» (pages/importar.php, sólo
//  administradores), que lo carga con require_once; no está en bootstrap.
//  Es núcleo: modulos.php no lo apaga. La carga de correos a una lista sí
//  depende de que las campañas estén encendidas (lo controla la página).
//
//  Tablas: escribe contactos, cuentas (vía cuenta_asegurar), lista_miembros e
//  import_lotes (una fila por corrida real, no por simulación); lee usuarios.
//  Depende de crm.php (normalizar_*, cuenta_asegurar, contacto_guardar,
//  empresa_clave, plano) y de
//  helpers.php (email_valido, normalizar_email, recortar).
//
//  Corridas largas: se guarda de a IMPORTAR_TANDA filas por transacción y la
//  fila de import_lotes se escribe al empezar y se actualiza en cada tanda.
//  Si el servidor corta la corrida, lo confirmado queda guardado, la tanda
//  a medias se descarta entera y «Importaciones anteriores» la muestra
//  «cortada» con hasta dónde llegó. Reimportar el archivo completa el resto
//  (lo ya cargado se actualiza, no se duplica).
// ============================================================

/** Filas por transacción en la importación de contactos. */
const IMPORTAR_TANDA = 200;

/**
 * Columnas del CSV de Zoho → campo del CRM. El primero que exista, gana.
 *
 * «Existir» quiere decir que la columna está y la celda no está vacía (ver
 * valor_zoho): si «Conmutador» viene vacío se usa «Fax». Los nombres se
 * comparan sin distinguir mayúsculas ni tildes.
 */
function mapa_zoho_contactos(): array
{
    return [
        'zoho_id'        => ['ID de registro', 'Record Id'],
        'nombre'         => ['Nombre', 'First Name'],
        'apellido'       => ['Apellidos', 'Last Name'],
        'cuenta'         => ['Nombre de Cuenta', 'Account Name'],
        'cargo'          => ['Título', 'Title'],
        'departamento'   => ['Departamento', 'Department'],
        'email'          => ['Correo electrónico', 'Email'],
        'email2'         => ['Correo electrónico secundario', 'Secondary Email'],
        'email3'         => ['Correo electrónico terciario'],
        'telefono'       => ['Teléfono Directo', 'Teléfono', 'Phone'],
        'movil'          => ['Móvil', 'Mobile'],
        'conmutador'     => ['Conmutador', 'Fax'],
        'linkedin'       => ['LinkedIn Personal'],
        'sitio_web'      => ['Página Web', 'Website'],
        'calle'          => ['Domicilio para correspondencia', 'Mailing Street'],
        'localidad'      => ['Localidad', 'Ciudad para correspondencia', 'Mailing City'],
        'provincia'      => ['Provincia', 'Mailing State'],
        'codigo_postal'  => ['Código postal para correspondencia', 'Mailing Zip'],
        'pais'           => ['País para correspondencia', 'Mailing Country'],
        'descripcion'    => ['Descripción', 'Description'],
        'propietario'    => ['Propietario de Contacto', 'Contact Owner'],
        'creado_en'      => ['Hora de creación', 'Created Time'],
    ];
}

/**
 * Arma la fila como nombre de columna → celda. La clave es el nombre plano
 * (sin tildes ni mayúsculas), así «TÍTULO» y «Título» son la misma columna.
 */
function csv_fila(array $cab, array $celdas): array
{
    $fila = [];
    foreach ($cab as $i => $nombreCol) {
        $k = plano((string)$nombreCol);
        // Si dos columnas se llaman igual, gana la primera que traiga dato.
        if (!isset($fila[$k]) || trim((string)$fila[$k]) === '') {
            $fila[$k] = (string)($celdas[$i] ?? '');
        }
    }
    return $fila;
}

/**
 * Devuelve el valor de la primera columna que exista en la fila y no esté
 * vacía, sin espacios a los costados. Cadena vacía si ninguna tiene dato.
 * Busca por el nombre tal cual y por su forma plana (ver csv_fila).
 */
function valor_zoho(array $fila, array $candidatas): string
{
    foreach ($candidatas as $c) {
        foreach ([$c, plano($c)] as $k) {
            if (isset($fila[$k]) && trim((string)$fila[$k]) !== '') {
                return trim((string)$fila[$k]);
            }
        }
    }
    return '';
}

/**
 * Pasa el contenido de un archivo a UTF-8 y le saca el BOM.
 *
 *  · BOM de UTF-8 → se saca;
 *  · BOM de UTF-16 (LE o BE), lo que guarda Excel como «Texto Unicode» → se
 *    convierte;
 *  · sin BOM: si es UTF-8 válido se deja; si no, se toma como Windows-1252
 *    (Excel en castellano), que es un superconjunto de ISO-8859-1.
 *
 * @return array{0:string,1:string} [texto en UTF-8, codificación detectada]
 */
function csv_a_utf8(string $raw): array
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        return [substr($raw, 3), 'UTF-8'];
    }
    if (str_starts_with($raw, "\xFF\xFE")) {
        return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16'];
    }
    if (str_starts_with($raw, "\xFE\xFF")) {
        return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16'];
    }
    if (mb_check_encoding($raw, 'UTF-8')) {
        return [$raw, 'UTF-8'];
    }
    return [mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
}

/**
 * Abre un CSV detectando codificación y separador y devuelve
 * [encabezados, handle, separador, codificación].
 *
 * El archivo se pasa entero a UTF-8 (csv_a_utf8) y se lee desde una copia en
 * memoria (php://temp, que pasa a disco si es grande). El separador se elige
 * mirando sólo la primera línea: si tiene más «;» que «,» es punto y coma (lo
 * que guarda Excel en castellano); si no, coma. El handle queda posicionado
 * en la primera fila de datos y lo tiene que cerrar quien llama.
 *
 * @throws RuntimeException si no se puede abrir, está vacío o no tiene encabezados
 */
function csv_abrir(string $ruta): array
{
    $raw = @file_get_contents($ruta);
    if ($raw === false) {
        throw new RuntimeException('No se pudo abrir el archivo.');
    }
    [$texto, $codif] = csv_a_utf8($raw);
    unset($raw);
    if (trim($texto) === '') {
        throw new RuntimeException('El archivo está vacío.');
    }
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $texto);
    rewind($fh);
    $nl = strcspn($texto, "\r\n");
    $primera = substr($texto, 0, $nl);
    unset($texto);
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    // fgetcsv entiende las comillas. El escape '\\' es el de PHP, no el de RFC
    // 4180: una barra invertida justo antes de una comilla dentro de un campo
    // desarma la fila.
    $cab = fgetcsv($fh, 0, $sep, '"', '\\');
    if ($cab === false || $cab === [null]) {
        fclose($fh);
        throw new RuntimeException('No se pudieron leer los encabezados.');
    }
    $cab = array_map(static fn($c) => trim((string)$c), $cab);
    return [$cab, $fh, $sep, $codif];
}

/**
 * Lee la «Hora de creación» de Zoho y la devuelve como Y-m-d H:i:s.
 *
 * Las fechas con barras o guiones van día/mes/año, como las exporta Zoho en
 * castellano: «01/02/2024» es el 1 de febrero (strtotime() lo leía como 2 de
 * enero, y «13/02/2024» directamente no lo entendía). También acepta el
 * formato ISO (2024-02-01 10:30:00) y, como último recurso, lo que entienda
 * strtotime() si el texto trae nombres de mes («Feb 1, 2024 10:30 AM»).
 *
 * @return string|null null si no se reconoce
 */
function fecha_zoho(string $txt): ?string
{
    $txt = trim($txt);
    if ($txt === '') { return null; }
    $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y-m-d\TH:i:s',
                 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y h:i A', 'd/m/Y h:i a', 'd/m/Y',
                 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd/m/y H:i', 'd/m/y'];
    foreach ($formatos as $f) {
        $d = DateTime::createFromFormat('!' . $f, $txt);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    if (preg_match('/[a-z]{3}/i', $txt) && ($ts = strtotime($txt))) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

/** Suma uno al motivo en un detalle motivo => cantidad (con tope de 25 motivos). */
function importar_contar(array &$detalle, string $motivo): void
{
    if (!isset($detalle[$motivo]) && count($detalle) >= 25) { return; }
    $detalle[$motivo] = ($detalle[$motivo] ?? 0) + 1;
}

/**
 * Índices de los contactos ya cargados, para cruzar el archivo sin una
 * consulta por fila (son más de 25.000 contactos).
 *
 * @return array{zoho:array,email_vivo:array,email_papelera:array,fichas:array}
 *   zoho: zoho_id => id; email_vivo / email_papelera: correo => id (el que lo
 *   tiene como principal gana sobre el que lo tiene de secundario); fichas:
 *   id => [propietario_id, cuenta_id, zoho_id, vivo]
 */
function importar_indice_contactos(): array
{
    $ix = ['zoho' => [], 'email_vivo' => [], 'email_papelera' => [], 'fichas' => []];
    $filas = q('SELECT id, zoho_id, email, email2, email3, propietario_id, cuenta_id,
                       CASE WHEN eliminado_en IS NULL THEN 1 ELSE 0 END AS vivo
                FROM contactos ORDER BY id')->fetchAll();
    foreach ($filas as $c) {
        $id = (int)$c['id'];
        $ix['fichas'][$id] = ['propietario_id' => $c['propietario_id'] ? (int)$c['propietario_id'] : null,
                              'cuenta_id' => $c['cuenta_id'] ? (int)$c['cuenta_id'] : null,
                              'zoho_id' => (string)($c['zoho_id'] ?? ''), 'vivo' => (bool)(int)$c['vivo']];
        if ((string)$c['zoho_id'] !== '') { $ix['zoho'][(string)$c['zoho_id']] = $id; }
    }
    // Dos pasadas: primero los correos principales, después los otros.
    foreach ([['email'], ['email2', 'email3']] as $cols) {
        foreach ($filas as $c) {
            $donde = (int)$c['vivo'] ? 'email_vivo' : 'email_papelera';
            foreach ($cols as $col) {
                $e = normalizar_email((string)($c[$col] ?? ''));
                if ($e !== '' && !isset($ix[$donde][$e])) { $ix[$donde][$e] = (int)$c['id']; }
            }
        }
    }
    return $ix;
}

/**
 * Busca el contacto de una fila en los índices.
 *
 * Primero por ID de Zoho y, si no, por cualquiera de los correos de la fila.
 * Sólo cuenta un contacto vivo; si el único que coincide está en la papelera
 * se devuelve 'papelera' (no se actualiza un contacto que alguien borró, y
 * crear otro igual lo duplicaría).
 *
 * @return int|string|null id del contacto vivo, 'papelera' o null si es nuevo
 */
function importar_buscar_contacto(array $ix, string $zoho, array $emails)
{
    $enPapelera = false;
    if ($zoho !== '' && isset($ix['zoho'][$zoho])) {
        $id = $ix['zoho'][$zoho];
        if ($ix['fichas'][$id]['vivo'] ?? true) { return $id; }
        $enPapelera = true;
    }
    foreach ($emails as $e) {
        if ($e !== '' && isset($ix['email_vivo'][$e])) { return $ix['email_vivo'][$e]; }
    }
    foreach ($emails as $e) {
        if ($e !== '' && isset($ix['email_papelera'][$e])) { $enPapelera = true; }
    }
    return $enPapelera ? 'papelera' : null;
}

/**
 * Importa contactos (y sus empresas) desde un CSV exportado de Zoho.
 *
 * Por cada fila: normaliza, asegura la empresa (comparando con
 * empresa_clave()) y crea o actualiza el contacto (importar_buscar_contacto).
 * Si existe se actualiza en modo «sólo completar» (los vacíos del archivo no
 * borran lo cargado) y sin cambiarle el propietario ni la empresa que ya
 * tenía; si no tenía, se le ponen los del archivo. Si no existe, se crea.
 * Las filas que coinciden con un contacto de la papelera se omiten.
 *
 * Un error en una fila no corta la corrida: se cuenta y se sigue.
 * Efectos (sin simular): escribe contactos y cuentas, una fila en
 * import_lotes (al empezar, actualizada en cada tanda) y una línea en el log.
 *
 * @param string      $ruta    el archivo subido (el temporal de PHP)
 * @param bool        $simular si es true no escribe nada: sirve para ver qué
 *                             pasaría. Las filas repetidas dentro del archivo
 *                             cuentan como una alta y después actualizaciones.
 * @param int|null    $propietarioPorDefecto dueño para las filas cuyo
 *                             propietario no coincide con ningún usuario
 * @param string|null $nombreArchivo el nombre original, para la traza
 * @return array resumen de la corrida: filas, creados, actualizados, omitidos,
 *               cuentas_nuevas, errores, detalle_omitidos (motivo => cantidad),
 *               avisos (motivo => cantidad, cosas que se corrigieron sin omitir
 *               la fila), columnas (encabezados leídos), codificacion y simulado
 */
function importar_contactos_zoho(string $ruta, bool $simular = false, ?int $propietarioPorDefecto = null,
                                 ?string $nombreArchivo = null): array
{
    [$cab, $fh, $sep, $codif] = csv_abrir($ruta);
    $mapa = mapa_zoho_contactos();
    if (function_exists('set_time_limit')) { @set_time_limit(0); }

    $res = [
        'filas' => 0, 'creados' => 0, 'actualizados' => 0, 'omitidos' => 0,
        'cuentas_nuevas' => 0, 'errores' => 0, 'detalle_omitidos' => [], 'avisos' => [],
        'columnas' => $cab, 'codificacion' => $codif, 'simulado' => $simular,
    ];

    // Los propietarios de Zoho se resuelven contra nuestros usuarios por nombre
    // plano (sin tildes ni mayúsculas). Incluye a los usuarios inactivos: un
    // contacto de alguien que ya no está igual queda a su nombre.
    $usuariosPorNombre = [];
    foreach (q('SELECT id, nombre FROM usuarios')->fetchAll() as $u) {
        $usuariosPorNombre[plano((string)$u['nombre'])] = (int)$u['id'];
    }

    $ix = importar_indice_contactos();
    // Empresas por clave: «ACME S.A.» y «Acme SA» van a la misma. Gana la más
    // antigua. En la simulación las que se crearían quedan con id 0.
    $cuentasPorClave = [];
    foreach (q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY id')->fetchAll() as $c) {
        $k = empresa_clave((string)$c['nombre']);
        if ($k !== '' && !isset($cuentasPorClave[$k])) {
            $cuentasPorClave[$k] = ['id' => (int)$c['id'], 'nombre' => (string)$c['nombre']];
        }
    }

    // La traza se escribe al empezar: si la corrida se corta, queda igual.
    $loteId = null;
    $archivo = mb_substr($nombreArchivo !== null && $nombreArchivo !== '' ? basename($nombreArchivo) : basename($ruta), 0, 255);
    $guardarLote = static function (string $estado) use (&$res, &$loteId): void {
        if (!$loteId) { return; }
        try {
            q('UPDATE import_lotes SET filas = ?, creados = ?, actualizados = ?, omitidos = ?, errores = ?, resumen = ?
               WHERE id = ?',
              [$res['filas'], $res['creados'], $res['actualizados'], $res['omitidos'], $res['errores'],
               json_encode(['estado' => $estado] + $res['detalle_omitidos'] + $res['avisos'], JSON_UNESCAPED_UNICODE),
               $loteId]);
        } catch (\Throwable $e) {
            // La traza no puede frenar la importación.
        }
    };
    if (!$simular) {
        try {
            q('INSERT INTO import_lotes (origen, modulo, archivo, filas, creados, actualizados, omitidos, errores, resumen, creado_por)
               VALUES (?, ?, ?, 0, 0, 0, 0, 0, ?, ?)',
              ['zoho', 'contactos', $archivo, json_encode(['estado' => 'en curso']), current_user_id()]);
            $loteId = last_id();
        } catch (\Throwable $e) {
            $loteId = null;
        }
    }

    $pdo = $simular ? null : db();
    $enTanda = 0;
    $abrirTanda = static function () use ($pdo): void {
        if ($pdo && !$pdo->inTransaction()) { $pdo->beginTransaction(); }
    };
    $cerrarTanda = static function () use ($pdo, $guardarLote): void {
        $guardarLote('en curso');
        if ($pdo && $pdo->inTransaction()) { $pdo->commit(); }
    };

    // Lo ya visto en el archivo, para que la simulación cuente igual que la
    // importación real las filas repetidas.
    $vistosZoho = [];
    $vistosEmail = [];

    while (($f = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        // Las líneas en blanco del final (o del medio) no cuentan como filas.
        if (count($f) === 1 && trim((string)$f[0]) === '') { continue; }
        $res['filas']++;
        $fila = csv_fila($cab, $f);

        $d = [];
        foreach ($mapa as $campo => $candidatas) {
            $d[$campo] = valor_zoho($fila, $candidatas);
        }

        // El apellido es obligatorio en la base (como en Zoho). Sin nombre ni
        // apellido la fila se omite; con nombre solo, ese nombre pasa a ser el
        // apellido.
        if ($d['apellido'] === '' && $d['nombre'] === '') {
            $res['omitidos']++;
            importar_contar($res['detalle_omitidos'], 'Sin nombre ni apellido');
            continue;
        }
        if ($d['apellido'] === '') {
            $d['apellido'] = $d['nombre'];
            $d['nombre']   = '';
        }

        // Limpieza / normalización
        $d['provincia'] = normalizar_provincia($d['provincia']) ?? '';
        $d['pais']      = normalizar_pais($d['pais']) ?? '';
        $d['localidad'] = normalizar_localidad($d['localidad']) ?? '';
        // Un correo que no es válido se descarta: el contacto entra igual, sin
        // ese correo, y queda anotado en los avisos del resumen.
        foreach (['email', 'email2', 'email3'] as $k) {
            if ($d[$k] !== '' && !email_valido($d[$k])) {
                importar_contar($res['avisos'], 'Correo no válido descartado (el contacto entró sin él)');
                $d[$k] = '';
            } else {
                $d[$k] = $d[$k] !== '' ? normalizar_email($d[$k]) : '';
            }
        }

        // `Fax` y `Conmutador` suelen traer el MISMO número: si el teléfono
        // directo coincide con el conmutador, se deja uno solo. La comparación es
        // del texto tal cual vino: «4555-1234» y «45551234» cuentan como distintos.
        if ($d['telefono'] !== '' && $d['telefono'] === $d['conmutador']) {
            $d['conmutador'] = '';
        }

        $propietarioId = $usuariosPorNombre[plano($d['propietario'])] ?? $propietarioPorDefecto;
        $emails = array_values(array_filter([$d['email'], $d['email2'], $d['email3']]));
        $existente = importar_buscar_contacto($ix, $d['zoho_id'], $emails);

        if ($existente === 'papelera') {
            $res['omitidos']++;
            importar_contar($res['detalle_omitidos'], 'Coincide con un contacto de la papelera (restauralo y volvé a importar)');
            continue;
        }

        if ($simular) {
            if ($d['cuenta'] !== '') {
                $k = empresa_clave($d['cuenta']);
                if ($k === '' ? !cuenta_por_nombre($d['cuenta']) : !isset($cuentasPorClave[$k])) {
                    $res['cuentas_nuevas']++;
                    if ($k !== '') { $cuentasPorClave[$k] = ['id' => 0, 'nombre' => $d['cuenta']]; }
                }
            }
            $repetido = ($d['zoho_id'] !== '' && isset($vistosZoho[$d['zoho_id']]))
                     || array_intersect_key($vistosEmail, array_flip($emails));
            ($existente || $repetido) ? $res['actualizados']++ : $res['creados']++;
            if ($d['zoho_id'] !== '') { $vistosZoho[$d['zoho_id']] = true; }
            foreach ($emails as $e) { $vistosEmail[$e] = true; }
            continue;
        }

        $abrirTanda();
        try {
            // 1) Empresa: la misma clave reutiliza la existente (y le completa los
            //    huecos); si no hay, se crea con el nombre tal como vino.
            $cuentaId = null;
            if ($d['cuenta'] !== '') {
                $extra = [
                    'propietario_id' => $propietarioId,
                    'sitio_web'      => ($d['sitio_web'] ?? null) ?: null,
                    'telefono'       => ($d['conmutador'] ?? null) ?: ($d['telefono'] ?? null) ?: null,
                    'calle'          => ($d['calle'] ?? null) ?: null,
                    'localidad'      => ($d['localidad'] ?? null) ?: null,
                    'provincia'      => ($d['provincia'] ?? null) ?: null,
                    'codigo_postal'  => ($d['codigo_postal'] ?? null) ?: null,
                    'pais'           => ($d['pais'] ?? null) ?: null,
                ];
                $k = empresa_clave($d['cuenta']);
                if ($k !== '' && isset($cuentasPorClave[$k])) {
                    $cuentaId = cuenta_asegurar($cuentasPorClave[$k]['nombre'], $extra, $propietarioId);
                } else {
                    $antes = cuenta_por_nombre($d['cuenta']);
                    $cuentaId = cuenta_asegurar(mb_substr($d['cuenta'], 0, 200), $extra, $propietarioId);
                    if (!$antes) { $res['cuentas_nuevas']++; }
                    if ($k !== '' && $cuentaId) {
                        $cuentasPorClave[$k] = ['id' => $cuentaId, 'nombre' => mb_substr($d['cuenta'], 0, 200)];
                    }
                }
            }

            $datos = [
                'nombre'         => $d['nombre'],
                'apellido'       => $d['apellido'],
                'cuenta_id'      => $cuentaId,
                'propietario_id' => $propietarioId,
                'cargo'          => $d['cargo'],
                'departamento'   => $d['departamento'],
                'email'          => $d['email'],
                'email2'         => $d['email2'],
                'email3'         => $d['email3'],
                'telefono'       => $d['telefono'],
                'movil'          => $d['movil'],
                'conmutador'     => $d['conmutador'],
                // Vacío a propósito: en el modo «sólo completar» no pisa la fuente que tenga
                // el contacto; en un alta queda sin fuente.
                'fuente'         => '',
                'linkedin'       => $d['linkedin'],
                'sitio_web'      => $d['sitio_web'],
                'calle'          => $d['calle'],
                'localidad'      => $d['localidad'],
                'provincia'      => $d['provincia'],
                'codigo_postal'  => $d['codigo_postal'],
                'pais'           => $d['pais'],
                'descripcion'    => $d['descripcion'],
            ];

            if ($existente) {
                $id = (int)$existente;
                $ficha = $ix['fichas'][$id] ?? ['propietario_id' => null, 'cuenta_id' => null, 'zoho_id' => ''];
                // Reimportar no reasigna: el propietario y la empresa que ya tenía
                // se respetan; sólo se completan si estaban vacíos.
                $datos['propietario_id'] = $ficha['propietario_id'] ?: $propietarioId;
                $datos['cuenta_id']      = $ficha['cuenta_id'] ?: $cuentaId;
                $datos['_solo_completar'] = true;
                contacto_guardar($datos, $id);
                // Encontrado por correo y sin ID de Zoho: se le pone, así la próxima
                // vez se encuentra directo.
                if ($d['zoho_id'] !== '' && $ficha['zoho_id'] === '' && !isset($ix['zoho'][$d['zoho_id']])) {
                    q("UPDATE contactos SET zoho_id = ? WHERE id = ? AND (zoho_id IS NULL OR zoho_id = '')",
                      [mb_substr($d['zoho_id'], 0, 40), $id]);
                    $ix['zoho'][$d['zoho_id']] = $id;
                    $ix['fichas'][$id]['zoho_id'] = $d['zoho_id'];
                }
                $ix['fichas'][$id]['propietario_id'] = $datos['propietario_id'];
                $ix['fichas'][$id]['cuenta_id'] = $datos['cuenta_id'];
                $res['actualizados']++;
            } else {
                $nuevoId = contacto_guardar($datos);
                // zoho_id no pasa por contacto_guardar(): se escribe aparte, después del alta.
                if ($d['zoho_id'] !== '') {
                    q('UPDATE contactos SET zoho_id = ? WHERE id = ?', [mb_substr($d['zoho_id'], 0, 40), $nuevoId]);
                    $ix['zoho'][$d['zoho_id']] = $nuevoId;
                }
                // Respetar la fecha de alta original para no perder la antigüedad.
                if ($d['creado_en'] !== '') {
                    $fecha = fecha_zoho($d['creado_en']);
                    if ($fecha !== null) {
                        q('UPDATE contactos SET creado_en = ? WHERE id = ?', [$fecha, $nuevoId]);
                    } else {
                        importar_contar($res['avisos'], 'Fecha de alta que no se entendió (quedó la de hoy)');
                    }
                }
                $ix['fichas'][$nuevoId] = ['propietario_id' => $propietarioId ?: current_user_id(),
                                           'cuenta_id' => $cuentaId, 'zoho_id' => $d['zoho_id'], 'vivo' => true];
                foreach ($emails as $e) { $ix['email_vivo'][$e] ??= $nuevoId; }
                $res['creados']++;
            }
        } catch (\Throwable $e) {
            $res['errores']++;
            // El detalle técnico va al registro de errores; en el resumen, un motivo
            // claro (sin el mensaje de la base).
            if ($res['errores'] <= 20) {
                log_error('importar.fila', 'Fila ' . $res['filas'] . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
            }
            importar_contar($res['detalle_omitidos'], 'Error al guardar la fila (detalle en el registro de errores)');
        }
        if (++$enTanda >= IMPORTAR_TANDA) {
            $cerrarTanda();
            $enTanda = 0;
        }
    }
    fclose($fh);

    if (!$simular) {
        if ($pdo && $pdo->inTransaction()) { $pdo->commit(); }
        $guardarLote('terminada');
        log_app('importar.contactos', ['detalle' => sprintf(
            '%d filas: %d creados, %d actualizados, %d cuentas nuevas, %d omitidos, %d errores',
            $res['filas'], $res['creados'], $res['actualizados'], $res['cuentas_nuevas'],
            $res['omitidos'], $res['errores'])]);
    }

    return $res;
}

/**
 * Estado de una corrida de import_lotes según su resumen: 'terminada', 'en
 * curso' o 'cortada' (en curso desde hace más de una hora: el servidor la
 * cortó). Las corridas viejas, de antes de que se guardara el estado, cuentan
 * como terminadas.
 */
function importar_estado_lote(array $lote, ?int $ahora = null): string
{
    $r = json_decode((string)($lote['resumen'] ?? ''), true);
    $estado = is_array($r) ? (string)($r['estado'] ?? 'terminada') : 'terminada';
    if ($estado === 'en curso' && strtotime((string)$lote['creado_en']) < ($ahora ?? time()) - 3600) {
        return 'cortada';
    }
    return $estado;
}

/**
 * Importa una lista de correos suelta (el formato de «csv descargados.csv»:
 * Nombre, Apellidos, Cuenta y hasta 4 correos por fila).
 * Cada correo se agrega como un miembro distinto de la lista.
 *
 * Las columnas de correo se reconocen por tener «correo» o «mail» en el
 * encabezado. Se omiten, con su motivo: los inválidos, los repetidos (la
 * clave única lista_id + email, con INSERT IGNORE) y los de contactos
 * marcados «No incluir en campañas» o con el correo rebotado.
 * Deja una fila en import_lotes y una línea en el log.
 *
 * @param string      $ruta          el archivo subido
 * @param int         $listaId       la lista destino
 * @param string|null $nombreArchivo el nombre original, para la traza
 * @return array filas, agregados, omitidos, detalle_omitidos (motivo => cantidad)
 * @throws RuntimeException si la lista no existe o está en la papelera
 */
function importar_lista_csv(string $ruta, int $listaId, ?string $nombreArchivo = null): array
{
    if (!qv('SELECT 1 FROM listas WHERE id = ? AND eliminado_en IS NULL', [$listaId])) {
        throw new RuntimeException('La lista elegida no existe o está en la papelera.');
    }
    [$cab, $fh, $sep] = csv_abrir($ruta);
    if (function_exists('set_time_limit')) { @set_time_limit(0); }
    $res = ['filas' => 0, 'agregados' => 0, 'omitidos' => 0, 'detalle_omitidos' => []];

    $colsEmail = array_values(array_filter($cab, static fn($c) => stripos($c, 'correo') !== false || stripos($c, 'mail') !== false));

    while (($f = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        if (count($f) === 1 && trim((string)$f[0]) === '') { continue; }
        $res['filas']++;
        $fila = csv_fila($cab, $f);

        $nombre  = trim(valor_zoho($fila, ['Nombre', 'First Name']) . ' ' . valor_zoho($fila, ['Apellidos', 'Last Name']));
        $empresa = valor_zoho($fila, ['Nombre de Cuenta', 'Account Name', 'Empresa']);

        foreach ($colsEmail as $col) {
            $crudo = trim((string)($fila[plano($col)] ?? ''));
            if ($crudo === '') { continue; }
            $email = normalizar_email($crudo);
            if (!email_valido($email)) {
                $res['omitidos']++;
                importar_contar($res['detalle_omitidos'], 'Correo no válido');
                continue;
            }
            // Se enlaza con el contacto si ya existe en el CRM: así la campaña
            // queda pegada a la ficha de la persona.
            $ct = q1('SELECT id, no_email, email_rebotado FROM contactos
                      WHERE email = ? AND eliminado_en IS NULL ORDER BY id LIMIT 1', [$email]);
            if ($ct && (int)$ct['no_email']) {
                $res['omitidos']++;
                importar_contar($res['detalle_omitidos'], 'El contacto pidió no recibir campañas');
                continue;
            }
            if ($ct && (int)$ct['email_rebotado']) {
                $res['omitidos']++;
                importar_contar($res['detalle_omitidos'], 'El correo rebotó antes');
                continue;
            }
            $st = q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre, empresa)
                     VALUES (?, ?, ?, ?, ?)',
                    [$listaId, $ct ? (int)$ct['id'] : null, $email,
                     $nombre !== '' ? mb_substr($nombre, 0, 160) : null,
                     $empresa !== '' ? mb_substr($empresa, 0, 200) : null]);
            if ($st->rowCount() > 0) {
                $res['agregados']++;
            } else {
                $res['omitidos']++;
                importar_contar($res['detalle_omitidos'], 'Ya estaba en la lista');
            }
        }
    }
    fclose($fh);

    try {
        q('INSERT INTO import_lotes (origen, modulo, archivo, filas, creados, actualizados, omitidos, errores, resumen, creado_por)
           VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, ?)',
          ['csv', 'lista', mb_substr($nombreArchivo !== null && $nombreArchivo !== '' ? basename($nombreArchivo) : basename($ruta), 0, 255),
           $res['filas'], $res['agregados'], $res['omitidos'],
           json_encode(['estado' => 'terminada', 'lista_id' => $listaId] + $res['detalle_omitidos'], JSON_UNESCAPED_UNICODE),
           current_user_id()]);
    } catch (\Throwable $e) {
        // La traza no invalida una carga que ya se hizo.
    }
    log_app('importar.lista', ['entidad' => 'lista', 'entidad_id' => $listaId,
        'detalle' => $res['agregados'] . ' correos agregados de ' . $res['filas'] . ' filas']);
    return $res;
}
