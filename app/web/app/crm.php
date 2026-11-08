<?php
declare(strict_types=1);

// ============================================================
//  Núcleo CRM: cuentas (empresas), contactos y posibles clientes.
//  Acá vive la lógica; las páginas sólo orquestan y las vistas pintan.
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

function fuentes(): array
{
    return ['Campaña de correo', 'Sitio web', 'Referido de empleado', 'Referido externo',
            'Llamada en frío', 'Feria o evento', 'LinkedIn', 'Socio', 'Publicidad',
            'Base comprada', 'Otro'];
}

function estados_posible_cliente(): array
{
    return ['Sin contactar', 'Contactado', 'Intentamos contactar', 'Contactar más adelante',
            'Precalificado', 'No calificado', 'Basura'];
}

function calificaciones(): array
{
    return ['Caliente', 'Tibio', 'Frío'];
}

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
    if (!in_array($tabla, ['contactos', 'cuentas'], true)) { return 0; }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return 0; }

    $marcas = implode(',', array_fill(0, count($ids), '?'));
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

function estados_actividad(): array
{
    return ['No iniciada', 'En curso', 'Esperando a alguien', 'Aplazada', 'Completada'];
}

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

/** Lleva un país a su forma canónica. Descarta la basura (URLs, textos sueltos). */
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
    return mb_substr($v, 0, 60);
}

// ------------------------------------------------------------
//  Cuentas (empresas)
// ------------------------------------------------------------

function cuenta_get(int $id): ?array
{
    return q1('SELECT c.*, u.nombre AS propietario
               FROM cuentas c LEFT JOIN usuarios u ON u.id = c.propietario_id
               WHERE c.id = ? AND c.eliminado_en IS NULL', [$id]);
}

/** Busca una cuenta por nombre exacto (sin distinguir mayúsculas ni acentos). */
function cuenta_por_nombre(string $nombre): ?array
{
    $nombre = trim($nombre);
    if ($nombre === '') { return null; }
    return q1('SELECT * FROM cuentas WHERE nombre = ? AND eliminado_en IS NULL LIMIT 1', [$nombre]);
}

/**
 * Devuelve el id de la cuenta con ese nombre, creándola si no existe.
 * Es lo que evita que la importación duplique empresas.
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

/** WHERE + parámetros de los filtros del listado de cuentas. */
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

function cuentas_listar(array $f): array
{
    [$where, $p] = cuentas_where($f);
    $total = (int) qv("SELECT COUNT(*) FROM cuentas c $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT c.id, c.nombre, c.cuit, c.tipo, c.sector, c.telefono,
                       c.localidad, c.provincia, c.sitio_web, c.pendiente_norm, c.aprobado_en,
                       u.nombre AS propietario,
                       (SELECT COUNT(*) FROM contactos ct WHERE ct.cuenta_id = c.id AND ct.eliminado_en IS NULL) AS n_contactos
                FROM cuentas c LEFT JOIN usuarios u ON u.id = c.propietario_id
                $where ORDER BY c.nombre LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/** Alta o edición de una cuenta. Devuelve el id. */
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
        'empleados'      => $d['empleados'] !== '' ? (int)($d['empleados'] ?? 0) : null,
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
    $campos['creado_por'] = current_user_id();
    if (empty($campos['propietario_id'])) { $campos['propietario_id'] = current_user_id(); }
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO cuentas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('cuenta.crear', ['entidad' => 'cuenta', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

// ------------------------------------------------------------
//  Contactos
// ------------------------------------------------------------

function contacto_get(int $id): ?array
{
    return q1('SELECT ct.*, cu.nombre AS cuenta, u.nombre AS propietario
               FROM contactos ct
               LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
               LEFT JOIN usuarios u ON u.id = ct.propietario_id
               WHERE ct.id = ? AND ct.eliminado_en IS NULL', [$id]);
}

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

function contactos_listar(array $f): array
{
    [$where, $p] = contactos_where($f);
    $sql = "FROM contactos ct
            LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
            LEFT JOIN usuarios u ON u.id = ct.propietario_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
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
                      ct.cuenta_id, ct.no_email, ct.email_rebotado, ct.pendiente_norm, ct.aprobado_en,
                      cu.nombre AS cuenta, u.nombre AS propietario $sql
               ORDER BY $orden LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/** Alta o edición de un contacto. Devuelve el id. */
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
            $antes = q1('SELECT * FROM contactos WHERE id = ?', [$id]) ?: [];
            foreach ($campos as $k => $v) {
                if (($v === null || $v === '') && isset($antes[$k]) && $antes[$k] !== null && $antes[$k] !== '') {
                    $campos[$k] = $antes[$k];
                }
            }
        }
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

function posible_get(int $id): ?array
{
    return q1('SELECT pc.*, u.nombre AS propietario
               FROM posibles_clientes pc LEFT JOIN usuarios u ON u.id = pc.propietario_id
               WHERE pc.id = ? AND pc.eliminado_en IS NULL', [$id]);
}

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
 * Convierte un posible cliente en Cuenta + Contacto (+ Negocio opcional).
 *
 * Es la operación central del embudo. Reglas:
 *   · La cuenta se reutiliza si ya existe una con ese nombre (no se duplica).
 *   · El contacto se reutiliza si ya existe uno con ese correo.
 *   · Las notas y actividades del posible cliente pasan al contacto.
 *   · El posible cliente no se borra: queda marcado como convertido, con los
 *     ids de lo que generó, para poder auditar de dónde salió cada cliente.
 *
 * @return array{cuenta_id:int, contacto_id:int, negocio_id:?int}
 */
function posible_convertir(int $id, array $opciones = []): array
{
    $pc = posible_get($id);
    if (!$pc) {
        throw new RuntimeException('El posible cliente no existe.');
    }
    if (!empty($pc['convertido_en'])) {
        throw new RuntimeException('Este posible cliente ya se convirtió.');
    }

    db()->beginTransaction();
    try {
        // 1) Cuenta
        $cuentaId = cuenta_asegurar($pc['empresa'], [
            'propietario_id' => $pc['propietario_id'],
            'sitio_web'      => $pc['sitio_web'],
            'telefono'       => ($pc['conmutador'] ?? null) ?: $pc['telefono'],
            'calle'          => $pc['calle'],
            'localidad'      => $pc['localidad'],
            'provincia'      => $pc['provincia'],
            'codigo_postal'  => $pc['codigo_postal'],
            'pais'           => $pc['pais'],
        ]);

        // 2) Contacto (reusar si ya existe uno con ese correo)
        $contactoId = null;
        if (!empty($pc['email'])) {
            $ya = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$pc['email']]);
            if ($ya) { $contactoId = (int)$ya['id']; }
        }
        if (!$contactoId) {
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
            q('UPDATE contactos SET cuenta_id = COALESCE(cuenta_id, ?) WHERE id = ?', [$cuentaId, $contactoId]);
        }

        // 3) Negocio (opcional)
        $negocioId = null;
        if (!empty($opciones['crear_negocio'])) {
            $etapa = q1('SELECT e.id, e.embudo_id, e.probabilidad FROM etapas e
                         JOIN embudos em ON em.id = e.embudo_id AND em.predeterminado = 1
                         WHERE e.activo = 1 ORDER BY e.orden LIMIT 1');
            if ($etapa) {
                q('INSERT INTO negocios (nombre, cuenta_id, contacto_id, propietario_id, embudo_id, etapa_id,
                                         importe, moneda, probabilidad, fecha_cierre, fuente, creado_por)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    trim((string)($opciones['negocio_nombre'] ?? '')) ?: $pc['empresa'],
                    $cuentaId, $contactoId, $pc['propietario_id'],
                    (int)$etapa['embudo_id'], (int)$etapa['id'],
                    (float)($opciones['negocio_importe'] ?? 0),
                    $opciones['negocio_moneda'] ?? 'ARS',
                    (int)$etapa['probabilidad'],
                    ($opciones['negocio_cierre'] ?? null) ?: null,
                    $pc['fuente'],
                    current_user_id(),
                ]);
                $negocioId = last_id();
                q('INSERT INTO negocio_etapas (negocio_id, etapa_id, usuario_id) VALUES (?, ?, ?)',
                  [$negocioId, (int)$etapa['id'], current_user_id()]);
            }
        }

        // 4) Arrastrar notas y actividades al contacto (y al negocio si se creó)
        q("UPDATE notas SET entidad = 'contacto', entidad_id = ?
           WHERE entidad = 'posible_cliente' AND entidad_id = ?", [$contactoId, $id]);
        q('UPDATE actividades SET contacto_id = ?, cuenta_id = COALESCE(cuenta_id, ?), negocio_id = COALESCE(negocio_id, ?),
                                  posible_cliente_id = NULL
           WHERE posible_cliente_id = ?', [$contactoId, $cuentaId, $negocioId, $id]);
        q("UPDATE adjuntos SET entidad = 'contacto', entidad_id = ?
           WHERE entidad = 'posible_cliente' AND entidad_id = ?", [$contactoId, $id]);

        // 5) Marcar el posible cliente
        q('UPDATE posibles_clientes
           SET convertido_en = NOW(), convertido_por = ?, cuenta_id = ?, contacto_id = ?, negocio_id = ?,
               estado = ?
           WHERE id = ?',
          [current_user_id(), $cuentaId, $contactoId, $negocioId, 'Precalificado', $id]);

        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    log_app('posible.convertir', ['entidad' => 'posible_cliente', 'entidad_id' => $id,
        'detalle' => 'Convertido en cuenta #' . $cuentaId . ', contacto #' . $contactoId
                   . ($negocioId ? ', negocio #' . $negocioId : '')]);

    return ['cuenta_id' => $cuentaId, 'contacto_id' => $contactoId, 'negocio_id' => $negocioId];
}

// ------------------------------------------------------------
//  Borrado lógico y papelera (vale para los tres módulos)
// ------------------------------------------------------------

/** Tablas que aceptan borrado lógico desde la UI. */
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

function registro_eliminar(string $entidad, int $id): void
{
    $def = tablas_papelera()[$entidad] ?? null;
    if (!$def) { throw new InvalidArgumentException('Entidad desconocida: ' . $entidad); }
    q("UPDATE {$def['tabla']} SET eliminado_en = NOW() WHERE id = ?", [$id]);
    log_app($entidad . '.eliminar', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => $def['etiqueta'] . ' enviado a la papelera']);
}

function registro_restaurar(string $entidad, int $id): void
{
    $def = tablas_papelera()[$entidad] ?? null;
    if (!$def) { throw new InvalidArgumentException('Entidad desconocida: ' . $entidad); }
    q("UPDATE {$def['tabla']} SET eliminado_en = NULL WHERE id = ?", [$id]);
    log_app($entidad . '.restaurar', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => $def['etiqueta'] . ' restaurado desde la papelera']);
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

/** Hash de una IP con el secreto de la instalación. Null si no hay IP. */
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
 */
function limite_superado(string $clave, int $max, int $segundos): bool
{
    $segundos = max(1, $segundos);
    $ventana  = intdiv(time(), $segundos);
    $clave    = mb_substr($clave, 0, 120);
    try {
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

/** Escapa los comodines de LIKE en un valor que se busca literal. */
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

    $c = $email === '' ? null : q1(
        'SELECT * FROM contactos
         WHERE (email = ? OR email2 = ? OR email3 = ?) AND eliminado_en IS NULL
         ORDER BY (email = ?) DESC, id LIMIT 1', [$email, $email, $email, $email]);

    if ($c) {
        $id   = (int)$c['id'];
        $sets = [];
        $vals = [];
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
    $elegido = (int) setting_get('web_dueno_id', '');
    if ($elegido > 0 && qv('SELECT 1 FROM usuarios WHERE id = ? AND activo = 1', [$elegido])) {
        return $elegido;
    }
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

/** Prepara los valores de una fila: recorta, vacía en null y convierte lo que haga falta. */
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

/** Actualiza una entrada (estado, error, respuesta y lo que se creó en el CRM). */
function entrada_web_actualizar(int $id, array $campos): void
{
    $v = entrada_web_valores($campos);
    unset($v['idempotencia'], $v['token_id']);
    if (!$v || $id <= 0) { return; }
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
    q("UPDATE entradas_web SET $sets WHERE id = ?", [...array_values($v), $id]);
}

/** Vencimiento de la tarea de contestar: el próximo día hábil a las 12 h. */
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
 * @param array $d los datos limpios de api_validar_entrada()
 * @return array{contacto_id:int, cuenta_id:?int, actividad_id:int, nota_id:int}
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

/** WHERE + parámetros de los filtros del listado. */
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

/** El listado y el CSV comparten el FROM. */
function entradas_web_from(): string
{
    return 'FROM entradas_web e
            LEFT JOIN contactos ct      ON ct.id = e.contacto_id
            LEFT JOIN cuentas cu        ON cu.id = e.cuenta_id
            LEFT JOIN agenda_reservas r ON r.id = e.reserva_id
            LEFT JOIN usuarios u        ON u.id = e.propietario_id';
}

function entradas_web_orden(string $orden): string
{
    return match ($orden) {
        'antigua' => 'e.creado_en ASC, e.id ASC',
        'canal'   => 'e.canal IS NULL, e.canal, e.creado_en DESC',
        'empresa' => 'e.empresa IS NULL, e.empresa, e.creado_en DESC',
        default   => 'e.creado_en DESC, e.id DESC',
    };
}

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

/** Valores distintos de una columna, para los filtros (canal o página). */
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
        $r = $estado[$vigente[(int)$e['reserva_id']] ?? 0] ?? null;
        if (!$r) { continue; }
        if ($r['estado'] === 'cancelada')    { $canales[$c]['canceladas']++; }
        elseif (!empty($r['completada_en'])) { $canales[$c]['realizadas']++; }
    }
    usort($canales, fn($a, $b) => ($b['agendadas'] + $b['consultas']) <=> ($a['agendadas'] + $a['consultas']));
    return $canales;
}
