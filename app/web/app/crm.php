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
 * El sector se elegía escribiéndolo a mano, y así una misma industria terminaba
 * cargada de cinco formas distintas —«Logistica», «logística», «Transporte y
 * logística»—, que es exactamente lo que hace inservible un filtro. Ahora se
 * elige de una lista, y la lista depende del tipo: a un proveedor no le sirven
 * las mismas categorías que a un cliente.
 */
function sectores_por_tipo(): array
{
    // Rubros de cliente: los que la empresa atiende de verdad.
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
    return strlen(preg_replace('/\D/', '', $v) ?? '') >= 6;      // y tiene que tener números de verdad
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
    if (($f['pendiente'] ?? '') === '1') { $w[] = 'c.pendiente_norm = 1'; }
    if (($f['pendiente'] ?? '') === '0') { $w[] = 'c.pendiente_norm = 0'; }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

function cuentas_listar(array $f): array
{
    [$where, $p] = cuentas_where($f);
    $total = (int) qv("SELECT COUNT(*) FROM cuentas c $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT c.id, c.nombre, c.cuit, c.tipo, c.sector, c.telefono,
                       c.localidad, c.provincia, c.sitio_web, c.pendiente_norm,
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
    if (($f['pendiente'] ?? '') === '1') { $w[] = 'ct.pendiente_norm = 1'; }
    if (($f['pendiente'] ?? '') === '0') { $w[] = 'ct.pendiente_norm = 0'; }
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
    // Se piden SOLO las columnas que el listado dibuja, mas las que necesita
    // contacto_falta() para decir que le falta a la ficha. Con ct.* venian
    // tambien la descripcion y las notas —texto largo que no se muestra—, y a
    // 100 filas por pagina eso es traer de la base lo que se va a tirar.
    $rows = q("SELECT ct.id, ct.nombre, ct.apellido, ct.nombre_completo, ct.email, ct.cargo,
                      ct.telefono, ct.movil, ct.conmutador, ct.localidad, ct.provincia,
                      ct.cuenta_id, ct.no_email, ct.email_rebotado, ct.pendiente_norm,
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
    // clave, y ponerla en cero a ciegas hacía que cada reimportación borrara
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
