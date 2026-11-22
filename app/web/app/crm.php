<?php
declare(strict_types=1);

// ============================================================
//  Núcleo CRM: cuentas (empresas), contactos y posibles clientes.
//  Acá vive la lógica; las páginas sólo orquestan y las vistas pintan.
//
//  Qué hay en este archivo, en el orden en que aparece:
//    · listas de valores fijas (provincias, fuentes, estados, sectores…);
//    · qué le falta a una ficha y la aprobación de fichas incompletas;
//    · normalización de provincia, país, localidad y teléfono;
//    · cuentas (empresas), contactos y posibles clientes: lectura, filtros,
//      listado paginado y alta/edición;
//    · la conversión de un posible cliente en cuenta + contacto (+ negocio);
//    · borrado lógico y papelera;
//    · las entradas desde la web (formulario y reservas que llegan por la
//      API v1): empresa y contacto sin duplicar, límites de pedidos, la fila
//      de `entradas_web` y la pantalla «Entradas desde la web».
//
//  Tablas que lee y escribe: cuentas, contactos, posibles_clientes, negocios,
//  negocio_etapas, notas, actividades, adjuntos, entradas_web, api_limites,
//  agenda_reservas, agenda_tipos, usuarios (y las de la papelera).
//
//  Depende de: db.php (q, q1, qv, last_id, db, setting_get), helpers.php
//  (normalizar_email, email_valido, normalizar_cuit, paginar, token32),
//  google.php (crm_secret), auth.php (current_user_id), actividad.php
//  (log_app) y notificaciones.php (notificar). La sección web usa además
//  nota_crear() de registros.php y actividad_guardar() de actividades.php.
//  Lo carga bootstrap.php en todos los pedidos: es núcleo y no se apaga desde
//  modulos.php.
//
//  Convenciones que se repiten en todo el archivo:
//    · nada se borra de verdad: `eliminado_en` marca la papelera y todas las
//      lecturas filtran `eliminado_en IS NULL`;
//    · las funciones *_where() devuelven [cláusula WHERE, parámetros] y los
//      valores del usuario van siempre como parámetros (?), nunca pegados;
//    · las funciones *_guardar() reciben el array crudo del formulario (o de
//      la importación), normalizan, y devuelven el id; con $id editan;
//    · la intercalación de la base es utf8mb4_unicode_ci: `nombre = ?` ya no
//      distingue mayúsculas ni acentos.
// ============================================================

// ------------------------------------------------------------
//  Listas de valores
// ------------------------------------------------------------

/** Las 24 jurisdicciones argentinas, en la forma canónica que usa el sistema. */
function provincias_ar(): array
{
    return [
        'Ciudad de Buenos Aires', 'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
        'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza',
        'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz',
        'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];
}

/** Canales de origen de un contacto o posible cliente (campo `fuente`). */
function fuentes(): array
{
    return ['Campaña de correo', 'Sitio web', 'Referido de empleado', 'Referido externo',
            'Llamada en frío', 'Feria o evento', 'LinkedIn', 'Socio', 'Publicidad',
            'Base comprada', 'Otro'];
}

/** Estados de trabajo de un posible cliente, en el orden en que se ofrecen. */
function estados_posible_cliente(): array
{
    return ['Sin contactar', 'Contactado', 'Intentamos contactar', 'Contactar más adelante',
            'Precalificado', 'No calificado', 'Basura'];
}

/** Temperatura comercial de un posible cliente. */
function calificaciones(): array
{
    return ['Caliente', 'Tibio', 'Frío'];
}

/**
 * Tipos de empresa. Salen de las claves de sectores_por_tipo() para que no
 * pueda existir un tipo sin su lista de sectores.
 */
function tipos_cuenta(): array
{
    return array_keys(sectores_por_tipo());
}

/**
 * Los sectores que tienen sentido para cada tipo de empresa.
 *
 * El sector se elige de una lista y no se escribe a mano: escrito a mano, una
 * misma industria termina cargada de cinco formas distintas —«Logistica»,
 * «logística», «Transporte y logística»—, que es lo que inutiliza un filtro.
 * La lista depende del tipo: a un proveedor no le sirven las mismas
 * categorías que a un cliente.
 */
function sectores_por_tipo(): array
{
    // Rubros de cliente: los que la empresa atiende.
    $rubros = [
        'Industria y manufactura', 'Logística y transporte', 'Comercio y retail',
        'Construcción', 'Salud', 'Educación', 'Banca y finanzas', 'Seguros',
        'Agro', 'Energía y servicios públicos', 'Hotelería y gastronomía',
        'Gobierno y sector público', 'Consorcios y countries',
        'Estudios y servicios profesionales', 'Tecnología', 'Otro',
    ];
    return [
        'Cliente'      => $rubros,
        'Prospecto'    => $rubros,
        'Socio'        => ['Integrador', 'Consultora', 'Estudio contable o jurídico',
                           'Cámara o asociación', 'Referidor', 'Otro'],
        'Distribuidor' => ['Mayorista de tecnología', 'Retail especializado',
                           'Representante de marca', 'Otro'],
        'Proveedor'    => ['Hardware y equipamiento', 'Software y licencias',
                           'Conectividad e internet', 'Obra e instalaciones',
                           'Logística y fletes', 'Servicios profesionales', 'Otro'],
        'Competidor'   => ['Soporte y servicios IT', 'Seguridad electrónica',
                           'Redes e infraestructura', 'Energías renovables', 'Otro'],
        'Otro'         => ['Otro'],
    ];
}

/**
 * ¿Qué le falta a esta ficha para estar completa?
 *
 * Son los datos sin los cuales la ficha no se puede usar para lo que existe:
 * sin apellido no se puede tratar a alguien por su nombre en una campaña, sin
 * empresa no se puede agrupar ni asignar, sin correo no se le puede escribir.
 * La ficha entra igual —perder al que llegó es peor—, pero queda señalada.
 *
 * @return string[] lo que falta, vacío si está completa
 */
function contacto_falta(array $d): array
{
    $falta = [];
    if (trim((string)($d['nombre'] ?? ''))   === '') { $falta[] = 'nombre'; }
    if (trim((string)($d['apellido'] ?? '')) === '') { $falta[] = 'apellido'; }
    if (trim((string)($d['email'] ?? ''))    === '') { $falta[] = 'correo principal'; }
    // La empresa se mira por id y no por nombre: un nombre escrito sin crear la
    // cuenta no cuenta como empresa.
    if (empty($d['cuenta_id']))                      { $falta[] = 'empresa'; }
    return $falta;
}

/**
 * Aprueba (o vuelve a dejar pendientes) varias fichas de una.
 *
 * Aprobar no completa la ficha: deja constancia de que alguien la miró y la dio
 * por buena así como está. Hay contactos que nunca van a tener empresa cargada
 * y no por eso hay que seguir viéndolos en la lista de pendientes indefinidamente.
 *
 * Se puede deshacer, y esa es la razón por la que existe el camino de vuelta:
 * una aprobación masiva hecha sobre la selección equivocada no puede ser un
 * camino de ida.
 *
 * @param string   $tabla  'contactos' o 'cuentas'
 * @param int[]    $ids    los que se marcaron en la lista
 * @param bool     $aprobar  false para devolverlos a pendientes
 * @return int cuántas fichas cambiaron
 */
function aprobar_registros(string $tabla, array $ids, bool $aprobar = true): int
{
    // Lista blanca: el nombre de la tabla va pegado en el SQL (no se puede pasar
    // como parámetro), así que sólo se aceptan estas dos.
    if (!in_array($tabla, ['contactos', 'cuentas'], true)) { return 0; }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return 0; }

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    // Las condiciones sobre aprobado_en hacen que rowCount() cuente sólo las que
    // cambiaron de verdad: aprobar una ya aprobada no suma.
    $n = $aprobar
        ? q("UPDATE `$tabla` SET aprobado_en = NOW(), aprobado_por = ?
             WHERE id IN ($marcas) AND eliminado_en IS NULL AND aprobado_en IS NULL",
            [current_user_id(), ...$ids])->rowCount()
        : q("UPDATE `$tabla` SET aprobado_en = NULL, aprobado_por = NULL
             WHERE id IN ($marcas) AND eliminado_en IS NULL AND aprobado_en IS NOT NULL",
            $ids)->rowCount();

    if ($n > 0) {
        log_app($tabla === 'contactos' ? 'contacto.aprobar' : 'cuenta.aprobar',
            ['detalle' => ($aprobar ? 'Aprobadas ' : 'Devueltas a pendientes ') . $n . ' de ' . count($ids) . ' fichas']);
    }
    return $n;
}

/**
 * Cuántas fichas esperan aprobación, por sección.
 *
 * Va al lado de su sección en la barra lateral: es el número que se mira todo
 * el día mientras se normaliza una base importada, y tenerlo que ir a buscar al
 * tablero es un viaje de ida y vuelta por cada lote que se aprueba.
 *
 * Las dos cuentas salen en una sola consulta: la barra se dibuja en TODAS las
 * pantallas, así que lo que cueste acá se paga en cada clic.
 *
 * @return array<string,int> claves 'contactos' y 'cuentas'
 */
function contar_pendientes(): array
{
    // Caché por pedido: la barra lateral puede pedirlo más de una vez.
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $pend = 'eliminado_en IS NULL AND pendiente_norm = 1 AND aprobado_en IS NULL';
    $f = q1("SELECT
              (SELECT COUNT(*) FROM contactos WHERE $pend) AS contactos,
              (SELECT COUNT(*) FROM cuentas   WHERE $pend) AS cuentas") ?: [];

    return $cache = [
        'contactos' => (int) ($f['contactos'] ?? 0),
        'cuentas'   => (int) ($f['cuentas'] ?? 0),
    ];
}

/**
 * ¿Esta fila (contacto o empresa) sigue pendiente de aprobar? Le falta algo
 * (`pendiente_norm`) y nadie la aprobó igual (`aprobado_en`). Es la misma
 * regla del filtro «Pendientes de aprobar» y del contador de la barra: los
 * listados la usan para que «Estado» no siga diciendo «Pendiente» después de
 * «Aprobar las marcadas».
 */
function ficha_pendiente(array $r): bool
{
    return !empty($r['pendiente_norm']) && empty($r['aprobado_en']);
}

/** Lo mismo para una empresa: sin CUIT no se puede facturar. */
function cuenta_falta(array $d): array
{
    $falta = [];
    if (trim((string)($d['nombre'] ?? '')) === '') { $falta[] = 'nombre'; }
    if (trim((string)($d['cuit'] ?? ''))   === '') { $falta[] = 'CUIT'; }
    if (trim((string)($d['tipo'] ?? ''))   === '') { $falta[] = 'tipo'; }
    if (trim((string)($d['sector'] ?? '')) === '') { $falta[] = 'sector'; }
    return $falta;
}

/** Estados de una actividad (tarea, llamada, reunión). */
function estados_actividad(): array
{
    return ['No iniciada', 'En curso', 'Esperando a alguien', 'Aplazada', 'Completada'];
}

/** Prioridades de una actividad, de mayor a menor. */
function prioridades(): array
{
    return ['Muy alta', 'Alta', 'Normal', 'Baja', 'Muy baja'];
}

// ------------------------------------------------------------
//  Normalización de datos
//  La base de Zoho viene con la misma provincia escrita de tres formas
//  distintas y el país en mayúsculas y minúsculas. Se normaliza al importar
//  y al guardar, para que los filtros y los reportes no se partan en dos.
// ------------------------------------------------------------

/** Quita acentos y pasa a minúsculas, para comparar sin sorpresas. */
// Lista cerrada de caracteres y no iconv/Normalizer: no depende de que el
// hosting tenga instalada la extensión intl ni del locale del servidor.
function plano(string $s): string
{
    $s = mb_strtolower(trim($s));
    $de = ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù','â','ê','î','ô','û','ã','õ','ç'];
    $a  = ['a','e','i','o','u','u','n','a','e','i','o','u','a','e','i','o','u','a','o','c'];
    return str_replace($de, $a, $s);
}

/** Lleva una provincia a su forma canónica. Devuelve el original si no la reconoce. */
function normalizar_provincia(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') { return null; }
    $p = plano($v);
    // Alias reales encontrados en la exportación de Zoho.
    // La comparación es contra el texto plano (sin acentos, en minúsculas): por
    // eso las claves del alias van así escritas.
    $alias = [
        'caba' => 'Ciudad de Buenos Aires', 'c.a.b.a.' => 'Ciudad de Buenos Aires',
        'capital federal' => 'Ciudad de Buenos Aires', 'ciudad autonoma de buenos aires' => 'Ciudad de Buenos Aires',
        'ciudad de buenos aires' => 'Ciudad de Buenos Aires', 'federal district' => 'Ciudad de Buenos Aires',
        'bs as' => 'Buenos Aires', 'bs. as.' => 'Buenos Aires', 'pcia de buenos aires' => 'Buenos Aires',
    ];
    if (isset($alias[$p])) { return $alias[$p]; }
    foreach (provincias_ar() as $canon) {
        if (plano($canon) === $p) { return $canon; }
    }
    return $v;
}

/**
 * Lleva un país a su forma canónica. Descarta la basura (URLs, textos sueltos).
 *
 * Lo que no está en el mapa se devuelve tal como vino (recortado de espacios):
 * no se inventa un país, sólo se unifican las grafías conocidas.
 */
function normalizar_pais(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') { return null; }
    if (preg_match('#^https?://#i', $v) || mb_strlen($v) > 40) {
        return null;   // en la base de Zoho hay URLs cargadas en el campo país
    }
    $mapa = [
        'argentina' => 'Argentina', 'republica argentina' => 'Argentina', 'ar' => 'Argentina',
        'mexico' => 'México', 'méxico' => 'México', 'chile' => 'Chile', 'colombia' => 'Colombia',
        'brazil' => 'Brasil', 'brasil' => 'Brasil', 'peru' => 'Perú', 'uruguay' => 'Uruguay',
        'spain' => 'España', 'españa' => 'España', 'paraguay' => 'Paraguay', 'bolivia' => 'Bolivia',
        'estados unidos' => 'Estados Unidos', 'united states' => 'Estados Unidos', 'usa' => 'Estados Unidos',
    ];
    return $mapa[plano($v)] ?? $v;
}

/**
 * Limpia una localidad. En la exportación hay filas con listas de aptitudes de
 * LinkedIn o direcciones completas metidas en este campo: lo que no parece un
 * nombre de ciudad se descarta en vez de ensuciar la base.
 */
function normalizar_localidad(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') { return null; }
    // Más de 60 caracteres o dos comas ya es una dirección o una lista, no una
    // localidad. Se pierde el dato a propósito: es basura conocida de Zoho.
    if (mb_strlen($v) > 60 || substr_count($v, ',') >= 2) { return null; }
    if (preg_match('#^https?://#i', $v)) { return null; }
    return $v;
}

/**
 * Caracteres que puede tener un teléfono escrito a mano.
 *
 * Sirve para el atributo `pattern` de los formularios y para validar del lado
 * del servidor: la misma regla en los dos lados, así no puede pasar que la
 * pantalla acepte algo que después la base rechaza.
 */
const TELEFONO_PATRON = '[0-9+()./\\s-]{6,40}';

/**
 * ¿Esto parece un teléfono?
 *
 * Se comprueba en el servidor porque `type="tel"` NO valida nada: es sólo una
 * pista para que el celular abra el teclado numérico. Con ese atributo puesto
 * se pueden escribir letras igual, y así se estaban guardando.
 */
function telefono_valido(?string $v): bool
{
    $v = trim((string)$v);
    if ($v === '') { return true; }                       // vacío es válido: el campo es opcional
    if (preg_match('/[^0-9+().\/\s-]/', $v)) { return false; }   // una letra lo descarta
    return strlen(preg_replace('/\D/', '', $v) ?? '') >= 6;      // y al menos seis dígitos
}

/**
 * Normaliza un teléfono conservando el formato legible que ya usa el equipo.
 *
 * Lo que no parece un teléfono se descarta en vez de guardarse a medias: es
 * preferible el campo vacío antes que un dato que nadie va a poder marcar.
 */
function normalizar_telefono(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') { return null; }
    if (!telefono_valido($v)) { return null; }
    // Recorte a 60: es el largo de las columnas de teléfono en la base.
    return mb_substr($v, 0, 60);
}

// ------------------------------------------------------------
//  Cuentas (empresas)
// ------------------------------------------------------------

/**
 * Una cuenta viva con el nombre de su propietario. Null si no existe o está
 * en la papelera.
 */
function cuenta_get(int $id): ?array
{
    return q1('SELECT c.*, u.nombre AS propietario
               FROM cuentas c LEFT JOIN usuarios u ON u.id = c.propietario_id
               WHERE c.id = ? AND c.eliminado_en IS NULL', [$id]);
}

/**
 * Busca una cuenta por nombre exacto (sin distinguir mayúsculas ni acentos).
 *
 * Lo de mayúsculas y acentos lo resuelve la intercalación utf8mb4_unicode_ci
 * de la columna, no el código. No unifica formas societarias ni espacios
 * dobles: «ACME S.A.» y «Acme SA» son dos empresas distintas para esta
 * función (para eso está empresa_clave(), que usa sólo la entrada web).
 */
function cuenta_por_nombre(string $nombre): ?array
{
    $nombre = trim($nombre);
    if ($nombre === '') { return null; }
    return q1('SELECT * FROM cuentas WHERE nombre = ? AND eliminado_en IS NULL LIMIT 1', [$nombre]);
}

/**
 * Otra empresa viva con el mismo nombre, escrito igual o de otra forma
 * («ACME S.A.» y «Acme SA»: misma empresa_clave()). Null si no hay.
 *
 * Es el control del formulario de empresa. Igual que cuenta_asegurar_web(),
 * para no traer las nueve mil empresas sólo se comparan las que empiezan con
 * la primera palabra de la clave.
 *
 * @param int $exceptoId la empresa que se está editando (0 en un alta)
 */
function cuenta_repetida(string $nombre, int $exceptoId = 0): ?array
{
    $nombre = trim($nombre);
    if ($nombre === '') { return null; }
    $igual = q1('SELECT id, nombre FROM cuentas WHERE nombre = ? AND eliminado_en IS NULL AND id <> ? LIMIT 1',
                [$nombre, $exceptoId]);
    if ($igual) { return $igual; }
    $clave = empresa_clave($nombre);
    if ($clave === '') { return null; }
    $primera = explode(' ', $clave)[0];
    foreach (q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL AND id <> ? AND nombre LIKE ?
                ORDER BY id LIMIT 200', [$exceptoId, like_literal($primera) . '%'])->fetchAll() as $c) {
        if (empresa_clave((string)$c['nombre']) === $clave) { return $c; }
    }
    return null;
}

/**
 * Devuelve el id de la cuenta con ese nombre, creándola si no existe.
 * Es lo que evita que la importación duplique empresas.
 *
 * Si ya existe, sólo se completan los campos vacíos con lo que venga en $extra
 * (nunca se pisa un dato cargado). Si no existe, se crea con pendiente_norm = 1.
 *
 * @param string   $nombre    nombre tal como se quiere la empresa
 * @param array    $extra     sitio_web, telefono, calle, localidad, provincia,
 *                            codigo_postal, pais, sector, zoho_id, propietario_id
 * @param int|null $usuarioId quien figura como creador y, si $extra no trae
 *                            propietario, como dueño; null = el usuario actual
 * @return int|null id de la cuenta, o null si el nombre vino vacío
 */
function cuenta_asegurar(string $nombre, array $extra = [], ?int $usuarioId = null): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') { return null; }
    $c = cuenta_por_nombre($nombre);
    if ($c) {
        // Completar los huecos sin pisar lo que ya está cargado.
        $sets = [];
        $vals = [];
        // Los nombres de columna salen de esta lista fija, por eso se pueden pegar en
        // el UPDATE sin riesgo.
        foreach (['sitio_web', 'telefono', 'localidad', 'provincia', 'pais', 'codigo_postal', 'calle', 'sector'] as $campo) {
            if (!empty($extra[$campo]) && empty($c[$campo])) {
                $sets[] = "$campo = ?";
                $vals[] = $extra[$campo];
            }
        }
        if ($sets) {
            $vals[] = (int)$c['id'];
            q('UPDATE cuentas SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        }
        return (int)$c['id'];
    }
    // Esta empresa nace de un nombre suelto: la crea la importación, la ficha de
    // un contacto o alguien que reservó una reunión y escribió dónde trabaja.
    // Nunca trae CUIT ni tipo, así que nace pendiente de completar. Es
    // exactamente para eso que existe el filtro: para poder encontrarlas.
    q('INSERT INTO cuentas (nombre, propietario_id, sitio_web, telefono, calle, localidad,
                            provincia, codigo_postal, pais, sector, zoho_id, creado_por,
                            pendiente_norm)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)', [
        $nombre,
        ($extra['propietario_id'] ?? null) ?: ($usuarioId ?: null),
        $extra['sitio_web'] ?? null,
        $extra['telefono'] ?? null,
        $extra['calle'] ?? null,
        $extra['localidad'] ?? null,
        $extra['provincia'] ?? null,
        $extra['codigo_postal'] ?? null,
        $extra['pais'] ?? null,
        $extra['sector'] ?? null,
        $extra['zoho_id'] ?? null,
        $usuarioId ?? current_user_id(),
    ]);
    return last_id();
}

/**
 * WHERE + parámetros de los filtros del listado de cuentas.
 *
 * @param array $f q, propietario_id, provincia, tipo, sector, pendiente ('1'/'0')
 * @return array{0:string,1:array} [cláusula WHERE, parámetros]
 */
function cuentas_where(array $f): array
{
    $w = ['c.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['q'])) {
        $w[] = '(c.nombre LIKE ? OR c.sitio_web LIKE ? OR c.telefono LIKE ? OR c.cuit LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if (!empty($f['propietario_id'])) { $w[] = 'c.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['provincia']))      { $w[] = 'c.provincia = ?';      $p[] = $f['provincia']; }
    if (!empty($f['tipo']))           { $w[] = 'c.tipo = ?';           $p[] = $f['tipo']; }
    if (!empty($f['sector']))         { $w[] = 'c.sector = ?';         $p[] = $f['sector']; }
    // Pendiente = le falta algo Y nadie la aprobó igual. Una ficha incompleta
    // que alguien miró y dio por buena deja de pedir atención.
    if (($f['pendiente'] ?? '') === '1') { $w[] = 'c.pendiente_norm = 1 AND c.aprobado_en IS NULL'; }
    if (($f['pendiente'] ?? '') === '0') { $w[] = '(c.pendiente_norm = 0 OR c.aprobado_en IS NOT NULL)'; }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * Listado paginado de cuentas con la cantidad de contactos de cada una.
 *
 * @return array lo de paginar() más 'rows' y 'total'
 */
function cuentas_listar(array $f): array
{
    [$where, $p] = cuentas_where($f);
    $total = (int) qv("SELECT COUNT(*) FROM cuentas c $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    // El conteo de contactos va como subconsulta correlacionada: corre una vez por
    // fila de la página (no por toda la tabla), y usa el índice de cuenta_id.
    $rows  = q("SELECT c.id, c.nombre, c.cuit, c.tipo, c.sector, c.telefono,
                       c.localidad, c.provincia, c.sitio_web, c.pendiente_norm, c.aprobado_en, c.propietario_id,
                       u.nombre AS propietario,
                       (SELECT COUNT(*) FROM contactos ct WHERE ct.cuenta_id = c.id AND ct.eliminado_en IS NULL) AS n_contactos
                FROM cuentas c LEFT JOIN usuarios u ON u.id = c.propietario_id
                $where ORDER BY c.nombre LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Alta o edición de una cuenta. Devuelve el id.
 *
 * Normaliza teléfono, correo, CUIT (sólo dígitos), localidad, provincia y
 * país, y recalcula pendiente_norm. En la edición pisa TODOS los campos con lo
 * que vino en $d: lo que no venga queda en null, así que hay que mandarle la
 * ficha completa (lo hace el formulario de empresa).
 * Efectos: escribe en `cuentas` y deja constancia en el log.
 */
function cuenta_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'         => trim((string)($d['nombre'] ?? '')),
        'propietario_id' => ($d['propietario_id'] ?? null) ?: null,
        'tipo'           => ($d['tipo'] ?? null) ?: null,
        'sector'         => ($d['sector'] ?? null) ?: null,
        'sitio_web'      => ($d['sitio_web'] ?? null) ?: null,
        'telefono'       => normalizar_telefono($d['telefono'] ?? null),
        'email'          => normalizar_email($d['email'] ?? '') ?: null,
        'cuit'           => ($c = normalizar_cuit($d['cuit'] ?? '')) !== '' ? $c : null,
        'condicion_iva'  => ($d['condicion_iva'] ?? null) ?: null,
        // Sin la clave (o vacía) queda null, no 0.
        'empleados'      => ($d['empleados'] ?? '') !== '' ? (int)$d['empleados'] : null,
        'calle'          => ($d['calle'] ?? null) ?: null,
        'localidad'      => normalizar_localidad($d['localidad'] ?? null),
        'provincia'      => normalizar_provincia($d['provincia'] ?? null),
        'codigo_postal'  => ($d['codigo_postal'] ?? null) ?: null,
        'pais'           => normalizar_pais($d['pais'] ?? null),
        'linkedin'       => ($d['linkedin'] ?? null) ?: null,
        'descripcion'    => ($d['descripcion'] ?? null) ?: null,
    ];
    // Igual que en los contactos: la empresa entra aunque le falte algo, pero
    // queda señalada para poder completarla después. Sin CUIT no se factura y
    // sin sector no se puede segmentar una campaña.
    $campos['pendiente_norm'] = cuenta_falta($campos) ? 1 : 0;

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE cuentas SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('cuenta.editar', ['entidad' => 'cuenta', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }
    // Quien crea la ficha figura como creador y, si no se eligió otro, como dueño.
    $campos['creado_por'] = current_user_id();
    if (empty($campos['propietario_id'])) { $campos['propietario_id'] = current_user_id(); }
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO cuentas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('cuenta.crear', ['entidad' => 'cuenta', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

/**
 * Totales de los negocios de una empresa para las tarjetas de su ficha:
 * embudo abierto y ganado, cada uno separado por moneda (no se convierte:
 * un negocio en dólares no se suma a los pesos).
 *
 * @param array $negocios filas con importe, moneda y etapa_tipo (abierta/ganada/perdida)
 * @return array{abierto:array<string,float>, ganado:array<string,float>} moneda => total
 */
function cuenta_totales_negocios(array $negocios): array
{
    $t = ['abierto' => [], 'ganado' => []];
    foreach ($negocios as $n) {
        $clave = ['abierta' => 'abierto', 'ganada' => 'ganado'][$n['etapa_tipo'] ?? ''] ?? null;
        if (!$clave) { continue; }
        $mon = strtoupper((string)(($n['moneda'] ?? '') ?: 'ARS'));
        $t[$clave][$mon] = ($t[$clave][$mon] ?? 0.0) + (float)$n['importe'];
    }
    ksort($t['abierto']);
    ksort($t['ganado']);
    return $t;
}

// ------------------------------------------------------------
//  Contactos
// ------------------------------------------------------------

/**
 * Un contacto vivo con el nombre de su empresa y de su propietario. Null si
 * no existe o está en la papelera.
 */
function contacto_get(int $id): ?array
{
    return q1('SELECT ct.*, cu.nombre AS cuenta, u.nombre AS propietario
               FROM contactos ct
               LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
               LEFT JOIN usuarios u ON u.id = ct.propietario_id
               WHERE ct.id = ? AND ct.eliminado_en IS NULL', [$id]);
}

/**
 * WHERE + parámetros de los filtros del listado de contactos.
 *
 * La búsqueda libre mira nombre, el correo principal y el secundario, la
 * empresa, los teléfonos y el cargo. Usa `cu.nombre`, así que la consulta que
 * lo use tiene que unir `cuentas cu`.
 *
 * @param array $f q, cuenta_id, propietario_id, provincia, fuente, con_email,
 *                 no_email, rebotado, pendiente ('1'/'0')
 * @return array{0:string,1:array} [cláusula WHERE, parámetros]
 */
function contactos_where(array $f): array
{
    $w = ['ct.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['q'])) {
        $w[] = '(ct.nombre_completo LIKE ? OR ct.email LIKE ? OR ct.email2 LIKE ? OR cu.nombre LIKE ?
                 OR ct.telefono LIKE ? OR ct.movil LIKE ? OR ct.conmutador LIKE ? OR ct.cargo LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($f['cuenta_id']))      { $w[] = 'ct.cuenta_id = ?';      $p[] = (int)$f['cuenta_id']; }
    if (!empty($f['propietario_id'])) { $w[] = 'ct.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['provincia']))      { $w[] = 'ct.provincia = ?';      $p[] = $f['provincia']; }
    if (!empty($f['fuente']))         { $w[] = 'ct.fuente = ?';         $p[] = $f['fuente']; }
    if (($f['con_email'] ?? '') === '1') { $w[] = "ct.email IS NOT NULL AND ct.email <> ''"; }
    if (($f['no_email']  ?? '') === '1') { $w[] = 'ct.no_email = 1'; }
    if (($f['rebotado']  ?? '') === '1') { $w[] = 'ct.email_rebotado = 1'; }
    // Fichas a las que les falta algo de lo que pedimos siempre. Es el filtro
    // que permite ir completando la base de a poco en vez de descubrir el
    // agujero el día que hace falta el dato.
    if (($f['pendiente'] ?? '') === '1') { $w[] = 'ct.pendiente_norm = 1 AND ct.aprobado_en IS NULL'; }
    if (($f['pendiente'] ?? '') === '0') { $w[] = '(ct.pendiente_norm = 0 OR ct.aprobado_en IS NOT NULL)'; }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * Listado paginado de contactos.
 *
 * @param array $f los filtros de contactos_where() más 'page' y 'orden'
 *                 ('reciente', 'empresa' o por apellido)
 * @return array lo de paginar() más 'rows' y 'total'
 */
function contactos_listar(array $f): array
{
    [$where, $p] = contactos_where($f);
    $sql = "FROM contactos ct
            LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
            LEFT JOIN usuarios u ON u.id = ct.propietario_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    // El orden sale de un match con valores fijos: lo que manda el usuario nunca
    // llega al ORDER BY.
    $orden = match ($f['orden'] ?? '') {
        'reciente' => 'ct.creado_en DESC',
        'empresa'  => 'cu.nombre, ct.apellido',
        default    => 'ct.apellido, ct.nombre',
    };
    // Se piden SOLO las columnas que el listado dibuja, más las que necesita
    // contacto_falta() para decir qué le falta a la ficha. Con ct.* venían
    // también la descripción y las notas —texto largo que no se muestra—, y a
    // 100 filas por página eso es traer de la base lo que se va a tirar.
    $rows = q("SELECT ct.id, ct.nombre, ct.apellido, ct.nombre_completo, ct.email, ct.cargo,
                      ct.telefono, ct.movil, ct.conmutador, ct.localidad, ct.provincia,
                      ct.cuenta_id, ct.propietario_id, ct.no_email, ct.email_rebotado, ct.pendiente_norm, ct.aprobado_en,
                      cu.nombre AS cuenta, u.nombre AS propietario $sql
               ORDER BY $orden LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Alta o edición de un contacto. Devuelve el id.
 *
 * Lo usan el formulario de contacto, la importación de Zoho, la conversión de
 * posibles clientes y la entrada web.
 *
 * Claves especiales de $d:
 *   · `no_email`: sólo se toca si viene (ver más abajo);
 *   · `_solo_completar`: en la edición, los campos vacíos no pisan lo cargado.
 *
 * Sin `_solo_completar`, la edición pisa todos los campos con lo que vino.
 * Efectos: escribe en `contactos`, alinea las listas de campaña con el correo
 * nuevo (si el módulo está cargado) y deja constancia en el log.
 * No recorta los textos al largo de las columnas: un valor más largo (un cargo
 * de 200 caracteres, por ejemplo) hace fallar el INSERT/UPDATE.
 */
function contacto_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'         => trim((string)($d['nombre'] ?? '')) ?: null,
        'apellido'       => trim((string)($d['apellido'] ?? '')),
        'cuenta_id'      => ($d['cuenta_id'] ?? null) ?: null,
        'propietario_id' => ($d['propietario_id'] ?? null) ?: null,
        'cargo'          => ($d['cargo'] ?? null) ?: null,
        'departamento'   => ($d['departamento'] ?? null) ?: null,
        'email'          => normalizar_email($d['email'] ?? '') ?: null,
        'email2'         => normalizar_email($d['email2'] ?? '') ?: null,
        'email3'         => normalizar_email($d['email3'] ?? '') ?: null,
        'telefono'       => normalizar_telefono($d['telefono'] ?? null),
        'movil'          => normalizar_telefono($d['movil'] ?? null),
        'conmutador'     => normalizar_telefono($d['conmutador'] ?? null),
        'fuente'         => ($d['fuente'] ?? null) ?: null,
        'linkedin'       => ($d['linkedin'] ?? null) ?: null,
        'sitio_web'      => ($d['sitio_web'] ?? null) ?: null,
        'calle'          => ($d['calle'] ?? null) ?: null,
        'localidad'      => normalizar_localidad($d['localidad'] ?? null),
        'provincia'      => normalizar_provincia($d['provincia'] ?? null),
        'codigo_postal'  => ($d['codigo_postal'] ?? null) ?: null,
        'pais'           => normalizar_pais($d['pais'] ?? null),
        'descripcion'    => ($d['descripcion'] ?? null) ?: null,
    ];

    // «No incluir en campañas» sólo se toca si vino en los datos. Es una
    // decisión de la persona, no un campo más: la importación no manda esta
    // clave, y ponerla en cero por defecto hacía que cada reimportación borrara
    // todas las bajas que la gente había pedido.
    if (array_key_exists('no_email', $d)) {
        $campos['no_email'] = !empty($d['no_email']) ? 1 : 0;
    }

    // Queda señalado si le falta algo de lo que pedimos siempre. Se calcula
    // sobre los campos que se van a guardar, no sobre lo que llegó: con
    // `_solo_completar` puede completarse más abajo con lo que ya había.
    $campos['pendiente_norm'] = contacto_falta($campos) ? 1 : 0;

    if ($id) {
        // Con `_solo_completar` no se pisa lo que ya está cargado con un valor
        // vacío. Importar un archivo con menos columnas que la ficha borraba en
        // silencio teléfonos, cargos y direcciones que ya estaban, mientras la
        // pantalla informaba «N actualizados» como si todo hubiera ido bien.
        if (!empty($d['_solo_completar'])) {
            // Se lee también si está en la papelera: el UPDATE no filtra eliminado_en.
            $antes = q1('SELECT * FROM contactos WHERE id = ?', [$id]) ?: [];
            foreach ($campos as $k => $v) {
                if (($v === null || $v === '') && isset($antes[$k]) && $antes[$k] !== null && $antes[$k] !== '') {
                    $campos[$k] = $antes[$k];
                }
            }
        }
        // Se recalcula: el bloque de arriba pudo completar campos que faltaban.
        $campos['pendiente_norm'] = contacto_falta($campos) ? 1 : 0;
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE contactos SET $sets WHERE id = ?", [...array_values($campos), $id]);

        // Las listas guardan una copia del correo del día que se armaron. Si no
        // se alinea acá, cambiar un correo no cambia nada donde importa: la
        // lista sigue mostrando el viejo y la campaña le escribe a ése.
        if (function_exists('lista_sincronizar_contacto')) {
            lista_sincronizar_contacto($id);
        }
        log_app('contacto.editar', ['entidad' => 'contacto', 'entidad_id' => $id,
            'detalle' => trim($campos['nombre'] . ' ' . $campos['apellido'])]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    // Sin propietario, la ficha no aparece en «Lo mío» de nadie y queda
    // flotando: si no se eligió uno, se queda quien la está creando.
    if (empty($campos['propietario_id'])) { $campos['propietario_id'] = current_user_id(); }
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO contactos ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('contacto.crear', ['entidad' => 'contacto', 'entidad_id' => $nuevo,
        'detalle' => trim($campos['nombre'] . ' ' . $campos['apellido'])]);
    return $nuevo;
}

// ------------------------------------------------------------
//  Posibles clientes (leads)
// ------------------------------------------------------------

/**
 * Un posible cliente vivo con el nombre de su propietario. Null si no existe
 * o está en la papelera (los convertidos sí se devuelven).
 */
function posible_get(int $id): ?array
{
    return q1('SELECT pc.*, u.nombre AS propietario
               FROM posibles_clientes pc LEFT JOIN usuarios u ON u.id = pc.propietario_id
               WHERE pc.id = ? AND pc.eliminado_en IS NULL', [$id]);
}

/**
 * WHERE + parámetros de los filtros de la bandeja de posibles clientes.
 *
 * @param array $f convertidos ('1' para incluirlos), q, estado, fuente,
 *                 propietario_id, calificacion
 * @return array{0:string,1:array} [cláusula WHERE, parámetros]
 */
function posibles_where(array $f): array
{
    $w = ['pc.eliminado_en IS NULL'];
    $p = [];
    // Por defecto sólo se listan los que todavía no se convirtieron: los
    // convertidos ya viven como contacto y ensucian la lista de trabajo.
    if (($f['convertidos'] ?? '') !== '1') { $w[] = 'pc.convertido_en IS NULL'; }
    if (!empty($f['q'])) {
        $w[] = '(pc.nombre LIKE ? OR pc.apellido LIKE ? OR pc.empresa LIKE ? OR pc.email LIKE ? OR pc.telefono LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like, $like);
    }
    if (!empty($f['estado']))         { $w[] = 'pc.estado = ?';         $p[] = $f['estado']; }
    if (!empty($f['fuente']))         { $w[] = 'pc.fuente = ?';         $p[] = $f['fuente']; }
    if (!empty($f['propietario_id'])) { $w[] = 'pc.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['calificacion']))   { $w[] = 'pc.calificacion = ?';   $p[] = $f['calificacion']; }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * Listado paginado de posibles clientes, los de mayor puntaje primero.
 *
 * @return array lo de paginar() más 'rows' y 'total'
 */
function posibles_listar(array $f): array
{
    [$where, $p] = posibles_where($f);
    $sql   = "FROM posibles_clientes pc LEFT JOIN usuarios u ON u.id = pc.propietario_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT pc.*, u.nombre AS propietario $sql
                ORDER BY pc.puntaje DESC, pc.creado_en DESC
                LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Alta o edición de un posible cliente. Devuelve el id.
 *
 * A diferencia de contacto_guardar(), acá `no_email` se escribe siempre (un
 * formulario que no lo manda lo pone en 0) y no hay modo «sólo completar».
 * El estado arranca en «Sin contactar» si no se indica otro.
 * Efectos: escribe en `posibles_clientes` y deja constancia en el log.
 */
function posible_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'         => trim((string)($d['nombre'] ?? '')) ?: null,
        'apellido'       => trim((string)($d['apellido'] ?? '')),
        'empresa'        => trim((string)($d['empresa'] ?? '')),
        'cargo'          => ($d['cargo'] ?? null) ?: null,
        'propietario_id' => ($d['propietario_id'] ?? null) ?: null,
        'email'          => normalizar_email($d['email'] ?? '') ?: null,
        'email2'         => normalizar_email($d['email2'] ?? '') ?: null,
        'telefono'       => normalizar_telefono($d['telefono'] ?? null),
        'movil'          => normalizar_telefono($d['movil'] ?? null),
        'conmutador'     => normalizar_telefono($d['conmutador'] ?? null),
        'sitio_web'      => ($d['sitio_web'] ?? null) ?: null,
        'linkedin'       => ($d['linkedin'] ?? null) ?: null,
        'fuente'         => ($d['fuente'] ?? null) ?: null,
        'estado'         => ($d['estado'] ?? null) ?: 'Sin contactar',
        'calificacion'   => ($d['calificacion'] ?? null) ?: null,
        'sector'         => ($d['sector'] ?? null) ?: null,
        'empleados'      => ($d['empleados'] ?? '') !== '' ? (int)($d['empleados'] ?? 0) : null,
        'calle'          => ($d['calle'] ?? null) ?: null,
        'localidad'      => normalizar_localidad($d['localidad'] ?? null),
        'provincia'      => normalizar_provincia($d['provincia'] ?? null),
        'codigo_postal'  => ($d['codigo_postal'] ?? null) ?: null,
        'pais'           => normalizar_pais($d['pais'] ?? null),
        'descripcion'    => ($d['descripcion'] ?? null) ?: null,
        'no_email'       => !empty($d['no_email']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE posibles_clientes SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('posible.editar', ['entidad' => 'posible_cliente', 'entidad_id' => $id,
            'detalle' => trim($campos['nombre'] . ' ' . $campos['apellido']) . ' · ' . $campos['empresa']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO posibles_clientes ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('posible.crear', ['entidad' => 'posible_cliente', 'entidad_id' => $nuevo,
        'detalle' => trim($campos['nombre'] . ' ' . $campos['apellido']) . ' · ' . $campos['empresa']]);
    return $nuevo;
}

/**
 * Monedas en que se puede cargar el negocio de una conversión. No se
 * convierte entre ellas: cada importe queda en la suya.
 */
function conversion_monedas(): array
{
    return ['ARS' => 'Pesos (ARS)', 'USD' => 'Dólares (USD)'];
}

/**
 * Lee un importe escrito a mano, con la costumbre argentina o la inglesa.
 *
 *   · «1.500.000» → 1500000 (puntos de miles);
 *   · «1.234,56»  → 1234.56 (miles con punto, decimales con coma);
 *   · «1,234.56»  → 1234.56 (al revés);
 *   · «1500,5»    → 1500.5  (una sola coma: decimal);
 *   · «1.500»     → 1500    (un punto seguido de tres cifras: miles);
 *   · «1500.50»   → 1500.5.
 * Vacío vale 0. Acepta el signo $, US$, ARS o USD adelante.
 *
 * Antes se cambiaba la coma por punto y se pasaba por (float): «1.234,56»
 * quedaba «1.234.56» y se guardaba 1.234; «1.500.000» se guardaba 1.5.
 *
 * @return float|null null si no es un importe (letras, negativo, dos
 *                    separadores decimales o más grande que DECIMAL(15,2))
 */
function conversion_importe(string $txt): ?float
{
    $s = trim($txt);
    $s = (string) preg_replace('/^(US\$|U\$S|\$|ARS|USD)\s*/i', '', $s);
    $s = str_replace([' ', "\u{00A0}"], '', $s);
    if ($s === '') { return 0.0; }
    if (!preg_match('/^\d[\d.,]*$/', $s)) { return null; }

    $puntos = substr_count($s, '.');
    $comas  = substr_count($s, ',');
    if ($puntos && $comas) {
        // El separador que aparece último es el decimal; el otro, el de miles.
        $dec = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
        $mil = $dec === ',' ? '.' : ',';
        if (substr_count($s, $dec) > 1) { return null; }
        $entero = substr($s, 0, (int) strrpos($s, $dec));
        if (!preg_match('/^\d{1,3}(' . preg_quote($mil, '/') . '\d{3})*$/', $entero)) { return null; }
        $s = str_replace($dec, '.', str_replace($mil, '', $s));
    } elseif ($comas) {
        if ($comas > 1) {
            if (!preg_match('/^\d{1,3}(,\d{3})+$/', $s)) { return null; }
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
    } elseif ($puntos) {
        if ($puntos > 1 || preg_match('/^\d{1,3}\.\d{3}$/', $s)) {
            if (!preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) { return null; }
            $s = str_replace('.', '', $s);
        }
    }
    if (!is_numeric($s)) { return null; }
    $n = round((float) $s, 2);
    return $n < 1e13 ? $n : null;
}

/**
 * Valida y normaliza las opciones del negocio de una conversión.
 *
 * @param array $in crear_negocio, negocio_nombre, negocio_importe (texto o
 *                  número), negocio_moneda, negocio_cierre (Y-m-d o vacío)
 * @return array{0:array,1:array} [opciones normalizadas, errores campo => mensaje]
 */
function conversion_opciones(array $in): array
{
    $op = [
        'crear_negocio'   => !empty($in['crear_negocio']),
        'negocio_nombre'  => mb_substr(trim((string)($in['negocio_nombre'] ?? '')), 0, 200),
        'negocio_importe' => 0.0,
        'negocio_moneda'  => 'ARS',
        'negocio_cierre'  => null,
    ];
    $err = [];
    if (!$op['crear_negocio']) { return [$op, $err]; }

    $imp = $in['negocio_importe'] ?? '';
    $imp = (is_int($imp) || is_float($imp)) ? ($imp >= 0 ? round((float)$imp, 2) : null)
                                            : conversion_importe((string)$imp);
    if ($imp === null) {
        $err['negocio_importe'] = 'El importe no se entiende: escribilo como 1.500.000 o 1500000,50.';
    } else {
        $op['negocio_importe'] = $imp;
    }

    $mon = strtoupper(trim((string)($in['negocio_moneda'] ?? 'ARS')));
    if (!isset(conversion_monedas()[$mon])) {
        $err['negocio_moneda'] = 'La moneda tiene que ser pesos (ARS) o dólares (USD).';
    } else {
        $op['negocio_moneda'] = $mon;
    }

    $cierre = trim((string)($in['negocio_cierre'] ?? ''));
    if ($cierre !== '') {
        $f = DateTime::createFromFormat('!Y-m-d', $cierre);
        if (!$f || $f->format('Y-m-d') !== $cierre) {
            $err['negocio_cierre'] = 'La fecha de cierre no es válida.';
        } else {
            $op['negocio_cierre'] = $cierre;
        }
    }
    return [$op, $err];
}

/**
 * Primera etapa activa del embudo predeterminado: donde arranca el negocio
 * de una conversión. Null si no hay embudo predeterminado con etapas activas.
 */
function conversion_etapa_inicial(): ?array
{
    return q1('SELECT e.id, e.embudo_id, e.probabilidad FROM etapas e
               JOIN embudos em ON em.id = e.embudo_id AND em.predeterminado = 1
               WHERE e.activo = 1 ORDER BY e.orden LIMIT 1');
}

/**
 * El mensaje de «convertido» según lo que pasó de verdad: empresa creada,
 * reutilizada o ninguna (posible cliente sin empresa); contacto creado o
 * reutilizado; negocio creado, o pedido y no creado por falta de embudo.
 *
 * @param array $r lo que devuelve posible_convertir()
 */
function conversion_mensaje(array $r): string
{
    $partes = [];
    if (empty($r['cuenta_id'])) {
        $partes[] = 'quedó sin empresa (el posible cliente no tenía una)';
    } else {
        $partes[] = !empty($r['cuenta_nueva']) ? 'se creó la empresa' : 'se usó la empresa que ya existía';
    }
    $partes[] = !empty($r['contacto_nuevo']) ? 'se creó el contacto' : 'se completó el contacto que ya existía';
    if (!empty($r['negocio_id'])) { $partes[] = 'se creó el negocio'; }
    $msg = 'Convertido: ' . implode(', ', $partes) . '.';
    if (!empty($r['negocio_omitido'])) {
        $msg .= ' El negocio no se creó porque no hay un embudo predeterminado con etapas activas.';
    }
    return $msg;
}

/**
 * Convierte un posible cliente en Cuenta + Contacto (+ Negocio opcional).
 *
 * Es la operación central del embudo. Reglas:
 *   · La cuenta se reutiliza si ya existe una con ese nombre (no se duplica).
 *     Una cuenta reutilizada sólo se completa en lo que tenga vacío.
 *   · El contacto se reutiliza si ya existe uno con ese correo. Se le completa
 *     lo que tenga vacío (nunca se pisa un dato cargado) y, si no tenía
 *     empresa, se lo enlaza a ésta; si ya tenía otra, la conserva.
 *   · Las notas, actividades y adjuntos del posible cliente pasan al
 *     contacto; las etiquetas se copian. Lo que no tiene lugar en la ficha del
 *     contacto (calificación, sector o empleados que no entraron en la
 *     empresa, notas del registro) queda en una nota del contacto.
 *   · El posible cliente no se borra ni cambia de estado: queda marcado como
 *     convertido, con los ids de lo que generó.
 *
 * Doble conversión: lo primero que se hace dentro de la transacción es
 * marcar `convertido_en` con un UPDATE condicional (WHERE convertido_en IS
 * NULL). El segundo envío (doble clic, otra pestaña) espera el bloqueo de la
 * fila, no encuentra nada que actualizar y termina con el código 409.
 *
 * @param array $opciones las de conversion_opciones() (se vuelven a validar)
 * @return array{cuenta_id:?int, contacto_id:int, negocio_id:?int, cuenta_nueva:bool,
 *               contacto_nuevo:bool, negocio_omitido:bool}
 * @throws RuntimeException código 404 si no existe, 409 si ya se convirtió
 * @throws InvalidArgumentException si las opciones del negocio no son válidas
 */
function posible_convertir(int $id, array $opciones = []): array
{
    $pc = posible_get($id);
    if (!$pc) {
        throw new RuntimeException('El posible cliente no existe.', 404);
    }
    if (!empty($pc['convertido_en'])) {
        throw new RuntimeException('Este posible cliente ya se convirtió.', 409);
    }
    [$op, $errOp] = conversion_opciones($opciones);
    if ($errOp) {
        throw new InvalidArgumentException((string) reset($errOp));
    }

    db()->beginTransaction();
    try {
        // 0) Tomar la fila: sólo un envío puede pasar de acá.
        $tomada = q('UPDATE posibles_clientes SET convertido_en = NOW(), convertido_por = ?
                     WHERE id = ? AND convertido_en IS NULL AND eliminado_en IS NULL',
                    [current_user_id(), $id])->rowCount();
        if ($tomada !== 1) {
            throw new RuntimeException('Este posible cliente ya se convirtió.', 409);
        }
        // Se relee con la fila ya bloqueada: lo de antes pudo cambiar.
        $pc = q1('SELECT * FROM posibles_clientes WHERE id = ?', [$id]) ?: $pc;
        $paraNota = [];

        // 1) Cuenta. Sin empresa (la columna admite '') no se crea ninguna y el
        // contacto queda suelto; el mensaje lo dice.
        // El sector del posible cliente es texto libre; en la empresa es una
        // lista cerrada, así que sólo se copia si es uno de la lista.
        $sectoresValidos = array_unique(array_merge(...array_values(sectores_por_tipo())));
        $sectorOk = in_array((string)($pc['sector'] ?? ''), $sectoresValidos, true);
        $cuentaNueva = trim((string)$pc['empresa']) !== '' && !cuenta_por_nombre((string)$pc['empresa']);
        $cuentaId = cuenta_asegurar((string)$pc['empresa'], [
            'propietario_id' => $pc['propietario_id'],
            'sitio_web'      => $pc['sitio_web'],
            'telefono'       => ($pc['conmutador'] ?? null) ?: $pc['telefono'],
            'calle'          => $pc['calle'],
            'localidad'      => $pc['localidad'],
            'provincia'      => $pc['provincia'],
            'codigo_postal'  => $pc['codigo_postal'],
            'pais'           => $pc['pais'],
            'sector'         => $sectorOk ? $pc['sector'] : null,
        ]);
        if ($cuentaId) {
            if (!empty($pc['empleados'])) {
                q('UPDATE cuentas SET empleados = ? WHERE id = ? AND empleados IS NULL', [(int)$pc['empleados'], $cuentaId]);
            }
            $cu = q1('SELECT sector, empleados FROM cuentas WHERE id = ?', [$cuentaId]) ?: [];
            if (!empty($pc['sector']) && (string)($cu['sector'] ?? '') !== (string)$pc['sector']) {
                $paraNota[] = 'Sector: ' . $pc['sector'];
            }
            if (!empty($pc['empleados']) && (int)($cu['empleados'] ?? 0) !== (int)$pc['empleados']) {
                $paraNota[] = 'Empleados: ' . (int)$pc['empleados'];
            }
        } else {
            if (!empty($pc['sector']))    { $paraNota[] = 'Sector: ' . $pc['sector']; }
            if (!empty($pc['empleados'])) { $paraNota[] = 'Empleados: ' . (int)$pc['empleados']; }
        }

        // 2) Contacto (reusar si ya existe uno con ese correo)
        $contactoId = null;
        if (!empty($pc['email'])) {
            $ya = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$pc['email']]);
            if ($ya) { $contactoId = (int)$ya['id']; }
        }
        $contactoNuevo = !$contactoId;
        if ($contactoNuevo) {
            $contactoId = contacto_guardar([
                'nombre'         => $pc['nombre'],
                'apellido'       => $pc['apellido'],
                'cuenta_id'      => $cuentaId,
                'propietario_id' => $pc['propietario_id'],
                'cargo'          => $pc['cargo'],
                'email'          => $pc['email'],
                'email2'         => $pc['email2'],
                'telefono'       => $pc['telefono'],
                'movil'          => $pc['movil'],
                'conmutador'     => $pc['conmutador'],
                'fuente'         => $pc['fuente'],
                'linkedin'       => $pc['linkedin'],
                'sitio_web'      => $pc['sitio_web'],
                'calle'          => $pc['calle'],
                'localidad'      => $pc['localidad'],
                'provincia'      => $pc['provincia'],
                'codigo_postal'  => $pc['codigo_postal'],
                'pais'           => $pc['pais'],
                'descripcion'    => $pc['descripcion'],
                'no_email'       => $pc['no_email'],
                'email3'         => '',
                'departamento'   => '',
            ]);
        } else {
            // Contacto reutilizado: se completa sólo lo vacío. La empresa se pone
            // si no tenía; si ya tenía otra, se respeta. Una baja de campañas del
            // posible cliente se suma (nunca se saca una baja).
            $antes = q1('SELECT * FROM contactos WHERE id = ?', [$contactoId]) ?: [];
            $nuevos = [];
            foreach (['nombre', 'cargo', 'email2', 'telefono', 'movil', 'conmutador', 'fuente', 'linkedin',
                      'sitio_web', 'calle', 'localidad', 'provincia', 'codigo_postal', 'pais', 'descripcion'] as $k) {
                if (trim((string)($pc[$k] ?? '')) !== '' && trim((string)($antes[$k] ?? '')) === '') {
                    $nuevos[$k] = $pc[$k];
                }
            }
            if (empty($antes['cuenta_id']) && $cuentaId) { $nuevos['cuenta_id'] = $cuentaId; }
            if (!empty($pc['no_email']) && empty($antes['no_email'])) { $nuevos['no_email'] = 1; }
            if (trim((string)($pc['descripcion'] ?? '')) !== '' && !isset($nuevos['descripcion'])
                && trim((string)$pc['descripcion']) !== trim((string)($antes['descripcion'] ?? ''))) {
                $paraNota[] = "Notas del posible cliente:\n" . trim((string)$pc['descripcion']);
            }
            if ($nuevos) {
                $nuevos['pendiente_norm'] = contacto_falta(array_merge($antes, $nuevos)) ? 1 : 0;
                // Las columnas salen de la lista fija de arriba.
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($nuevos)));
                q("UPDATE contactos SET $sets WHERE id = ?", [...array_values($nuevos), $contactoId]);
            }
        }

        // 3) Negocio (opcional). Arranca en la primera etapa activa del embudo
        // predeterminado, con su probabilidad. Sin embudo no se crea y el
        // resultado lo avisa (negocio_omitido).
        $negocioId = null;
        $negocioOmitido = false;
        if ($op['crear_negocio']) {
            $etapa = conversion_etapa_inicial();
            if ($etapa) {
                q('INSERT INTO negocios (nombre, cuenta_id, contacto_id, propietario_id, embudo_id, etapa_id,
                                         importe, moneda, probabilidad, fecha_cierre, fuente, creado_por)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $op['negocio_nombre'] !== '' ? $op['negocio_nombre'] : ((string)$pc['empresa'] ?: trim(($pc['nombre'] ?? '') . ' ' . $pc['apellido'])),
                    $cuentaId, $contactoId, $pc['propietario_id'],
                    (int)$etapa['embudo_id'], (int)$etapa['id'],
                    $op['negocio_importe'],
                    $op['negocio_moneda'],
                    (int)$etapa['probabilidad'],
                    $op['negocio_cierre'],
                    $pc['fuente'],
                    current_user_id(),
                ]);
                $negocioId = last_id();
                // Primer tramo del historial de etapas, igual que negocio_guardar().
                q('INSERT INTO negocio_etapas (negocio_id, etapa_id, usuario_id) VALUES (?, ?, ?)',
                  [$negocioId, (int)$etapa['id'], current_user_id()]);
            } else {
                $negocioOmitido = true;
            }
        }

        // 4) Arrastrar notas, actividades y adjuntos al contacto (y al negocio si
        // se creó). Las etiquetas se copian: el posible cliente conserva las suyas.
        q("UPDATE notas SET entidad = 'contacto', entidad_id = ?
           WHERE entidad = 'posible_cliente' AND entidad_id = ?", [$contactoId, $id]);
        q('UPDATE actividades SET contacto_id = ?, cuenta_id = COALESCE(cuenta_id, ?), negocio_id = COALESCE(negocio_id, ?),
                                  posible_cliente_id = NULL
           WHERE posible_cliente_id = ?', [$contactoId, $cuentaId, $negocioId, $id]);
        q("UPDATE adjuntos SET entidad = 'contacto', entidad_id = ?
           WHERE entidad = 'posible_cliente' AND entidad_id = ?", [$contactoId, $id]);
        q("INSERT IGNORE INTO etiqueta_registro (etiqueta_id, entidad, entidad_id)
           SELECT etiqueta_id, 'contacto', ? FROM etiqueta_registro
           WHERE entidad = 'posible_cliente' AND entidad_id = ?", [$contactoId, $id]);

        // Lo que no entró en ninguna columna queda escrito, para que de verdad
        // no se pierda nada.
        if (!empty($pc['calificacion'])) { array_unshift($paraNota, 'Calificación: ' . $pc['calificacion']); }
        if ($paraNota && function_exists('nota_crear')) {
            array_unshift($paraNota, 'Estado al convertir: ' . $pc['estado']);
            nota_crear('contacto', $contactoId, implode("\n", $paraNota), 'Datos del posible cliente');
        }

        // 5) Completar la marca del posible cliente con lo que generó. El estado
        // queda como estaba: «convertido» lo dice `convertido_en`.
        q('UPDATE posibles_clientes SET cuenta_id = ?, contacto_id = ?, negocio_id = ? WHERE id = ?',
          [$cuentaId, $contactoId, $negocioId, $id]);

        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    log_app('posible.convertir', ['entidad' => 'posible_cliente', 'entidad_id' => $id,
        'detalle' => 'Convertido en ' . ($cuentaId ? 'cuenta #' . $cuentaId : 'contacto sin empresa')
                   . ', contacto #' . $contactoId . ($negocioId ? ', negocio #' . $negocioId : '')]);

    return ['cuenta_id' => $cuentaId, 'contacto_id' => $contactoId, 'negocio_id' => $negocioId,
            'cuenta_nueva' => $cuentaNueva && $cuentaId !== null, 'contacto_nuevo' => $contactoNuevo,
            'negocio_omitido' => $negocioOmitido];
}

// ------------------------------------------------------------
//  Borrado lógico y papelera (vale para los tres módulos)
// ------------------------------------------------------------

/**
 * Tablas que aceptan borrado lógico desde la UI.
 *
 * La clave es el nombre de la entidad que viaja en el formulario
 * (registro-eliminar); 'tabla' es la tabla real. Funciona como lista blanca:
 * el nombre de la tabla se pega en el SQL y sólo puede salir de acá.
 */
function tablas_papelera(): array
{
    return [
        'cuenta'          => ['tabla' => 'cuentas',           'etiqueta' => 'Cuenta'],
        'contacto'        => ['tabla' => 'contactos',         'etiqueta' => 'Contacto'],
        'posible_cliente' => ['tabla' => 'posibles_clientes', 'etiqueta' => 'Posible cliente'],
        'negocio'         => ['tabla' => 'negocios',          'etiqueta' => 'Negocio'],
        'actividad'       => ['tabla' => 'actividades',       'etiqueta' => 'Actividad'],
        // Estas tres se podían crear y no borrar: la única salida era entrar a
        // la base. Van a la papelera como todo lo demás, con sus 60 días de
        // gracia antes del borrado definitivo.
        'campana'         => ['tabla' => 'campanas',          'etiqueta' => 'Campaña'],
        'lista'           => ['tabla' => 'listas',            'etiqueta' => 'Lista'],
        'plantilla'       => ['tabla' => 'plantillas',        'etiqueta' => 'Plantilla'],
    ];
}

/**
 * Manda un registro a la papelera (pone `eliminado_en`).
 *
 * No verifica permisos ni que el registro exista: eso le toca a la página que
 * la llama (registro-eliminar mira papelera_permitida()). El borrado
 * definitivo lo hace el cron a los 60 días.
 *
 * @throws InvalidArgumentException si la entidad no está en tablas_papelera()
 */
function registro_eliminar(string $entidad, int $id): void
{
    $def = tablas_papelera()[$entidad] ?? null;
    if (!$def) { throw new InvalidArgumentException('Entidad desconocida: ' . $entidad); }
    q("UPDATE {$def['tabla']} SET eliminado_en = NOW() WHERE id = ?", [$id]);
    log_app($entidad . '.eliminar', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => $def['etiqueta'] . ' enviado a la papelera']);
}

/**
 * Saca un registro de la papelera (vuelve `eliminado_en` a null).
 *
 * @throws InvalidArgumentException si la entidad no está en tablas_papelera()
 */
function registro_restaurar(string $entidad, int $id): void
{
    $def = tablas_papelera()[$entidad] ?? null;
    if (!$def) { throw new InvalidArgumentException('Entidad desconocida: ' . $entidad); }
    q("UPDATE {$def['tabla']} SET eliminado_en = NULL WHERE id = ?", [$id]);
    log_app($entidad . '.restaurar', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => $def['etiqueta'] . ' restaurado desde la papelera']);
}

/**
 * ¿Se puede mandar a la papelera un registro con este propietario?
 *
 * Regla del equipo: el administrador siempre; si no, sólo el propietario. Un
 * registro sin propietario lo puede mandar cualquiera (si no, nadie que no
 * sea administrador podría limpiar lo que entró suelto).
 */
function papelera_permitida(?int $propietarioId, ?int $usuarioId, bool $esAdmin): bool
{
    if ($esAdmin || !$propietarioId) { return true; }
    return $usuarioId !== null && $propietarioId === $usuarioId;
}

/**
 * Lo mismo para el usuario de la sesión y una fila ya leída (con su
 * `propietario_id`). Es lo que usan las vistas para mostrar u ocultar el botón.
 */
function puede_mandar_papelera(array $fila): bool
{
    $prop = (int)($fila['propietario_id'] ?? 0);
    return papelera_permitida($prop ?: null, current_user_id(), auth_is_admin());
}

/**
 * Propietario de un registro de tablas_papelera(), o null si no tiene, si no
 * existe o si su tabla no tiene propietario (listas y plantillas).
 */
function registro_propietario(string $entidad, int $id): ?int
{
    $def = tablas_papelera()[$entidad] ?? null;
    // Las tablas con columna `propietario_id`; el nombre sale de la lista blanca.
    if (!$def || !in_array($def['tabla'], ['cuentas', 'contactos', 'posibles_clientes', 'negocios',
                                            'actividades', 'campanas'], true)) {
        return null;
    }
    $p = qv("SELECT propietario_id FROM {$def['tabla']} WHERE id = ?", [$id]);
    return $p ? (int)$p : null;
}

// ------------------------------------------------------------
//  Entradas desde la web
//
//  Lo que llega de intellihelp.tech —una consulta del formulario o una reunión
//  reservada en la landing— entra al CRM por la API v1 (api.php) y termina
//  acá: contacto, empresa, la reunión o la tarea de contestar, y una fila en
//  `entradas_web` con la página y el canal por el que llegó la persona.
//
//  Dos reglas que valen para todo lo de esta sección:
//    · lo que ya estaba cargado NO se pisa. Un formulario lo llena cualquiera,
//      y no puede cambiarle el dueño, la fuente ni las bajas a una ficha que el
//      equipo ya trabajó;
//    · la IP no se guarda nunca en claro (Ley 25.326): sólo su hash.
// ------------------------------------------------------------

/**
 * Hash de una IP con el secreto de la instalación. Null si no hay IP.
 *
 * Es un HMAC y no un sha256 pelado: sin el secreto no se puede recorrer el
 * espacio de IPv4 (son 4.300 millones) para saber de qué IP salió cada hash.
 */
function ip_hash(?string $ip): ?string
{
    $ip = trim((string)$ip);
    return $ip === '' ? null : hash_hmac('sha256', $ip, crm_secret());
}

/**
 * ¿Se pasó del límite? Suma uno al contador de esta ventana y devuelve true
 * si con este pedido supera $max.
 *
 * Ventanas fijas (la hora, el día) y no deslizantes: alcanza para frenar a un
 * bot que llena el calendario, y es una sola fila por clave y ventana.
 *
 * Si la tabla todavía no existe (código subido antes de migrar) no bloquea:
 * es preferible dejar pasar un formulario de más que rechazar a un cliente.
 *
 * @param string $clave    qué se limita (por ejemplo, token + hash de IP)
 * @param int    $max      pedidos permitidos por ventana
 * @param int    $segundos largo de la ventana
 */
function limite_superado(string $clave, int $max, int $segundos): bool
{
    $segundos = max(1, $segundos);
    $ventana  = intdiv(time(), $segundos);
    $clave    = mb_substr($clave, 0, 120);
    try {
        // INSERT … ON DUPLICATE KEY suma en una sola sentencia atómica: dos pedidos
        // juntos no pueden leer el mismo conteo y pasar los dos.
        q('INSERT INTO api_limites (clave, ventana, conteo, vence_en) VALUES (?, ?, 1, ?)
           ON DUPLICATE KEY UPDATE conteo = conteo + 1',
          [$clave, $ventana, date('Y-m-d H:i:s', ($ventana + 1) * $segundos)]);
        $n = (int) qv('SELECT conteo FROM api_limites WHERE clave = ? AND ventana = ?', [$clave, $ventana]);
        // La limpieza va de vez en cuando y no en cada pedido: es un DELETE que
        // no le hace falta a nadie en ese momento. El cron la hace igual una
        // vez por día.
        if (random_int(1, 50) === 1) {
            q('DELETE FROM api_limites WHERE vence_en < NOW() LIMIT 500');
        }
        return $n > $max;
    } catch (\Throwable $e) {
        // Cualquier error (tabla faltante, base caída) deja pasar el pedido.
        return false;
    }
}

/** Segundos que faltan para que se libere esa ventana (para Retry-After). */
function limite_espera(int $segundos): int
{
    $segundos = max(1, $segundos);
    return max(1, $segundos - (time() % $segundos));
}

/**
 * Nombre de empresa reducido para comparar: sin acentos, puntuación ni forma
 * societaria. «ACME S.A.», «Acme SA» y «acme, s. a.» dan todos 'acme'.
 *
 * La forma societaria se quita sólo al FINAL y sólo si queda algo: «Sasa SRL»
 * es 'sasa' y no se confunde con una empresa que se llame «Sa».
 */
function empresa_clave(string $nombre): string
{
    $s = plano($nombre);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    if ($s === '') { return ''; }

    // De la más larga a la más corta, para que «s a i c» no se quede en «i c».
    static $formas = [
        'sociedad de responsabilidad limitada', 'sociedad anonima', 'cooperativa',
        's a i c', 's a u', 's a s', 's r l', 's c a', 's c i',
        'saic', 'ltda', 'coop', 'sau', 'srl', 'sas', 'sca', 'sci', 'inc', 'llc', 'ltd',
        's a', 's h', 'sa', 'sh',
    ];
    do {
        $antes = $s;
        foreach ($formas as $f) {
            if (str_ends_with($s, ' ' . $f)) {
                $s = rtrim(substr($s, 0, -strlen($f) - 1));
                break;
            }
        }
    } while ($s !== $antes && $s !== '');
    return $s;
}

/**
 * Dominio del correo si es corporativo; null para las casillas gratuitas
 * (gmail, hotmail, outlook, yahoo, live, icloud…) y las de los proveedores de
 * internet. Con una casilla gratuita el dominio no dice nada de la empresa.
 *
 * Las gratuitas se reconocen por la primera parte del dominio («gmail» en
 * gmail.com o gmail.com.ar). Efecto colateral: un dominio corporativo que
 * empiece con una de esas palabras (mail.empresa.com, me.empresa.com) también
 * se descarta.
 */
function email_dominio_corporativo(string $email): ?string
{
    $email = normalizar_email($email);
    if (!email_valido($email)) { return null; }
    $dominio = substr($email, strrpos($email, '@') + 1);
    if ($dominio === '' || !str_contains($dominio, '.')) { return null; }

    $base = explode('.', $dominio)[0];
    static $gratuitas = ['gmail', 'googlemail', 'hotmail', 'outlook', 'live', 'msn', 'yahoo', 'ymail',
                         'rocketmail', 'icloud', 'me', 'mac', 'aol', 'protonmail', 'proton', 'pm', 'gmx',
                         'yandex', 'mail', 'zoho', 'tutanota', 'hushmail', 'inbox', 'email'];
    if (in_array($base, $gratuitas, true)) { return null; }

    static $proveedores = ['fibertel.com.ar', 'arnet.com.ar', 'speedy.com.ar', 'ciudad.com.ar',
                           'uolsinectis.com.ar', 'sinectis.com.ar', 'telecentro.com.ar', 'infovia.com.ar',
                           'personal.com.ar', 'movistar.com.ar', 'claro.com.ar'];
    if (in_array($dominio, $proveedores, true)) { return null; }

    // Los dominios reservados para ejemplos y pruebas (RFC 2606) no son de
    // ninguna empresa. Sin esto, en pruebas todas las direcciones inventadas
    // @example.com terminaban colgadas de la primera empresa que tuviera un
    // contacto con ese dominio.
    if (preg_match('/(^|\.)example\.(com|net|org)$|\.(test|example|invalid|localhost)$/', $dominio)) {
        return null;
    }
    return $dominio;
}

/**
 * Escapa los comodines de LIKE en un valor que se busca literal.
 *
 * Usa la barra invertida, que es el escape por defecto de MySQL en LIKE.
 */
function like_literal(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * La empresa de alguien que llegó por la web, sin duplicar ni pisar nada:
 *  1. mismo nombre (la intercalación ya ignora mayúsculas y acentos);
 *  2. misma empresa_clave() entre las que empiezan con la misma palabra;
 *  3. dominio corporativo: la empresa de los contactos con ese dominio, si es
 *     una sola, o la que tiene ese dominio en el sitio web o el correo;
 *  4. si no, se crea con cuenta_asegurar().
 *
 * No se cambia cuenta_asegurar() porque la usa el importador, y ahí el criterio
 * es otro: el archivo trae el nombre exacto con el que se quiere la empresa.
 */
function cuenta_asegurar_web(string $empresa, string $email, int $propietarioId): ?int
{
    $empresa = trim((string) preg_replace('/\s+/u', ' ', $empresa));
    $dominio = email_dominio_corporativo($email);

    // A una empresa que ya existe sólo se le completa el sitio web, y sólo si
    // estaba vacío.
    $completar = static function (int $id) use ($dominio): int {
        if ($dominio !== null) {
            q("UPDATE cuentas SET sitio_web = ? WHERE id = ? AND (sitio_web IS NULL OR sitio_web = '')",
              [$dominio, $id]);
        }
        return $id;
    };

    // 1. El mismo nombre.
    if ($empresa !== '' && ($c = cuenta_por_nombre($empresa))) {
        return $completar((int)$c['id']);
    }

    // 2. El mismo nombre escrito de otra forma: «ACME S.A.» y «Acme SA».
    // Para no traer todas las cuentas, se buscan sólo las que empiezan con la
    // primera palabra de la clave y se compara la clave completa en PHP. El tope
    // de 200 alcanza para cualquier palabra razonable.
    $clave = empresa_clave($empresa);
    if ($clave !== '') {
        $primera = explode(' ', $clave)[0];
        foreach (q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL AND nombre LIKE ?
                    ORDER BY id LIMIT 200', [like_literal($primera) . '%'])->fetchAll() as $c) {
            if (empresa_clave((string)$c['nombre']) === $clave) {
                return $completar((int)$c['id']);
            }
        }
    }

    // 3. El dominio del correo corporativo. Sólo si apunta a UNA empresa: con
    //    dos candidatas no hay forma de saber cuál es, y adivinar mal es peor
    //    que crear una nueva que después se une a mano.
    if ($dominio !== null) {
        $arroba = '%@' . like_literal($dominio);
        $ids = array_map('intval', array_column(q(
            'SELECT DISTINCT ct.cuenta_id FROM contactos ct
             JOIN cuentas cu ON cu.id = ct.cuenta_id AND cu.eliminado_en IS NULL
             WHERE ct.eliminado_en IS NULL AND ct.cuenta_id IS NOT NULL
               AND (ct.email LIKE ? OR ct.email2 LIKE ? OR ct.email3 LIKE ?)
             LIMIT 3', [$arroba, $arroba, $arroba])->fetchAll(), 'cuenta_id'));
        if (count($ids) === 1) { return $ids[0]; }
        // Ningún contacto con ese dominio: se prueba con el sitio web o el correo de
        // la empresa misma. El LIKE '%dominio%' sobre sitio_web también encuentra
        // dominios que lo contienen (acme.com dentro de notacme.com).
        if (!$ids) {
            $ids = array_map('intval', array_column(q(
                'SELECT id FROM cuentas WHERE eliminado_en IS NULL AND (sitio_web LIKE ? OR email LIKE ?) LIMIT 3',
                ['%' . like_literal($dominio) . '%', $arroba])->fetchAll(), 'id'));
            if (count($ids) === 1) { return $ids[0]; }
        }
    }

    // 4. Nueva. Nace pendiente de completar, como toda empresa que entra por un nombre suelto.
    if ($empresa === '') { return null; }
    $id = cuenta_asegurar(mb_substr($empresa, 0, 200), $dominio !== null ? ['sitio_web' => $dominio] : [],
                          $propietarioId);
    if ($id) {
        log_app('cuenta.web', ['entidad' => 'cuenta', 'entidad_id' => $id, 'actor' => 'web',
            'detalle' => 'Alta desde el formulario de la web']);
    }
    return $id;
}

/**
 * El contacto de alguien que llegó por la web.
 *
 * Si ya existe (por email, email2 o email3) sólo se completan el teléfono y la
 * empresa cuando estaban vacíos: no se tocan el dueño, la fuente ni «No incluir
 * en campañas». Si es nuevo entra con fuente «Sitio web», el dueño que se
 * indique, y fuera de las campañas salvo que haya pedido recibir novedades:
 * dejó sus datos para que le contestemos, no para recibir publicidad.
 *
 * Sin correo no hay forma de reconocerlo: se crea siempre uno nuevo.
 *
 * @param array $d nombre, email, telefono, novedades, pagina
 * @return array{0:int,1:bool} [id, es_nuevo]
 */
function contacto_asegurar_web(array $d, int $propietarioId, ?int $cuentaId): array
{
    $email    = normalizar_email((string)($d['email'] ?? ''));
    $nombre   = trim((string)($d['nombre'] ?? ''));
    $telefono = normalizar_telefono((string)($d['telefono'] ?? ''));
    $pagina   = trim((string)($d['pagina'] ?? ''));
    $detalle  = 'Llegó desde la web' . ($pagina !== '' ? ': ' . $pagina : '');

    // Si el correo aparece en más de un contacto, gana el que lo tiene como
    // correo principal y, entre ésos, el más antiguo.
    $c = $email === '' ? null : q1(
        'SELECT * FROM contactos
         WHERE (email = ? OR email2 = ? OR email3 = ?) AND eliminado_en IS NULL
         ORDER BY (email = ?) DESC, id LIMIT 1', [$email, $email, $email, $email]);

    if ($c) {
        $id   = (int)$c['id'];
        $sets = [];
        $vals = [];
        // El teléfono se completa sólo si la ficha no tenía ni fijo ni móvil.
        if ($telefono !== null && trim((string)$c['telefono']) === '' && trim((string)$c['movil']) === '') {
            $sets[] = 'telefono = ?'; $vals[] = $telefono; $c['telefono'] = $telefono;
        }
        if ($cuentaId && empty($c['cuenta_id'])) {
            $sets[] = 'cuenta_id = ?'; $vals[] = $cuentaId; $c['cuenta_id'] = $cuentaId;
        }
        if ($sets) {
            $sets[] = 'pendiente_norm = ?';
            $vals[] = contacto_falta($c) ? 1 : 0;
            $vals[] = $id;
            q('UPDATE contactos SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        }
        log_app('contacto.web', ['entidad' => 'contacto', 'entidad_id' => $id, 'actor' => 'web',
            'detalle' => $detalle]);
        return [$id, false];
    }

    // Nombre y apellido entran en columnas de 120: lo que llega de afuera se
    // recorta acá también, para que un nombre largo nunca termine en un error
    // de la base en lugar de un contacto.
    // Una sola palabra va al apellido (que es obligatorio en la base); con más,
    // la primera es el nombre y el resto el apellido.
    $partes = preg_split('/\s+/u', $nombre) ?: [];
    $id = contacto_guardar([
        'nombre'         => count($partes) > 1 ? mb_substr($partes[0], 0, 120) : '',
        'apellido'       => mb_substr(count($partes) > 1 ? implode(' ', array_slice($partes, 1)) : $nombre, 0, 120),
        'email'          => $email,
        'telefono'       => $telefono ?? '',
        'propietario_id' => $propietarioId ?: null,
        'cuenta_id'      => $cuentaId,
        'fuente'         => 'Sitio web',
        'no_email'       => !empty($d['novedades']) ? 0 : 1,
        'descripcion'    => 'Alta automática desde el formulario de intellihelp.tech.',
    ]);
    log_app('contacto.web', ['entidad' => 'contacto', 'entidad_id' => $id, 'actor' => 'web',
        'detalle' => $detalle]);
    return [$id, true];
}

/**
 * Quién atiende las consultas de la web: el responsable elegido en «Entradas
 * desde la web»; si no hay, el vendedor del tipo de reunión 'diagnostico'; si
 * tampoco, el primer administrador activo. Una consulta sin dueño es una
 * consulta que nadie contesta.
 */
function entrada_web_dueno(): int
{
    // Se lee del ajuste `web_dueno_id` y se confirma que siga activo: un usuario
    // dado de baja no puede quedar recibiendo consultas.
    $elegido = (int) setting_get('web_dueno_id', '');
    if ($elegido > 0 && qv('SELECT 1 FROM usuarios WHERE id = ? AND activo = 1', [$elegido])) {
        return $elegido;
    }
    // La tabla agenda_tipos puede no existir si la agenda no se migró todavía.
    try {
        $vendedor = (int) qv("SELECT t.usuario_id FROM agenda_tipos t JOIN usuarios u ON u.id = t.usuario_id
                              WHERE t.slug = 'diagnostico' AND t.activo = 1 AND u.activo = 1
                              ORDER BY t.id LIMIT 1");
        if ($vendedor > 0) { return $vendedor; }
    } catch (\Throwable $e) {}
    $admin = (int) qv("SELECT id FROM usuarios WHERE activo = 1 AND rol = 'admin' ORDER BY id LIMIT 1");
    return $admin > 0 ? $admin : (int) qv('SELECT id FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1');
}

/**
 * Una línea legible del origen, para las descripciones internas del CRM:
 * «Llegó desde /diagnostico/ · canal Google Ads · campaña diagnostico-it
 * (google / cpc) · segunda visita a la landing».
 *
 * @param array $origen pagina u origen_pagina, canal, utm_*, gclid, li_fat_id,
 *                      referrer, landing, visitas_landing
 */
function entrada_web_resumen(array $origen): string
{
    $v = static fn(string $k): string => trim((string)($origen[$k] ?? ''));
    $partes = [];
    $pagina = $v('pagina') !== '' ? $v('pagina') : $v('origen_pagina');
    $partes[] = $pagina !== '' ? 'Llegó desde ' . $pagina : 'Llegó desde la web';
    if ($v('canal') !== '') { $partes[] = 'canal ' . $v('canal'); }

    $fuente = implode(' / ', array_filter([$v('utm_source'), $v('utm_medium')], 'strlen'));
    if ($v('utm_campaign') !== '') {
        $partes[] = 'campaña ' . $v('utm_campaign') . ($fuente !== '' ? ' (' . $fuente . ')' : '');
    } elseif ($fuente !== '') {
        $partes[] = 'origen ' . $fuente;
    }
    if ($v('utm_term') !== '')  { $partes[] = 'búsqueda «' . $v('utm_term') . '»'; }
    if ($v('gclid') !== '')     { $partes[] = 'clic de Google Ads'; }
    if ($v('li_fat_id') !== '') { $partes[] = 'clic de LinkedIn'; }

    if ($v('referrer') !== '') {
        $host = (string) parse_url($v('referrer'), PHP_URL_HOST);
        if ($host !== '') { $partes[] = 'venía de ' . preg_replace('/^www\./', '', $host); }
    }
    if ($v('landing') !== '' && $v('landing') !== $pagina) {
        $partes[] = 'entró por ' . $v('landing');
    }

    $n = (int)($origen['visitas_landing'] ?? 0);
    $ordinales = [1 => 'primera', 2 => 'segunda', 3 => 'tercera', 4 => 'cuarta', 5 => 'quinta'];
    if ($n > 0) {
        $partes[] = isset($ordinales[$n]) ? $ordinales[$n] . ' visita a la landing' : $n . ' visitas a la landing';
    }
    return implode(' · ', $partes);
}

/**
 * Columnas de `entradas_web` que se pueden cargar, con su largo máximo
 * (0 = número o fecha, sin recorte).
 *
 * Es también la lista blanca de columnas: entrada_web_valores() descarta
 * cualquier clave que no esté acá, así un campo extra en el JSON de la web no
 * puede llegar al SQL.
 */
function entrada_web_columnas(): array
{
    return [
        'idempotencia' => 80, 'cuerpo_hash' => 64, 'token_id' => 0, 'tipo' => 20, 'estado' => 20,
        'error' => 200, 'respuesta' => 60000,
        'nombre' => 160, 'empresa' => 200, 'email' => 190, 'telefono' => 60, 'servicio' => 120,
        'mensaje' => 3000, 'novedades' => 0,
        'consentimiento_en' => 0, 'consentimiento_version' => 40, 'consentimiento_hash' => 64,
        'origen_pagina' => 200, 'canal' => 80, 'utm_source' => 150, 'utm_medium' => 150,
        'utm_campaign' => 150, 'utm_term' => 150, 'utm_content' => 150, 'gclid' => 200,
        'li_fat_id' => 200, 'referrer' => 300, 'landing' => 200, 'visitas_landing' => 0, 'ip_hash' => 64,
        'tipo_reunion_id' => 0, 'inicio_pedido' => 0,
        'propietario_id' => 0, 'contacto_id' => 0, 'cuenta_id' => 0, 'actividad_id' => 0,
        'nota_id' => 0, 'reserva_id' => 0,
    ];
}

/**
 * Prepara los valores de una fila: recorta, vacía en null y convierte lo que
 * haga falta.
 *
 * Los arrays se guardan como JSON, los booleanos como 0/1, los textos se
 * recortan al largo de la columna y los vacíos pasan a null (salvo nombre y
 * correo, que en la tabla son NOT NULL). Las fechas que llegan como marca de
 * tiempo (consentimiento_en, inicio_pedido) se pasan a 'Y-m-d H:i:s'.
 *
 * @return array<string,mixed> sólo las columnas conocidas que vinieron en $d
 */
function entrada_web_valores(array $d): array
{
    // El origen puede venir anidado (como lo manda la web) o plano.
    if (isset($d['origen']) && is_array($d['origen'])) {
        foreach ($d['origen'] as $k => $v) {
            $col = $k === 'pagina' ? 'origen_pagina' : (string)$k;
            if (!array_key_exists($col, $d)) { $d[$col] = $v; }
        }
    }
    $out = [];
    foreach (entrada_web_columnas() as $col => $max) {
        if (!array_key_exists($col, $d)) { continue; }
        $v = $d[$col];
        if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
        if (is_bool($v))  { $v = $v ? 1 : 0; }
        if (is_string($v)) {
            $v = trim($v);
            if ($max > 0) { $v = mb_substr($v, 0, $max); }
            if ($v === '' && !in_array($col, ['nombre', 'email'], true)) { $v = null; }
        }
        if (in_array($col, ['consentimiento_en', 'inicio_pedido'], true) && is_int($v)) {
            $v = date('Y-m-d H:i:s', $v);
        }
        $out[$col] = $v;
    }
    return $out;
}

/**
 * Inserta la fila de la entrada, en estado 'procesando' salvo que se indique
 * otro.
 *
 * Se inserta ANTES de hacer nada más, con la clave de idempotencia única: si
 * la clave ya existe, el pedido es un reintento y no se procesa de nuevo. En
 * ese caso devuelve 0 y la fila existente queda en $previa.
 */
function entrada_web_guardar(array $d, ?array &$previa = null): int
{
    $previa = null;
    $v = entrada_web_valores($d);
    // Valores por defecto que la tabla exige: sin clave de idempotencia se inventa
    // una (el pedido no se puede reintentar, pero se guarda igual).
    $v += ['estado' => 'procesando', 'nombre' => '', 'email' => ''];
    if (empty($v['idempotencia']))           { $v['idempotencia'] = 'r-' . token32(); }
    if (empty($v['consentimiento_en']))      { $v['consentimiento_en'] = date('Y-m-d H:i:s'); }
    if (empty($v['consentimiento_version'])) { $v['consentimiento_version'] = 'sin-version'; }

    $cols = implode(', ', array_keys($v));
    $ph   = implode(', ', array_fill(0, count($v), '?'));
    try {
        q("INSERT INTO entradas_web ($cols) VALUES ($ph)", array_values($v));
        return last_id();
    } catch (\PDOException $e) {
        // 1062 = clave duplicada. Cualquier otro error se propaga.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        $previa = q1('SELECT * FROM entradas_web WHERE token_id <=> ? AND idempotencia = ?',
                     [$v['token_id'] ?? null, $v['idempotencia']]);
        if (!$previa) { throw $e; }
        return 0;
    }
}

/**
 * Vuelve a abrir una entrada que terminó con error, para procesarla de nuevo
 * con la misma clave de idempotencia.
 *
 * Un error no creó nada —la reserva y la consulta van en una transacción—, y
 * la web genera una sola clave por formulario: si el horario elegido ya estaba
 * tomado, la persona elige otro y vuelve a mandar con la MISMA clave. Si eso
 * se tratara como «otro pedido con la misma clave», quien tuvo mala suerte con
 * el primer horario no podría reservar nunca.
 *
 * La actualización es condicional al estado: si dos reintentos llegan juntos,
 * sólo uno la retoma.
 */
function entrada_web_retomar(int $id, array $d): bool
{
    $n = q("UPDATE entradas_web SET estado = 'procesando', error = NULL, respuesta = NULL
            WHERE id = ? AND estado = 'error'", [$id])->rowCount();
    if ($n !== 1) { return false; }
    unset($d['estado'], $d['error'], $d['respuesta']);
    entrada_web_actualizar($id, $d);
    return true;
}

/**
 * ¿Ya entró esta misma consulta hace poco? Devuelve el id de la anterior o 0.
 *
 * Doble clic, el botón «atrás» y volver a mandar: la misma persona (correo),
 * con el mismo servicio y el mismo mensaje, en los últimos 30 minutos. No
 * cuenta el propio pedido (misma clave e idempotencia) ni las que terminaron
 * con error o descartadas: ésas no generaron nada que se pueda duplicar.
 *
 * $minutos va pegado en el SQL (INTERVAL no acepta parámetro en todas las
 * versiones); es seguro porque la firma lo obliga a ser int.
 *
 * @param array $d email, servicio y mensaje, ya limpios (api_validar_entrada)
 */
function entrada_web_duplicada(array $d, ?int $tokenId, string $idem, int $minutos = 30): int
{
    return (int) qv("SELECT id FROM entradas_web
                     WHERE tipo = 'consulta' AND estado IN ('ok', 'procesando')
                       AND email = ? AND COALESCE(servicio, '') = ? AND COALESCE(mensaje, '') = ?
                       AND creado_en >= NOW() - INTERVAL $minutos MINUTE
                       AND NOT (token_id <=> ? AND idempotencia = ?)
                     ORDER BY id LIMIT 1",
                    [normalizar_email((string)($d['email'] ?? '')), trim((string)($d['servicio'] ?? '')),
                     trim((string)($d['mensaje'] ?? '')), $tokenId, $idem]);
}

/**
 * Actualiza una entrada (estado, error, respuesta y lo que se creó en el CRM).
 *
 * La clave de idempotencia y el token no se pueden cambiar: son la identidad
 * del pedido. Con $id <= 0 o sin campos válidos no hace nada.
 */
function entrada_web_actualizar(int $id, array $campos): void
{
    $v = entrada_web_valores($campos);
    unset($v['idempotencia'], $v['token_id']);
    if (!$v || $id <= 0) { return; }
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
    q("UPDATE entradas_web SET $sets WHERE id = ?", [...array_values($v), $id]);
}

/**
 * Vencimiento de la tarea de contestar: el próximo día hábil a las 12 h.
 *
 * «Hábil» acá es sólo lunes a viernes: no mira la tabla de feriados.
 *
 * @param int|null $ahora marca de tiempo de referencia (para las pruebas)
 */
function entrada_web_vencimiento(?int $ahora = null): string
{
    $t = strtotime('tomorrow 12:00', $ahora ?? time());
    while ((int) date('N', $t) >= 6) {   // sábado o domingo
        $t = strtotime('+1 day', $t);
    }
    return date('Y-m-d H:i:s', $t);
}

/**
 * Consulta sin reserva: empresa, contacto, nota con el mensaje, tarea de
 * contestar para el responsable, alerta y la entrada actualizada.
 *
 * Todo en una transacción: o queda la consulta entera, o no queda nada y la
 * web la vuelve a mandar. Un contacto suelto sin la tarea de contestarle es
 * una consulta que se pierde.
 *
 * Sin posibles clientes ni negocios: una consulta todavía no es una
 * oportunidad. El negocio lo abre quien la califique.
 *
 * @param int   $entradaId la fila de entradas_web creada con entrada_web_guardar()
 * @param array $d los datos limpios de api_validar_entrada()
 * @return array{contacto_id:int, cuenta_id:?int, actividad_id:int, nota_id:int}
 * @throws RuntimeException si no hay ningún usuario activo; cualquier error de
 *                          base se propaga después del rollback
 */
function entrada_web_consulta(int $entradaId, array $d): array
{
    $dueno = entrada_web_dueno();
    if ($dueno <= 0) { throw new RuntimeException('No hay ningún usuario activo para atender la consulta.'); }

    $origen   = (array)($d['origen'] ?? []);
    $empresa  = trim((string)($d['empresa'] ?? ''));
    $nombre   = trim((string)($d['nombre'] ?? ''));
    $servicio = trim((string)($d['servicio'] ?? ''));
    $mensaje  = trim((string)($d['mensaje'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cuentaId = cuenta_asegurar_web($empresa, (string)($d['email'] ?? ''), $dueno);
        [$contactoId] = contacto_asegurar_web([
            'nombre'    => $nombre,
            'email'     => $d['email'] ?? '',
            'telefono'  => $d['telefono'] ?? '',
            'novedades' => !empty($d['novedades']),
            'pagina'    => $origen['pagina'] ?? '',
        ], $dueno, $cuentaId);

        $notaId = nota_crear('contacto', $contactoId,
            trim(($servicio !== '' ? 'Servicio: ' . $servicio . "\n\n" : '') . ($mensaje !== '' ? $mensaje : '(Sin mensaje)')),
            'Consulta desde la web');

        $actividadId = actividad_guardar([
            'tipo'           => 'tarea',
            'asunto'         => mb_substr('Responder consulta web: ' . ($empresa !== '' ? $empresa : $nombre), 0, 200),
            'propietario_id' => $dueno,
            'contacto_id'    => $contactoId,
            'cuenta_id'      => $cuentaId,
            'prioridad'      => 'Alta',
            'vence_el'       => entrada_web_vencimiento(),
            'descripcion'    => trim(($servicio !== '' ? 'Servicio: ' . $servicio . "\n\n" : '')
                              . ($mensaje !== '' ? $mensaje . "\n\n" : '')
                              . entrada_web_resumen($origen)),
        ]);

        entrada_web_actualizar($entradaId, [
            'estado'         => 'ok',
            'propietario_id' => $dueno,
            'contacto_id'    => $contactoId,
            'cuenta_id'      => $cuentaId,
            'actividad_id'   => $actividadId,
            'nota_id'        => $notaId,
        ]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // Fuera de la transacción: la alerta no puede deshacer la consulta.
    // En pantalla siempre; por correo según el ajuste `avisar_web`.
    notificar('web',
        'Consulta desde la web: ' . ($empresa !== '' ? $empresa : $nombre),
        ['usuario_id' => $dueno,
         'detalle'    => $nombre . ($servicio !== '' ? ' · ' . $servicio : '')
                       . (!empty($origen['canal']) ? ' · llegó por ' . $origen['canal'] : ''),
         'entidad'    => 'contacto', 'entidad_id' => $contactoId,
         'url'        => 'contacto-ver?id=' . $contactoId]);

    return ['contacto_id' => $contactoId, 'cuenta_id' => $cuentaId,
            'actividad_id' => $actividadId, 'nota_id' => $notaId];
}

/**
 * Cron: borra los datos personales de las entradas viejas que ya no tienen
 * contacto (se purgó de la papelera) o que quedaron con error o descartadas.
 *
 * Se conservan el canal, la página y las fechas: son los números que mira la
 * consultora cada mes, y sin la persona ya no dicen nada de nadie.
 *
 * contacto_id queda en null solo cuando el contacto se borra de verdad: la
 * clave foránea de entradas_web es ON DELETE SET NULL.
 *
 * @param int $dias antigüedad mínima de la entrada
 * @return int cuántas filas se anonimizaron
 */
function entradas_web_anonimizar(int $dias = 60): int
{
    return q("UPDATE entradas_web
                 SET nombre = '(borrado)', email = '', empresa = NULL, telefono = NULL,
                     mensaje = NULL, respuesta = NULL, ip_hash = NULL
               WHERE creado_en < NOW() - INTERVAL ? DAY
                 AND nombre <> '(borrado)'
                 AND (contacto_id IS NULL OR estado IN ('error', 'descartada'))",
             [max(1, $dias)])->rowCount();
}

// ---- Pantalla «Entradas desde la web» ----

/** ¿Ya corrió la migración 008? La pantalla avisa en vez de reventar. */
function entradas_web_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        db()->query('SELECT id FROM entradas_web LIMIT 1');
        db()->query('SELECT reprogramada_de FROM agenda_reservas LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/**
 * WHERE + parámetros de los filtros del listado.
 *
 * @param array $f    q, tipo, estado, canal ('-' = sin canal), pagina, desde,
 *                    hasta (AAAA-MM-DD; lo que no tiene ese formato se ignora)
 * @param bool  $conCanal false para no filtrar por canal (las tarjetas por
 *                    canal comparan justamente eso)
 */
function entradas_web_where(array $f, bool $conCanal = true): array
{
    $w = ['1 = 1'];
    $p = [];
    if (!empty($f['q'])) {
        $w[] = '(e.nombre LIKE ? OR e.email LIKE ? OR e.empresa LIKE ? OR e.utm_campaign LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if (in_array($f['tipo'] ?? '', ['consulta', 'reserva'], true)) { $w[] = 'e.tipo = ?'; $p[] = $f['tipo']; }
    if (in_array($f['estado'] ?? '', ['procesando', 'ok', 'error', 'descartada'], true)) {
        $w[] = 'e.estado = ?'; $p[] = $f['estado'];
    }
    if ($conCanal && ($f['canal'] ?? '') !== '') {
        if ($f['canal'] === '-') { $w[] = "(e.canal IS NULL OR e.canal = '')"; }
        else { $w[] = 'e.canal = ?'; $p[] = $f['canal']; }
    }
    if (($f['pagina'] ?? '') !== '') { $w[] = 'e.origen_pagina = ?'; $p[] = $f['pagina']; }
    if (!empty($f['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['desde'])) {
        $w[] = 'e.creado_en >= ?'; $p[] = $f['desde'] . ' 00:00:00';
    }
    if (!empty($f['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['hasta'])) {
        $w[] = 'e.creado_en <= ?'; $p[] = $f['hasta'] . ' 23:59:59';
    }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * El listado y el CSV comparten el FROM.
 *
 * Todo LEFT JOIN: una entrada con error no creó contacto ni reserva y tiene
 * que aparecer igual.
 */
function entradas_web_from(): string
{
    return 'FROM entradas_web e
            LEFT JOIN contactos ct      ON ct.id = e.contacto_id
            LEFT JOIN cuentas cu        ON cu.id = e.cuenta_id
            LEFT JOIN agenda_reservas r ON r.id = e.reserva_id
            LEFT JOIN usuarios u        ON u.id = e.propietario_id';
}

/** ORDER BY del listado según la opción elegida; lo desconocido va por fecha. */
function entradas_web_orden(string $orden): string
{
    return match ($orden) {
        'antigua' => 'e.creado_en ASC, e.id ASC',
        'canal'   => 'e.canal IS NULL, e.canal, e.creado_en DESC',
        'empresa' => 'e.empresa IS NULL, e.empresa, e.creado_en DESC',
        default   => 'e.creado_en DESC, e.id DESC',
    };
}

/**
 * Listado paginado de entradas web con el contacto, la cuenta, la reserva y
 * el responsable de cada una.
 *
 * @return array lo de paginar() más 'rows' y 'total'
 */
function entradas_web_listar(array $f): array
{
    [$where, $p] = entradas_web_where($f);
    $total = (int) qv("SELECT COUNT(*) FROM entradas_web e $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q('SELECT e.*, ct.nombre_completo AS contacto, cu.nombre AS cuenta,
                       r.inicia_el AS reserva_inicio, r.estado AS reserva_estado, u.nombre AS propietario '
             . entradas_web_from() . " $where ORDER BY " . entradas_web_orden((string)($f['orden'] ?? ''))
             . " LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Valores distintos de una columna, para los filtros (canal o página).
 *
 * La columna se pega en el SQL, por eso se acepta sólo de esta lista.
 */
function entradas_web_opciones(string $columna): array
{
    if (!in_array($columna, ['canal', 'origen_pagina'], true)) { return []; }
    return array_column(q("SELECT DISTINCT $columna AS v FROM entradas_web
                           WHERE $columna IS NOT NULL AND $columna <> '' ORDER BY v LIMIT 200")->fetchAll(), 'v');
}

/**
 * La reserva vigente a partir de la original, siguiendo los cambios de
 * horario: cada cambio crea una reserva nueva que apunta a la anterior con
 * `reprogramada_de`. Sin esto, una reunión que se movió una vez contaría como
 * «reprogramada» y nunca como realizada.
 *
 * @param int[] $ids reservas originales
 * @return array<int,int> original => vigente
 */
function entradas_web_reservas_vigentes(array $ids): array
{
    $siguiente = [];
    // Se trae el mapa completo de reprogramaciones (son pocas) y se recorre en
    // memoria, en lugar de una consulta por cada eslabón.
    foreach (q('SELECT id, reprogramada_de FROM agenda_reservas WHERE reprogramada_de IS NOT NULL')->fetchAll() as $r) {
        $siguiente[(int)$r['reprogramada_de']] = (int)$r['id'];
    }
    $out = [];
    foreach ($ids as $id) {
        $actual = (int)$id;
        // El tope corta un ciclo si alguna vez quedara uno mal cargado.
        for ($i = 0; $i < 50 && isset($siguiente[$actual]); $i++) { $actual = $siguiente[$actual]; }
        $out[(int)$id] = $actual;
    }
    return $out;
}

/**
 * Tarjetas por canal: consultas, reuniones agendadas, realizadas y canceladas.
 *
 * Son las métricas que pide el embudo («reuniones agendadas», «reuniones
 * realizadas», «canal de origen»). Se calculan con los mismos filtros del
 * listado salvo el canal, que es justamente lo que se compara.
 */
function entradas_web_por_canal(array $f): array
{
    $g = $f;
    unset($g['tipo'], $g['estado']);
    [$where, $p] = entradas_web_where($g, false);
    // Sólo cuentan las entradas que terminaron bien: una con error no generó ni
    // consulta ni reunión.
    $filas = q("SELECT COALESCE(NULLIF(e.canal, ''), 'Sin dato') AS canal, e.tipo, e.reserva_id
                FROM entradas_web e $where AND e.estado = 'ok'", $p)->fetchAll();

    $reservas = array_values(array_unique(array_filter(array_map('intval', array_column($filas, 'reserva_id')))));
    $vigente  = $reservas ? entradas_web_reservas_vigentes($reservas) : [];
    $estado   = [];
    if ($vigente) {
        $ids = array_values(array_unique($vigente));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT r.id, r.estado, a.completada_en
                    FROM agenda_reservas r LEFT JOIN actividades a ON a.id = r.actividad_id
                    WHERE r.id IN ($ph)", $ids)->fetchAll() as $r) {
            $estado[(int)$r['id']] = $r;
        }
    }

    $canales = [];
    foreach ($filas as $e) {
        $c = (string)$e['canal'];
        $canales[$c] ??= ['canal' => $c, 'consultas' => 0, 'agendadas' => 0, 'realizadas' => 0, 'canceladas' => 0];
        if ($e['tipo'] === 'consulta') { $canales[$c]['consultas']++; continue; }
        $canales[$c]['agendadas']++;
        // Se mira el estado de la reserva VIGENTE, no el de la original: la original
        // de una reunión reprogramada queda como «reprogramada» para siempre.
        $r = $estado[$vigente[(int)$e['reserva_id']] ?? 0] ?? null;
        if (!$r) { continue; }
        if ($r['estado'] === 'cancelada')    { $canales[$c]['canceladas']++; }
        elseif (!empty($r['completada_en'])) { $canales[$c]['realizadas']++; }
    }
    usort($canales, fn($a, $b) => ($b['agendadas'] + $b['consultas']) <=> ($a['agendadas'] + $a['consultas']));
    return $canales;
}
