<?php
declare(strict_types=1);

// ============================================================
//  Importación del CSV de Zoho CRM.
//
//  Lo que hace, y por qué:
//   · Reconoce las columnas por su nombre en castellano (así salen del
//     exportador de Zoho) y también por los alias que ya usa el equipo.
//   · Crea las CUENTAS únicas y enlaza los contactos: en la base real hay
//     12.249 nombres de empresa para 13.573 contactos, así que importar sin
//     esto duplicaría empresas por todos lados.
//   · Normaliza provincia, país y localidad: en el CSV la misma provincia
//     aparece escrita de tres formas y la localidad trae basura de LinkedIn.
//   · Unifica `Fax` y `Conmutador`, que en la base real traen el MISMO número.
//   · Usa el `ID de registro` de Zoho como clave: reimportar el mismo archivo
//     actualiza en vez de duplicar.
//
//  Quién lo usa: sólo la pantalla «Importar» (pages/importar.php, sólo
//  administradores), que lo carga con require_once; no está en bootstrap.
//  Es núcleo: modulos.php no lo apaga. La carga de correos a una lista sí
//  depende de que las campañas estén encendidas, y eso lo decide la vista.
//
//  Tablas: escribe contactos, cuentas (vía cuenta_asegurar), lista_miembros e
//  import_lotes (una fila por corrida real, no por simulación); lee usuarios.
//  Depende de crm.php (normalizar_*, cuenta_asegurar, contacto_guardar,
//  plano) y de helpers.php (email_valido, normalizar_email, recortar).
//
//  Trampas conocidas:
//    · el archivo se lee tal cual, sin convertir la codificación: tiene que
//      venir en UTF-8. Un CSV guardado como «CSV (delimitado por comas)» desde
//      Excel en castellano viene en Windows-1252, y entonces los encabezados
//      con tilde («Título», «Móvil») no se reconocen y los textos con acentos
//      fallan al guardarse;
//    · los encabezados se comparan exactos (mayúsculas y tildes incluidas);
//    · cada fila se guarda por separado, sin transacción: si la corrida se
//      corta a la mitad (tiempo máximo de PHP), lo hecho queda hecho y la fila
//      de import_lotes, que se escribe al final, no llega a guardarse.
// ============================================================

/**
 * Columnas del CSV de Zoho → campo del CRM. El primero que exista, gana.
 *
 * «Existir» quiere decir que la columna está y la celda no está vacía (ver
 * valor_zoho): si «Conmutador» viene vacío se usa «Fax». Los nombres van con
 * la grafía exacta del exportador, en castellano y en inglés.
 */
/** Columnas del CSV de Zoho → campo del CRM. El primero que exista, gana. */
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
 * Devuelve el valor de la primera columna que exista en la fila y no esté
 * vacía, sin espacios a los costados. Cadena vacía si ninguna tiene dato.
 */
/** Devuelve el valor de la primera columna que exista en la fila. */
function valor_zoho(array $fila, array $candidatas): string
{
    foreach ($candidatas as $c) {
        if (isset($fila[$c]) && trim((string)$fila[$c]) !== '') {
            return trim((string)$fila[$c]);
        }
    }
    return '';
}

/**
 * Abre un CSV detectando el separador y devuelve [encabezados, handle, separador].
 *
 * El separador se elige mirando sólo la primera línea: si tiene más «;» que
 * «,» es punto y coma (lo que guarda Excel en castellano); si no, coma.
 * El handle queda posicionado en la primera fila de datos y lo tiene que
 * cerrar quien llama.
 *
 * @throws RuntimeException si no se puede abrir, está vacío o no tiene encabezados
 */
/** Abre un CSV detectando el separador y devuelve [encabezados, handle]. */
function csv_abrir(string $ruta): array
{
    $fh = fopen($ruta, 'r');
    if (!$fh) {
        throw new RuntimeException('No se pudo abrir el archivo.');
    }
    $primera = fgets($fh);
    if ($primera === false) {
        fclose($fh);
        throw new RuntimeException('El archivo está vacío.');
    }
    // Sacar el BOM que agrega Excel: si queda, la primera columna no matchea.
    $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera) ?? $primera;
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    // Se vuelve al principio y se relee la línea con fgetcsv, que entiende las
    // comillas. El escape '\\' es el de PHP, no el de RFC 4180: una barra
    // invertida justo antes de una comilla dentro de un campo desarma la fila.
    rewind($fh);
    $cab = fgetcsv($fh, 0, $sep, '"', '\\');
    if ($cab === false) {
        fclose($fh);
        throw new RuntimeException('No se pudieron leer los encabezados.');
    }
    // El BOM se vuelve a sacar acá porque fgetcsv leyó la línea cruda de nuevo.
    $cab[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cab[0]) ?? $cab[0];
    $cab = array_map(static fn($c) => trim((string)$c), $cab);
    return [$cab, $fh, $sep];
}

/**
 * Importa contactos (y sus empresas) desde un CSV exportado de Zoho.
 *
 * Por cada fila: normaliza, asegura la empresa por nombre exacto, y crea o
 * actualiza el contacto. El contacto se busca por el ID de Zoho si la fila lo
 * trae y, SÓLO si no lo trae, por el correo principal. Si existe se actualiza
 * en modo «sólo completar» (los vacíos del archivo no borran lo cargado);
 * si no, se crea.
 *
 * Un error en una fila no corta la corrida: se cuenta y se sigue.
 * Efectos (sin simular): escribe contactos y cuentas, una fila en import_lotes
 * y una línea en el log.
 *
 * @param string   $ruta    el archivo subido (el temporal de PHP)
 * @param bool     $simular si es true no escribe nada: sirve para ver qué
 *                          pasaría. La simulación no lleva cuenta de las
 *                          filas repetidas dentro del mismo archivo: dos
 *                          filas con el mismo correo cuentan como dos altas.
 * @param int|null $propietarioPorDefecto dueño para las filas cuyo
 *                          propietario no coincide con ningún usuario
 * @return array resumen de la corrida: filas, creados, actualizados, omitidos,
 *               cuentas_nuevas, errores, detalle_omitidos (motivo => cantidad),
 *               columnas (encabezados leídos) y simulado
 */
function importar_contactos_zoho(string $ruta, bool $simular = false, ?int $propietarioPorDefecto = null): array
{
    [$cab, $fh, $sep] = csv_abrir($ruta);
    $mapa = mapa_zoho_contactos();

    $res = [
        'filas' => 0, 'creados' => 0, 'actualizados' => 0, 'omitidos' => 0,
        'cuentas_nuevas' => 0, 'errores' => 0, 'detalle_omitidos' => [],
        'columnas' => $cab, 'simulado' => $simular,
    ];

    // Los propietarios de Zoho se resuelven contra nuestros usuarios por nombre.
    // Por nombre plano (sin tildes ni mayúsculas). Incluye a los usuarios
    // inactivos: un contacto de alguien que ya no está igual queda a su nombre.
    $usuariosPorNombre = [];
    foreach (q('SELECT id, nombre FROM usuarios')->fetchAll() as $u) {
        $usuariosPorNombre[plano((string)$u['nombre'])] = (int)$u['id'];
    }

    // Empresas ya contadas en la simulación, para no sumar dos veces la misma.
    $cuentasVistas = [];

    while (($f = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        // Las líneas en blanco del final (o del medio) no cuentan como filas.
        if (count($f) === 1 && trim((string)$f[0]) === '') { continue; }
        $res['filas']++;
        $fila = [];
        foreach ($cab as $i => $nombreCol) {
            $fila[$nombreCol] = $f[$i] ?? '';
        }

        $d = [];
        foreach ($mapa as $campo => $candidatas) {
            $d[$campo] = valor_zoho($fila, $candidatas);
        }

        // Sin apellido no hay contacto en la base (es la única columna obligatoria,
        // como en Zoho). Sin nombre ni apellido la fila se omite; con nombre solo,
        // ese nombre pasa a ser el apellido.
        if ($d['apellido'] === '' && $d['nombre'] === '') {
            $res['omitidos']++;
            $res['detalle_omitidos']['Sin nombre ni apellido'] = ($res['detalle_omitidos']['Sin nombre ni apellido'] ?? 0) + 1;
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
        // Un correo que no es válido se descarta sin avisar: el contacto entra igual,
        // sin ese correo.
        $d['email']     = email_valido($d['email'])  ? normalizar_email($d['email'])  : '';
        $d['email2']    = email_valido($d['email2']) ? normalizar_email($d['email2']) : '';
        $d['email3']    = email_valido($d['email3']) ? normalizar_email($d['email3']) : '';

        // `Fax` y `Conmutador` suelen traer el MISMO número: si el teléfono
        // directo coincide con el conmutador, se deja uno solo. La comparación es
        // del texto tal cual vino: «4555-1234» y «45551234» cuentan como distintos.
        if ($d['telefono'] !== '' && $d['telefono'] === $d['conmutador']) {
            $d['conmutador'] = '';
        }

        // Ojo en la reimportación: este propietario (o el de por defecto, si el del
        // archivo no se reconoce) se escribe también sobre los contactos que ya
        // existían, porque no viene vacío y el modo «sólo completar» no lo protege.
        $propietarioId = $usuariosPorNombre[plano($d['propietario'])] ?? $propietarioPorDefecto;

        if ($simular) {
            if ($d['cuenta'] !== '' && !isset($cuentasVistas[plano($d['cuenta'])])) {
                $cuentasVistas[plano($d['cuenta'])] = true;
                if (!cuenta_por_nombre($d['cuenta'])) { $res['cuentas_nuevas']++; }
            }
            // El mismo criterio de búsqueda que la importación real, más abajo.
            $ya = $d['zoho_id'] !== ''
                ? q1('SELECT id FROM contactos WHERE zoho_id = ?', [$d['zoho_id']])
                : ($d['email'] !== '' ? q1('SELECT id FROM contactos WHERE email = ?', [$d['email']]) : null);
            $ya ? $res['actualizados']++ : $res['creados']++;
            continue;
        }

        try {
            // 1) Empresa
            $cuentaId = null;
            // Se mira antes si existía sólo para poder contar las empresas nuevas.
            if ($d['cuenta'] !== '') {
                $antes = cuenta_por_nombre($d['cuenta']);
                $cuentaId = cuenta_asegurar($d['cuenta'], [
                    'propietario_id' => $propietarioId,
                    'sitio_web'      => ($d['sitio_web'] ?? null) ?: null,
                    'telefono'       => ($d['conmutador'] ?? null) ?: ($d['telefono'] ?? null) ?: null,
                    'calle'          => ($d['calle'] ?? null) ?: null,
                    'localidad'      => ($d['localidad'] ?? null) ?: null,
                    'provincia'      => ($d['provincia'] ?? null) ?: null,
                    'codigo_postal'  => ($d['codigo_postal'] ?? null) ?: null,
                    'pais'           => ($d['pais'] ?? null) ?: null,
                ], $propietarioId);
                if (!$antes) { $res['cuentas_nuevas']++; }
            }

            // 2) Contacto: se busca por el id de Zoho y, si no, por correo.
            // Ninguna de las dos búsquedas filtra eliminado_en: un contacto que está en la
            // papelera se «actualiza» y sigue en la papelera. Y si la fila trae ID de
            // Zoho no se busca por correo: un contacto que ya existía por otra vía (la
            // web, el formulario) con el mismo correo se duplica.
            $existente = $d['zoho_id'] !== ''
                ? q1('SELECT id FROM contactos WHERE zoho_id = ?', [$d['zoho_id']])
                : ($d['email'] !== '' ? q1('SELECT id FROM contactos WHERE email = ?', [$d['email']]) : null);

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
                $datos['_solo_completar'] = true;
                contacto_guardar($datos, (int)$existente['id']);
                $res['actualizados']++;
            } else {
                $nuevoId = contacto_guardar($datos);
                // zoho_id no pasa por contacto_guardar(): se escribe aparte, después del alta.
                if ($d['zoho_id'] !== '') {
                    q('UPDATE contactos SET zoho_id = ? WHERE id = ?', [$d['zoho_id'], $nuevoId]);
                }
                // Respetar la fecha de alta original para no perder la antigüedad.
                // strtotime() lee «01/02/2024» como mes/día (formato de EE. UU.) y devuelve
                // false con «13/02/2024»: con fechas día/mes, la antigüedad queda mal o no se
                // toma.
                if ($d['creado_en'] !== '' && ($ts = strtotime($d['creado_en']))) {
                    q('UPDATE contactos SET creado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', $ts), $nuevoId]);
                }
                $res['creados']++;
            }
        } catch (\Throwable $e) {
            $res['errores']++;
            // El detalle se corta en 20 motivos distintos para que el resumen no crezca
            // sin límite si cada fila falla con un mensaje diferente.
            if (count($res['detalle_omitidos']) < 20) {
                $res['detalle_omitidos']['Error: ' . recortar($e->getMessage(), 60)] =
                    ($res['detalle_omitidos']['Error: ' . recortar($e->getMessage(), 60)] ?? 0) + 1;
            }
        }
    }
    fclose($fh);

    if (!$simular) {
        // La traza de la corrida: es lo que lista «Importaciones anteriores».
        q('INSERT INTO import_lotes (origen, modulo, archivo, filas, creados, actualizados, omitidos, errores, resumen, creado_por)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          ['zoho', 'contactos', basename($ruta), $res['filas'], $res['creados'], $res['actualizados'],
           $res['omitidos'], $res['errores'],
           json_encode($res['detalle_omitidos'], JSON_UNESCAPED_UNICODE), current_user_id()]);
        log_app('importar.contactos', ['detalle' => sprintf(
            '%d filas: %d creados, %d actualizados, %d cuentas nuevas, %d omitidos, %d errores',
            $res['filas'], $res['creados'], $res['actualizados'], $res['cuentas_nuevas'],
            $res['omitidos'], $res['errores'])]);
    }

    return $res;
}

/**
 * Importa una lista de correos suelta (el formato de «csv descargados.csv»:
 * Nombre, Apellidos, Cuenta y hasta 4 correos por fila).
 * Cada correo se agrega como un miembro distinto de la lista.
 *
 * Las columnas de correo se reconocen por tener «correo» o «mail» en el
 * encabezado. Los repetidos los descarta la clave única (lista_id, email) con
 * INSERT IGNORE y cuentan como omitidos, igual que los inválidos.
 * No mira «No incluir en campañas» ni los rebotes del contacto: eso lo tiene
 * que resolver el envío de la campaña. No deja fila en import_lotes.
 *
 * @param string $ruta    el archivo subido
 * @param int    $listaId la lista destino (no se verifica que exista)
 * @return array filas, agregados, omitidos
 */
function importar_lista_csv(string $ruta, int $listaId): array
{
    [$cab, $fh, $sep] = csv_abrir($ruta);
    $res = ['filas' => 0, 'agregados' => 0, 'omitidos' => 0];

    $colsEmail = array_values(array_filter($cab, static fn($c) => stripos($c, 'correo') !== false || stripos($c, 'mail') !== false));

    while (($f = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        if (count($f) === 1 && trim((string)$f[0]) === '') { continue; }
        $res['filas']++;
        $fila = [];
        foreach ($cab as $i => $c) { $fila[$c] = $f[$i] ?? ''; }

        $nombre  = trim(valor_zoho($fila, ['Nombre', 'First Name']) . ' ' . valor_zoho($fila, ['Apellidos', 'Last Name']));
        $empresa = valor_zoho($fila, ['Nombre de Cuenta', 'Account Name', 'Empresa']);

        foreach ($colsEmail as $col) {
            $email = normalizar_email((string)($fila[$col] ?? ''));
            if (!email_valido($email)) { continue; }
            // Se enlaza con el contacto si ya existe en el CRM: así la campaña
            // queda pegada a la ficha de la persona.
            $ct = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);
            $st = q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre, empresa)
                     VALUES (?, ?, ?, ?, ?)',
                    [$listaId, $ct ? (int)$ct['id'] : null, $email, $nombre ?: null, $empresa ?: null]);
            $st->rowCount() > 0 ? $res['agregados']++ : $res['omitidos']++;
        }
    }
    fclose($fh);

    log_app('importar.lista', ['entidad' => 'lista', 'entidad_id' => $listaId,
        'detalle' => $res['agregados'] . ' correos agregados de ' . $res['filas'] . ' filas']);
    return $res;
}
