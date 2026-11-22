<?php
declare(strict_types=1);

require_once __DIR__ . '/envio.php';

// ============================================================
//  Campañas de correo — el motor de Pulsemail llevado a web.
//
//  Diferencia clave con la app de escritorio: acá la campaña NO corre en la
//  máquina de nadie. Se arma una COLA (tabla `envios`) y un cron la despacha
//  de a poco. Si se corta la luz, si cierran el navegador o si se reinicia el
//  servidor, la campaña sigue por donde quedó, sin repetir un solo correo.
//
//  Módulo: «campanas» (ver modulos.php). En producción se enciende aparte;
//  las rutas públicas que dependen de esto (`baja`, `px`, `img`) NO están en
//  la lista del módulo, así que siguen respondiendo aunque se apague: un
//  enlace de baja de un correo viejo tiene que funcionar siempre.
//
//  Qué hay acá, en orden:
//    · El turno del cron (cron_tomar_turno / cron_soltar_turno).
//    · Plantillas: listar, leer, guardar y reemplazar variables.
//    · Listas de destinatarios y sus miembros.
//    · Campañas: alta, cola (`campana_preparar`), pasos previos, ritmo y el
//      despachador que llama app/cron.php (`campanas_despachar`).
//    · Bajas, rebotes, respuestas y la purga de la papelera.
//
//  Tablas que se leen o escriben: plantillas, listas, lista_miembros,
//  campanas, envios, envio_eventos, desuscripciones, contactos,
//  posibles_clientes, cuentas, cuentas_correo, agenda_tipos, usuarios,
//  app_settings (turno del cron) y todas las de tablas_papelera() en la purga.
//
//  Depende de: envio.php (SMTP), bloques.php (render del editor visual),
//  cuentas_correo.php (casillas y cupos), crm.php (contactos_where), imap.php
//  (imap_quizas_revisar, opcional) y los helpers de db.php / helpers.php.
//
//  Estados de un envío (`envios.estado`):
//    pendiente → enviando → enviado | rechazado (5xx en RCPT) | error (tras
//    20 intentos fallidos, un 5xx en otro paso, o un envío «dudoso» que pudo
//    haber salido y no se reintenta solo). «omitido» es lo que nunca se
//    intentó (baja, inválido, papelera, fuera de la lista blanca del modo
//    prueba, campaña cancelada). «rebotado» lo pone después el lector IMAP o
//    marcar_rebote() sobre un envío que ya había salido.
//
//  Estados de una campaña: borrador → (programada) → enviando ⇄ pausada →
//  completada | cancelada. No hay paso de aprobación. Iniciar y cancelar
//  sólo pueden su creador, su responsable o un admin
//  (campana_puede_gestionar()); las transiciones válidas las fija
//  campana_transicion_valida().
//
//  Modo prueba (es_testing()): el despachador sólo manda a la lista blanca
//  (campana_lista_blanca()); lo demás queda omitido.
//
//  Los valores de las variables ({{nombre}}, {{empresa}}…) se escapan al
//  insertarlos en el HTML (render_plantilla() con $html = true).
// ============================================================

// Cada cuánto se despierta el cron en el hosting, y cuánto trabaja cada vez.
// Están acá y no sueltos en el código porque de estos dos números sale el
// ritmo real de envío, y ese ritmo es lo que hay que poder mostrar en pantalla:
// el cron se despierta cada 5 minutos, pero NO manda un correo por corrida,
// manda todos los que entren en su presupuesto. Confundir las dos cosas lleva
// a creer que 500 correos son dos días, cuando son unas horas.
// El cron marca el pulso; el ritmo lo pone el script. hPanel no siempre deja
// programar cada minuto —hay planes donde el mínimo es cada cinco—, así que en
// vez de depender de eso, cada corrida trabaja en VUELTAS de un minuto hasta
// gastar su presupuesto (CRON_CORRIDA_SEG). Ojo: la corrida da siempre unas
// cinco vueltas, esté el cron cada uno o cada cinco minutos; con el cron cada
// minuto, las corridas que arrancan mientras otra sigue viva encuentran el
// turno tomado y se van sin hacer nada. En los dos casos la casilla se mira
// una vez por minuto.
// Con estos valores, por vuelta salen uno o dos correos por campaña: el primero
// sin esperar y otro si la pausa sorteada entra en los 45 segundos.
const CRON_CICLO_SEG       = 55;   // cuánto dura cada vuelta
const CRON_CORRIDA_SEG     = 280;  // el total, con margen antes de los 5 minutos
const CRON_PERIODO_SEG     = 60;   // cada cuánto sale un correo, a efectos del ritmo
const CRON_PRESUPUESTO_SEG = 45;   // lo que se despacha DENTRO de cada vuelta

/**
 * Toma el turno para correr el cron, o dice que no.
 *
 * Con el cron cada cinco minutos esto no hacía falta: la corrida duraba 280
 * segundos y nunca llegaba a encontrarse con la siguiente. Corriendo cada
 * minuto sí se pisan, y dos corridas a la vez leen la misma casilla dos veces y
 * cuentan de más.
 *
 * Se toma con un UPDATE condicional y no leyendo-y-después-escribiendo: entre
 * esas dos operaciones entran las dos corridas y las dos creen que ganaron.
 * Acá la base decide, y sólo una fila cambia.
 *
 * El turno vence solo. Si el proceso se muere a la mitad —tope de tiempo del
 * hosting, reinicio— nadie va a poder liberarlo, y sin vencimiento el cron
 * quedaría trabado indefinidamente esperando a un proceso que ya no existe.
 *
 * El valor guardado en `app_settings.cron_turno` es el timestamp Unix de quien
 * tomó el turno ('0' = libre). Se llama `setting_refrescar()` porque
 * setting_get() cachea los ajustes por pedido.
 *
 * @param int $venceSeg a los cuántos segundos un turno tomado se da por
 *                      abandonado (mínimo 60).
 * @return bool true si esta corrida ganó el turno.
 */
function cron_tomar_turno(int $venceSeg = 600): bool
{
    $ahora   = time();
    $vencido = $ahora - max(60, $venceSeg);

    // La fila tiene que existir para poder actualizarla condicionalmente.
    q("INSERT IGNORE INTO app_settings (clave, valor) VALUES ('cron_turno', '0')");
    $tomado = q("UPDATE app_settings SET valor = ?
                 WHERE clave = 'cron_turno'
                   AND (valor = '' OR valor = '0' OR CAST(valor AS UNSIGNED) < ?)",
                [(string)$ahora, $vencido])->rowCount();

    setting_refrescar();
    return $tomado === 1;
}

/** Suelta el turno al terminar. */
function cron_soltar_turno(): void
{
    q("UPDATE app_settings SET valor = '0' WHERE clave = 'cron_turno'");
    setting_refrescar();
}

// ------------------------------------------------------------
//  Plantillas
// ------------------------------------------------------------

/**
 * Plantillas que no están en la papelera, agrupadas por carpeta (las sin
 * carpeta al final) y por nombre.
 *
 * @param bool $soloActivas true para el selector de la campaña: sólo las
 *                          marcadas «Disponible para campañas».
 */
function plantillas_listar(bool $soloActivas = false): array
{
    $w = 'WHERE eliminado_en IS NULL' . ($soloActivas ? ' AND activo = 1' : '');
    return q("SELECT * FROM plantillas $w ORDER BY carpeta IS NULL, carpeta, nombre")->fetchAll();
}

/** Una plantilla por id, o null si no existe o está en la papelera. */
function plantilla_get(int $id): ?array
{
    return q1('SELECT * FROM plantillas WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

/**
 * Datos de la empresa que usan las plantillas.
 *
 * Salen de Ajustes (`empresa_*`) y alimentan los bloques de encabezado,
 * contacto y WhatsApp. Se resuelven al GUARDAR la plantilla, no al enviar:
 * si después se cambia el teléfono en Ajustes, las plantillas ya guardadas
 * siguen con el viejo hasta que se vuelvan a guardar.
 */
function contexto_marca(): array
{
    return [
        'empresa'          => (string) setting_get('empresa_nombre', 'INTELLIHELP'),
        'empresa_web'      => (string) setting_get('empresa_web', ''),
        'empresa_telefono' => (string) setting_get('empresa_telefono', ''),
        'empresa_email'    => (string) setting_get('empresa_email', ''),
        'empresa_whatsapp' => (string) setting_get('empresa_whatsapp', ''),
        'logo'             => url_abs('assets/img/ITH-logo.png'),
    ];
}

/**
 * Crea o actualiza una plantilla.
 *
 * Siempre se guarda como 'visual': el HTML se genera acá a partir de los
 * bloques. El tipo 'html' (HTML escrito a mano en `cuerpo_html`) existió
 * pero ninguna pantalla lo manda, y aceptarlo de un POST armado a mano
 * dejaba guardar cualquier HTML (scripts incluidos) que después se mostraba
 * en el CRM. Si llega `tipo=html` o `cuerpo_html`, se ignoran.
 *
 * @param array    $d  datos del formulario: nombre, asunto, bloques (JSON
 *                     con {bloques, ajustes} o la lista sola), carpeta,
 *                     preajuste, activo.
 * @param int|null $id null para crear.
 * @return int id de la plantilla.
 * Efectos: escribe `plantillas` y deja registro en el log de actividad.
 */
function plantilla_guardar(array $d, ?int $id = null): int
{
    $tipo = 'visual';

    // En el editor visual el HTML NO se escribe a mano: se genera a partir de
    // los bloques. Así el correo siempre sale con la estructura de tablas que
    // sobrevive en Outlook, sin depender de que quien lo arma sepa de HTML.
    $bloques = null;
    $html    = '';
    $texto   = '';

    if ($tipo === 'visual') {
        // Se aceptan los dos formatos históricos del JSON: {bloques, ajustes}
        // (el actual) o la lista de bloques suelta (plantillas viejas).
        $crudo = $d['bloques'] ?? '[]';
        $datos = is_array($crudo) ? $crudo : (json_decode((string)$crudo, true) ?: []);
        $lista = is_array($datos['bloques'] ?? null) ? $datos['bloques'] : (is_array($datos) ? $datos : []);
        $aj    = is_array($datos['ajustes'] ?? null) ? $datos['ajustes'] : [];
        $ctx   = contexto_marca();
        $html  = bloques_render($lista, $aj, $ctx);
        $texto = bloques_texto($lista, $ctx);
        $bloques = json_encode(['bloques' => $lista, 'ajustes' => $aj],
                               JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $campos = [
        'nombre'       => trim((string)($d['nombre'] ?? '')),
        'asunto'       => trim((string)($d['asunto'] ?? '')),
        'cuerpo_html'  => $html,
        'cuerpo_texto' => $texto ?: null,
        'bloques'      => $bloques,
        'carpeta'      => ($d['carpeta'] ?? null) ?: null,
        'tipo'         => $tipo,
        'preajuste'    => ($d['preajuste'] ?? null) ?: null,
        'activo'       => !empty($d['activo']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE plantillas SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('plantilla.editar', ['entidad' => 'plantilla', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO plantillas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('plantilla.crear', ['entidad' => 'plantilla', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

/**
 * Variables que se pueden usar en asunto y cuerpo.
 *
 * Es el catálogo que muestra el editor (clave => descripción). Agregar una
 * acá no alcanza: también hay que resolverla en render_plantilla() y
 * llenarla en campana_armar_mensaje().
 */
function variables_plantilla(): array
{
    return [
        '{{nombre}}'           => 'Nombre de pila del destinatario',
        '{{apellido}}'         => 'Apellido',
        '{{nombre_completo}}'  => 'Nombre y apellido',
        '{{empresa}}'          => 'Empresa del destinatario',
        '{{cargo}}'            => 'Cargo',
        '{{email}}'            => 'Correo del destinatario',
        '{{remitente}}'        => 'Tu nombre',
        '{{remitente_empresa}}' => 'El nombre de nuestra empresa',
        '{{empresa_web}}'      => 'Nuestro sitio web',
        '{{agenda}}'           => 'Enlace a tu agenda para que reserve una reunión',
        '{{baja}}'             => 'Enlace para darse de baja (obligatorio)',
    ];
}

/**
 * Reemplaza las variables de una plantilla por los datos del destinatario.
 *
 * Sirve para el asunto y el texto plano (valores tal cual) y para el HTML,
 * donde hay que pedirlo con $html = true: ahí cada valor se escapa con e().
 * Un nombre o una empresa los puede escribir cualquiera desde el formulario
 * de la web, y sin escapar un `<img onerror=…>` llegaba como etiqueta al
 * correo y a las vistas previas del CRM. Las direcciones (agenda, baja)
 * también se escapan, que es lo correcto dentro de un href.
 * Sin nombre de pila, {{nombre}} vale «Hola» para que «{{nombre}},» no
 * quede como «,».
 *
 * @param string $texto plantilla con variables {{...}}.
 * @param array  $datos nombre, apellido, empresa, cargo, email, remitente,
 *                      remitente_empresa, empresa_web, agenda, baja.
 * @param bool   $html  true si $texto es HTML: los valores se escapan.
 * @return string el texto con las variables reemplazadas; las desconocidas
 *                quedan tal cual.
 */
function render_plantilla(string $texto, array $datos, bool $html = false): string
{
    $nombre   = trim((string)($datos['nombre'] ?? ''));
    $apellido = trim((string)($datos['apellido'] ?? ''));
    // El ajuste sólo se consulta si hace falta: así esta función sirve también
    // fuera de la aplicación (por ejemplo en la batería de pruebas, que corre
    // sin base de datos).
    $ajuste = static function (string $clave, string $def): string {
        return function_exists('setting_get') ? (string) setting_get($clave, $def) : $def;
    };
    $mapa = [
        '{{nombre}}'            => $nombre !== '' ? $nombre : 'Hola',
        '{{apellido}}'          => $apellido,
        '{{nombre_completo}}'   => trim($nombre . ' ' . $apellido),
        '{{empresa}}'           => (string)($datos['empresa'] ?? ''),
        '{{cargo}}'             => (string)($datos['cargo'] ?? ''),
        '{{email}}'             => (string)($datos['email'] ?? ''),
        '{{remitente}}'         => (string)($datos['remitente'] ?? ''),
        '{{remitente_empresa}}' => (string)($datos['remitente_empresa'] ?? $ajuste('empresa_nombre', 'INTELLIHELP')),
        '{{empresa_web}}'       => (string)($datos['empresa_web'] ?? $ajuste('empresa_web', '')),
        '{{agenda}}'            => (string)($datos['agenda'] ?? ''),
        '{{baja}}'              => (string)($datos['baja'] ?? ''),
    ];
    if ($html) {
        $mapa = array_map('e', $mapa);
    }
    return strtr($texto, $mapa);
}

// ------------------------------------------------------------
//  Listas de destinatarios
// ------------------------------------------------------------

/**
 * Listas vivas con su segmento y la cantidad de miembros.
 *
 * El id de categoría se concatena en el SQL: es seguro sólo porque el tipo
 * `?int` ya lo obliga a ser un número.
 *
 * @param int|null $categoriaId filtrar por segmento; null = todas.
 */
function listas_listar(?int $categoriaId = null): array
{
    $w = 'WHERE l.eliminado_en IS NULL' . ($categoriaId ? ' AND l.categoria_id = ' . $categoriaId : '');
    return q("SELECT l.*, c.nombre AS categoria, c.color AS categoria_color,
                     (SELECT COUNT(*) FROM lista_miembros m WHERE m.lista_id = l.id) AS miembros
              FROM listas l LEFT JOIN categorias c ON c.id = l.categoria_id
              $w ORDER BY c.orden IS NULL, c.orden, l.nombre")->fetchAll();
}

/** Una lista por id, o null si no existe o está en la papelera. */
function lista_get(int $id): ?array
{
    return q1('SELECT * FROM listas WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

/**
 * Crea o actualiza una lista (nombre, descripción, segmento).
 *
 * @param array    $d  nombre, descripcion, categoria_id.
 * @param int|null $id null para crear.
 * @return int id de la lista. Deja registro en el log de actividad.
 */
function lista_guardar(array $d, ?int $id = null): int
{
    $nombre = trim((string)($d['nombre'] ?? ''));
    $cat    = ($d['categoria_id'] ?? null) ?: null;
    if ($id) {
        q('UPDATE listas SET nombre = ?, descripcion = ?, categoria_id = ? WHERE id = ?',
          [$nombre, ($d['descripcion'] ?? null) ?: null, $cat, $id]);
        log_app('lista.editar', ['entidad' => 'lista', 'entidad_id' => $id, 'detalle' => $nombre]);
        return $id;
    }
    q('INSERT INTO listas (nombre, descripcion, categoria_id, creado_por) VALUES (?, ?, ?, ?)',
      [$nombre, ($d['descripcion'] ?? null) ?: null, $cat, current_user_id()]);
    $nuevo = last_id();
    log_app('lista.crear', ['entidad' => 'lista', 'entidad_id' => $nuevo, 'detalle' => $nombre]);
    return $nuevo;
}

/**
 * Carga en una lista todos los contactos que cumplen un filtro del CRM.
 * Devuelve cuántos se agregaron (los repetidos se ignoran por la clave única).
 *
 * Ya acá se descartan los que no tienen correo, los marcados «no incluir en
 * campañas» y los que rebotaron; igual campana_preparar() lo vuelve a mirar
 * al armar la cola, porque entre una cosa y otra pueden pasar semanas.
 * Se inserta de a uno: con filtros de miles de contactos son miles de
 * consultas, pero la carga es manual y poco frecuente.
 *
 * @param int   $listaId lista destino.
 * @param array $filtro  el mismo formato que usa la pantalla de contactos
 *                       (lo traduce contactos_where() de crm.php).
 */
function lista_cargar_contactos(int $listaId, array $filtro): int
{
    [$where, $p] = contactos_where($filtro);
    $rows = q("SELECT ct.id, ct.nombre, ct.apellido, ct.email, cu.nombre AS empresa
               FROM contactos ct
               LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
               $where AND ct.email IS NOT NULL AND ct.email <> ''
                      AND ct.no_email = 0 AND ct.email_rebotado = 0", $p)->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $st = q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre, empresa)
                 VALUES (?, ?, ?, ?, ?)',
                [$listaId, (int)$r['id'], normalizar_email($r['email']),
                 trim($r['nombre'] . ' ' . $r['apellido']), $r['empresa']]);
        $n += $st->rowCount();
    }
    log_app('lista.cargar', ['entidad' => 'lista', 'entidad_id' => $listaId,
        'detalle' => $n . ' contactos agregados desde el filtro del CRM']);
    return $n;
}

/**
 * SQL de los datos reales de un miembro de lista.
 *
 * `lista_miembros` guarda el correo, el nombre y la empresa con los que se
 * agregó a la persona. Cuando el miembro es un contacto del CRM esa copia se
 * queda vieja apenas alguien edita la ficha: la lista sigue mostrando —y, lo
 * que es peor, sigue escribiendo a— el correo anterior.
 *
 * Así que cuando hay un contacto atrás, manda el contacto. La copia queda como
 * respaldo para los correos sueltos importados, que no tienen ficha detrás y
 * son el motivo por el que esas columnas existen.
 *
 * Espera los alias `m` (lista_miembros), `ct`, `cu` y `pc` que arma
 * lista_miembro_join(). El JOIN no filtra `ct.eliminado_en`: un contacto en
 * la papelera sigue figurando en la lista (se puede restaurar), pero
 * campana_preparar() y el despachador lo dejan afuera del envío.
 */
function lista_miembro_sql(): string
{
    return "COALESCE(NULLIF(ct.email, ''), NULLIF(pc.email, ''), m.email) AS email,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ct.nombre, ct.apellido)), ''),
                     NULLIF(TRIM(CONCAT_WS(' ', pc.nombre, pc.apellido)), ''),
                     m.nombre) AS nombre,
            COALESCE(NULLIF(cu.nombre, ''), NULLIF(pc.empresa, ''), m.empresa) AS empresa";
}

/** Los JOIN que hacen falta para lo de arriba. */
function lista_miembro_join(): string
{
    return 'LEFT JOIN contactos ct         ON ct.id = m.contacto_id
            LEFT JOIN cuentas cu           ON cu.id = ct.cuenta_id
            LEFT JOIN posibles_clientes pc ON pc.id = m.posible_cliente_id';
}

/**
 * Miembros de una lista, paginados, con sus datos vivos y sus marcas
 * (dado de baja, no incluir, rebotado).
 *
 * @param int   $listaId
 * @param array $f q (búsqueda libre) y page.
 * @return array lo de paginar() + rows + total.
 */
function lista_miembros(int $listaId, array $f = []): array
{
    $datos = lista_miembro_sql();
    $join  = lista_miembro_join();
    $w = ['m.lista_id = ?'];
    $p = [$listaId];
    if (!empty($f['q'])) {
        // Se busca sobre los datos vivos, no sobre la copia: si no, buscar el
        // correo nuevo de alguien no lo encontraba en la lista donde está.
        // A diferencia de lista_miembro_sql(), acá el COALESCE no lleva
        // NULLIF: un contacto con el correo vacío ('') se busca por la cadena
        // vacía y no cae a la copia de la lista.
        $w[] = "(COALESCE(ct.email, pc.email, m.email) LIKE ?
                 OR CONCAT_WS(' ', ct.nombre, ct.apellido, pc.nombre, pc.apellido, m.nombre) LIKE ?
                 OR COALESCE(cu.nombre, pc.empresa, m.empresa) LIKE ?)";
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like);
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    $total = (int) qv("SELECT COUNT(*) FROM lista_miembros m $join $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT m.id, m.lista_id, m.contacto_id, m.posible_cliente_id, m.creado_en,
                       $datos,
                       (d.email IS NOT NULL) AS dado_de_baja,
                       COALESCE(ct.no_email, 0) AS no_email,
                       COALESCE(ct.email_rebotado, 0) AS email_rebotado
                FROM lista_miembros m
                $join
                LEFT JOIN desuscripciones d ON d.email = COALESCE(NULLIF(ct.email,''), NULLIF(pc.email,''), m.email)
                $where ORDER BY empresa, nombre LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Alinea la copia guardada en las listas con la ficha del contacto.
 *
 * Con el COALESCE de arriba alcanza para mostrar y para enviar bien, pero la
 * columna `email` además sostiene la clave única de la lista (una persona una
 * sola vez) y se usa para cruzar bajas. Si queda desfasada, esa clave protege
 * al correo equivocado. Se corre al guardar un contacto.
 *
 * El correo nuevo puede chocar con otro miembro que ya lo tenía: en ese caso
 * el que sobra se borra, porque son la misma persona en la misma lista.
 *
 * Si el contacto se quedó sin correo, la copia pasa a un marcador
 * ('sin-correo+<id del miembro>@invalido.local', ver lista_marcador_sin_correo())
 * para no violar el NOT NULL; campana_preparar() lo descarta como inválido.
 * Lleva el id del miembro porque la columna sostiene la clave única
 * (lista_id, email): con un marcador fijo, dos contactos sin correo en la
 * misma lista chocaban y guardar el segundo daba error.
 *
 * @return int cuántas filas de lista_miembros se actualizaron.
 */
function lista_sincronizar_contacto(int $contactoId): int
{
    $ct = q1('SELECT ct.id, ct.email, ct.nombre, ct.apellido, cu.nombre AS empresa
              FROM contactos ct LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
              WHERE ct.id = ?', [$contactoId]);
    if (!$ct) { return 0; }

    $email   = normalizar_email((string)$ct['email']);
    $nombre  = trim($ct['nombre'] . ' ' . $ct['apellido']);
    $n       = 0;
    foreach (q('SELECT id, lista_id FROM lista_miembros WHERE contacto_id = ?', [$contactoId])->fetchAll() as $m) {
        if ($email !== '') {
            q('DELETE FROM lista_miembros WHERE lista_id = ? AND email = ? AND id <> ?',
              [(int)$m['lista_id'], $email, (int)$m['id']]);
        }
        $n += q('UPDATE lista_miembros SET email = ?, nombre = ?, empresa = ? WHERE id = ?',
                [$email !== '' ? $email : lista_marcador_sin_correo((int)$m['id']),
                 $nombre, $ct['empresa'], (int)$m['id']])->rowCount();
    }
    return $n;
}

/** El correo de relleno de un miembro sin correo: único por miembro. */
function lista_marcador_sin_correo(int $miembroId): string
{
    return 'sin-correo+' . $miembroId . '@invalido.local';
}

/**
 * Por qué un miembro de la lista NO tiene que recibir la campaña, o null.
 *
 * Es pura (recibe todo resuelto) para poder probarla sin base; la usan
 * campana_preparar() al armar la cola. El orden decide qué motivo se anota
 * cuando hay varios.
 *
 * @param string $email       correo ya normalizado.
 * @param bool   $dadoDeBaja  figura en `desuscripciones`.
 * @param array  $m           marcas del contacto (ct_*) y del posible
 *                            cliente (pc_*): no_email, email_rebotado,
 *                            eliminado_en.
 */
function campana_motivo_omision(string $email, bool $dadoDeBaja, array $m): ?string
{
    if (!email_valido($email) || str_ends_with($email, '@invalido.local')) { return 'Correo inválido'; }
    if ($dadoDeBaja)                                  { return 'Se dio de baja'; }
    if (!empty($m['ct_eliminado_en']) || !empty($m['pc_eliminado_en'])) {
        return 'Está en la papelera';
    }
    if (!empty($m['ct_no_email']) || !empty($m['pc_no_email'])) {
        return 'Marcado como «no incluir en campañas»';
    }
    if (!empty($m['ct_email_rebotado']) || !empty($m['pc_email_rebotado'])) {
        return 'El correo rebotó antes';
    }
    return null;
}

// ------------------------------------------------------------
//  Campañas
// ------------------------------------------------------------

/**
 * Listado paginado de campañas con sus cifras contadas sobre la cola.
 *
 * Lo usan la pantalla de campañas y api-campana (que lo consulta cada pocos
 * segundos). No trae `reuniones` calculado: esa cifra sale de la columna
 * `campanas.reuniones` que suma agenda.php vía `c.*`.
 *
 * @param array $f estado, q (nombre), categoria_id, page.
 * @return array lo de paginar() + rows + total.
 */
function campanas_listar(array $f = []): array
{
    $w = ['c.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['estado'])) { $w[] = 'c.estado = ?'; $p[] = $f['estado']; }
    if (!empty($f['q']))      { $w[] = 'c.nombre LIKE ?'; $p[] = '%' . $f['q'] . '%'; }
    if (!empty($f['categoria_id'])) { $w[] = 'c.categoria_id = ?'; $p[] = (int)$f['categoria_id']; }
    $where = 'WHERE ' . implode(' AND ', $w);
    $total = (int) qv("SELECT COUNT(*) FROM campanas c $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT c.*, l.nombre AS lista, pl.nombre AS plantilla, u.nombre AS propietario,
                       cat.nombre AS categoria, cat.color AS categoria_color
                FROM campanas c
                LEFT JOIN listas l     ON l.id  = c.lista_id
                LEFT JOIN plantillas pl ON pl.id = c.plantilla_id
                LEFT JOIN usuarios u   ON u.id  = c.propietario_id
                LEFT JOIN categorias cat ON cat.id = c.categoria_id
                $where ORDER BY c.creado_en DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    // Los contadores se leen de la cola en el momento, no de las columnas de
    // la campaña: si el envío se corta a mitad, esas columnas quedan
    // desactualizadas. Van en UNA consulta agrupada para toda la página, no
    // en siete subconsultas por fila: api-campana pide esto cada pocos
    // segundos.
    $cifras = campanas_cifras(array_map(fn($r) => (int)$r['id'], $rows));
    foreach ($rows as &$r) {
        $r = array_merge($r, $cifras[(int)$r['id']] ?? campanas_cifras_vacias());
    }
    unset($r);
    return $pg + ['rows' => $rows, 'total' => $total];
}

/** Las cifras de una campaña sin ningún envío. */
function campanas_cifras_vacias(): array
{
    return ['total' => 0, 'enviados' => 0, 'rebotados' => 0, 'respondidos' => 0, 'errores' => 0, 'procesados' => 0];
}

/**
 * Cifras de varias campañas contadas sobre la cola, en una sola consulta.
 *
 * «Procesados» es lo que ya se resolvió: entregados, rebotados y fallidos.
 * Es lo que mide el AVANCE, y no es lo mismo que «enviados». Una campaña que
 * entregó 3 y rebotó 3 terminó su trabajo con los 6: mostrar «3 de 6» hacía
 * parecer que se había quedado a mitad de camino.
 *
 * @param int[] $ids
 * @return array<int,array> id => total, enviados, rebotados, respondidos,
 *                          errores, procesados (las campañas sin envíos no
 *                          figuran).
 */
function campanas_cifras(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return []; }
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (q("SELECT campana_id,
                       SUM(CASE WHEN estado <> 'omitido' THEN 1 ELSE 0 END) AS total,
                       SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END) AS enviados,
                       SUM(CASE WHEN estado IN ('rebotado','rechazado') THEN 1 ELSE 0 END) AS rebotados,
                       SUM(CASE WHEN respondido_en IS NOT NULL THEN 1 ELSE 0 END) AS respondidos,
                       SUM(CASE WHEN estado = 'error' THEN 1 ELSE 0 END) AS errores,
                       SUM(CASE WHEN estado NOT IN ('pendiente','enviando','omitido') THEN 1 ELSE 0 END) AS procesados
                FROM envios WHERE campana_id IN ($ph) GROUP BY campana_id", $ids)->fetchAll() as $f) {
        $id = (int)$f['campana_id'];
        unset($f['campana_id']);
        $out[$id] = array_map('intval', $f);
    }
    return $out;
}

/**
 * Una campaña con todo lo que hace falta para mostrarla y para enviarla:
 * nombres de lista, plantilla y responsable, el asunto y el cuerpo de la
 * plantilla, y las cifras contadas sobre la cola en el momento.
 *
 * Las columnas `total`, `enviados`, etc. de la tabla quedan tapadas por las
 * subconsultas del mismo nombre (van después de `c.*`).
 *
 * @return array|null null si no existe o está en la papelera.
 */
function campana_get(int $id): ?array
{
    return q1('SELECT c.*, l.nombre AS lista, pl.nombre AS plantilla, pl.asunto AS plantilla_asunto,
                      pl.cuerpo_html, pl.cuerpo_texto, u.nombre AS propietario,
                      cat.nombre AS categoria, cat.color AS categoria_color,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado <> "omitido") AS total,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = "enviado") AS enviados,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado IN ("rebotado","rechazado")) AS rebotados,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.respondido_en IS NOT NULL) AS respondidos,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = "error") AS errores,
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.abierto_en IS NOT NULL) AS aperturas,
                      -- Ver el comentario de campanas_listar(): el avance mide lo
                      -- resuelto, no sólo lo entregado.
                      (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id
                        AND e.estado NOT IN ("pendiente","enviando","omitido")) AS procesados
               FROM campanas c
               LEFT JOIN listas l      ON l.id  = c.lista_id
               LEFT JOIN plantillas pl ON pl.id = c.plantilla_id
               LEFT JOIN usuarios u    ON u.id  = c.propietario_id
               LEFT JOIN categorias cat ON cat.id = c.categoria_id
               WHERE c.id = ? AND c.eliminado_en IS NULL', [$id]);
}

/**
 * Crea o actualiza una campaña con lo que llega del formulario.
 *
 * No valida que la plantilla, la lista o la cuenta existan (eso lo marca
 * después campana_pasos()), ni que el remitente esté autorizado en el
 * servidor de salida. Las pausas tienen piso (5 y 10 s) y el límite diario
 * mínimo es 1; no hay techo.
 *
 * @param array    $d  campos del formulario campana-form.
 * @param int|null $id null para crear.
 * @return int id de la campaña. Deja registro en el log de actividad.
 */
function campana_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'           => trim((string)($d['nombre'] ?? '')),
        'tipo'             => ($d['tipo'] ?? null) ?: 'Correo masivo',
        'plantilla_id'     => ($d['plantilla_id'] ?? null) ?: null,
        'lista_id'         => ($d['lista_id'] ?? null) ?: null,
        'cuenta_correo_id' => ($d['cuenta_correo_id'] ?? null) ?: null,
        'categoria_id'     => ($d['categoria_id'] ?? null) ?: null,
        'propietario_id'   => ($d['propietario_id'] ?? null) ?: current_user_id(),
        'remitente_nombre' => ($d['remitente_nombre'] ?? null) ?: null,
        'remitente_email'  => normalizar_email($d['remitente_email'] ?? '') ?: null,
        'responder_a'      => normalizar_email($d['responder_a'] ?? '') ?: null,
        'copia_a'          => ($d['copia_a'] ?? null) ?: null,
        'asunto'           => ($d['asunto'] ?? null) ?: null,
        'pausa_min_seg'    => max(5,  (int)($d['pausa_min_seg'] ?? 25)),
        'pausa_max_seg'    => max(10, (int)($d['pausa_max_seg'] ?? 45)),
        'limite_diario'    => max(1,  (int)($d['limite_diario'] ?? 300)),
        'enviar_desde'     => ($d['enviar_desde'] ?? null) ?: null,
        'enviar_hasta'     => ($d['enviar_hasta'] ?? null) ?: null,
        'programada_para'  => ($d['programada_para'] ?? null) ?: null,
        'descripcion'      => ($d['descripcion'] ?? null) ?: null,
    ];
    // La pausa mínima nunca puede superar a la máxima: si el usuario las
    // invierte, se ordenan solas en vez de romper el despachador.
    if ($campos['pausa_min_seg'] > $campos['pausa_max_seg']) {
        [$campos['pausa_min_seg'], $campos['pausa_max_seg']] = [$campos['pausa_max_seg'], $campos['pausa_min_seg']];
    }
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE campanas SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('campana.editar', ['entidad' => 'campana', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO campanas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('campana.crear', ['entidad' => 'campana', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

/**
 * Arma la cola de la campaña a partir de su lista.
 *
 * Reglas de exclusión (en este orden), cada una con su motivo registrado
 * (campana_motivo_omision()):
 *   1. correo inválido (incluido el marcador de los miembros sin correo)
 *   2. dado de baja (lista negra global)
 *   3. contacto o posible cliente en la papelera
 *   4. contacto o posible cliente marcado «no incluir en campañas»
 *   5. correo que ya rebotó antes
 *   6. repetido dentro de la misma campaña (lo impide la clave única)
 *
 * Es idempotente: se puede volver a preparar sin duplicar ni reenviar.
 * Volver a preparar sí suma a la cola a quienes se agregaron a la lista
 * después; y si a alguien le cambió el correo después de recibir la campaña,
 * entra de nuevo con la dirección nueva (la clave única es campaña+correo).
 *
 * Los omitidos también se insertan (estado 'omitido' con su motivo): así la
 * pantalla puede mostrar por qué no le llegó a cada uno.
 *
 * No mira el estado de la campaña: preparar una completada o cancelada deja
 * filas 'pendiente' que nadie despacha hasta que se vuelva a iniciar.
 *
 * @return array{agregados:int, omitidos:int, motivos:array<string,int>}
 * @throws RuntimeException si la campaña no existe o le falta lista o plantilla.
 */
function campana_preparar(int $campanaId): array
{
    $c = campana_get($campanaId);
    if (!$c)                 { throw new RuntimeException('La campaña no existe.'); }
    if (!$c['lista_id'])     { throw new RuntimeException('La campaña no tiene una lista de destinatarios.'); }
    if (!$c['plantilla_id']) { throw new RuntimeException('La campaña no tiene una plantilla.'); }

    $res = ['agregados' => 0, 'omitidos' => 0, 'motivos' => []];
    // Los datos salen de la ficha del contacto, no de la copia que guardó la
    // lista el día que se armó. Con la copia, cambiar el correo de alguien no
    // servía de nada: la campaña le seguía escribiendo a la dirección vieja.
    $datos    = lista_miembro_sql();
    $join     = lista_miembro_join();
    $miembros = q("SELECT m.contacto_id, m.posible_cliente_id, $datos,
                          ct.no_email AS ct_no_email, ct.email_rebotado AS ct_email_rebotado,
                          ct.eliminado_en AS ct_eliminado_en,
                          pc.no_email AS pc_no_email, pc.email_rebotado AS pc_email_rebotado,
                          pc.eliminado_en AS pc_eliminado_en
                   FROM lista_miembros m $join
                   WHERE m.lista_id = ?", [(int)$c['lista_id']])->fetchAll();

    // Una consulta a desuscripciones por miembro: con listas de miles son miles
    // de consultas, aceptable porque se prepara a mano y de vez en cuando.
    foreach ($miembros as $m) {
        $email  = normalizar_email($m['email']);
        $baja   = email_valido($email) && (bool) qv('SELECT 1 FROM desuscripciones WHERE email = ?', [$email]);
        $motivo = campana_motivo_omision($email, $baja, $m);

        // INSERT IGNORE: si la fila ya existe (misma campaña y correo) no se
        // toca, ni siquiera su estado. El token es el que después identifica
        // al destinatario en la baja y en el píxel de apertura.
        $st = q('INSERT IGNORE INTO envios (campana_id, contacto_id, posible_cliente_id, email, nombre, empresa, estado, motivo, token, proximo_intento_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$campanaId, ($m['contacto_id'] ?? null) ?: null, ($m['posible_cliente_id'] ?? null) ?: null,
                 $email, $m['nombre'], $m['empresa'],
                 $motivo ? 'omitido' : 'pendiente', $motivo, token32()]);
        if ($st->rowCount() > 0) {
            if ($motivo) {
                $res['omitidos']++;
                $res['motivos'][$motivo] = ($res['motivos'][$motivo] ?? 0) + 1;
            } else {
                $res['agregados']++;
            }
        }
    }

    campana_recontar($campanaId);
    log_app('campana.preparar', ['entidad' => 'campana', 'entidad_id' => $campanaId,
        'detalle' => $res['agregados'] . ' en cola, ' . $res['omitidos'] . ' omitidos']);
    return $res;
}

/**
 * Recalcula los contadores de la campaña desde la cola real.
 *
 * Las columnas guardadas sirven a quien no hace las subconsultas (reportes,
 * por ejemplo). El despachador lo llama después de cada correo.
 */
function campana_recontar(int $campanaId): void
{
    q("UPDATE campanas c SET
         c.total       = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado <> 'omitido'),
         c.enviados    = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = 'enviado'),
         c.rebotados   = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado IN ('rebotado','rechazado')),
         c.respondidos = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.respondido_en IS NOT NULL),
         c.errores     = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = 'error')
       WHERE c.id = ?", [$campanaId]);
}

/**
 * Cambia el estado de una campaña (iniciar, pausar, reanudar, cancelar).
 *
 * No controla transiciones (lo usan también el despachador y el cron): las
 * que llegan de la pantalla las valida antes campana-accion con
 * campana_transicion_valida(). Al pasar a 'enviando' se fija `inicio_en`
 * sólo la primera vez; al pasar a 'completada' se fija `fin_en`.
 *
 * @throws InvalidArgumentException si el estado no es uno de los conocidos.
 */
function campana_estado(int $id, string $estado): void
{
    $validos = ['borrador', 'programada', 'enviando', 'pausada', 'completada', 'cancelada'];
    if (!in_array($estado, $validos, true)) {
        throw new InvalidArgumentException('Estado inválido: ' . $estado);
    }
    $extra = '';
    if ($estado === 'enviando')   { $extra = ', inicio_en = COALESCE(inicio_en, NOW())'; }
    if ($estado === 'completada') { $extra = ', fin_en = NOW()'; }
    q("UPDATE campanas SET estado = ? $extra WHERE id = ?", [$estado, $id]);
    log_app('campana.' . $estado, ['entidad' => 'campana', 'entidad_id' => $id,
        'detalle' => 'La campaña pasó a «' . $estado . '»']);
}

/**
 * ¿Se puede aplicar esta acción de la pantalla a una campaña en este estado?
 *
 *   preparar → mientras la campaña no terminó (borrador, programada,
 *              enviando, pausada). Sobre una completada o cancelada dejaba
 *              filas pendientes que nadie iba a despachar.
 *   iniciar  → desde borrador, pausada o programada. Antes un POST directo
 *              volvía a poner a enviar una completada o una cancelada.
 *   pausar   → desde enviando o programada.
 *   cancelar → desde borrador, programada, enviando o pausada.
 */
function campana_transicion_valida(string $estado, string $accion): bool
{
    $desde = [
        'preparar' => ['borrador', 'programada', 'enviando', 'pausada'],
        'iniciar'  => ['borrador', 'pausada', 'programada'],
        'pausar'   => ['enviando', 'programada'],
        'cancelar' => ['borrador', 'programada', 'enviando', 'pausada'],
    ];
    return in_array($estado, $desde[$accion] ?? [], true);
}

/**
 * ¿Este usuario puede iniciar o cancelar la campaña?
 *
 * Su creador, su responsable o un admin. Preparar la cola y pausar quedan
 * abiertos a todo el equipo: no mandan nada (pausar, al contrario, frena).
 *
 * @param array      $c la campaña (creado_por, propietario_id).
 * @param array|null $u el usuario (id, rol); null = sin sesión.
 */
function campana_puede_gestionar(array $c, ?array $u): bool
{
    if (!$u) { return false; }
    if (($u['rol'] ?? '') === 'admin') { return true; }
    $uid = (int)($u['id'] ?? 0);
    return $uid > 0 && ($uid === (int)($c['creado_por'] ?? 0) || $uid === (int)($c['propietario_id'] ?? 0));
}

/**
 * Los correos a los que se puede mandar en modo prueba.
 *
 * Son los de los usuarios activos del CRM más los del ajuste
 * `testing_lista_blanca` (separados por coma, punto y coma o espacios), todo
 * normalizado. En producción no se usa.
 *
 * @return array<string,bool> correo => true, para buscar rápido.
 */
function campana_lista_blanca(): array
{
    $extra = function_exists('setting_get') ? (string) setting_get('testing_lista_blanca', '') : '';
    $usuarios = [];
    try {
        $usuarios = q('SELECT email FROM usuarios WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        // Sin la tabla, sólo vale el ajuste.
    }
    return campana_lista_blanca_armar($usuarios, $extra);
}

/** La parte pura de campana_lista_blanca(): junta y normaliza. */
function campana_lista_blanca_armar(array $usuarios, string $extra): array
{
    $out = [];
    foreach (array_merge($usuarios, preg_split('/[\s,;]+/', $extra) ?: []) as $e) {
        $e = normalizar_email((string)$e);
        if ($e !== '' && email_valido($e)) { $out[$e] = true; }
    }
    return $out;
}

/**
 * ¿Se le puede mandar a este correo? Siempre en producción; en modo prueba
 * sólo si está en la lista blanca.
 *
 * @param array|null $lista la de campana_lista_blanca(), para no releerla
 *                          en cada correo; null = la lee.
 */
function campana_destino_permitido(string $email, bool $testing, ?array $lista = null): bool
{
    if (!$testing) { return true; }
    $lista = $lista ?? campana_lista_blanca();
    return isset($lista[normalizar_email($email)]);
}

/** Motivo con el que queda omitido un envío fuera de la lista blanca. */
const CAMPANA_MOTIVO_TESTING = 'Modo prueba: fuera de la lista blanca';

/**
 * En modo prueba, pasa a 'omitido' los pendientes de la campaña que no están
 * en la lista blanca. Devuelve cuántos. En producción no hace nada.
 */
function campana_aplicar_lista_blanca(int $campanaId, bool $testing): int
{
    if (!$testing) { return 0; }
    $lista = campana_lista_blanca();
    $n = 0;
    foreach (q("SELECT id, email FROM envios WHERE campana_id = ? AND estado = 'pendiente'", [$campanaId])->fetchAll() as $e) {
        if (!campana_destino_permitido((string)$e['email'], true, $lista)) {
            $n += q("UPDATE envios SET estado = 'omitido', motivo = ? WHERE id = ? AND estado = 'pendiente'",
                    [CAMPANA_MOTIVO_TESTING, (int)$e['id']])->rowCount();
        }
    }
    if ($n > 0) { campana_recontar($campanaId); }
    return $n;
}

/**
 * Enlace de la agenda pública del responsable de una campaña.
 *
 * Es lo que reemplaza a `{{agenda}}`. Si el responsable todavía no publicó
 * ningún tipo de reunión devuelve cadena vacía, y quien llama tiene que
 * avisarlo: un botón «Agendar» sin enlace es peor que no tener botón.
 *
 * El enlace es /r/<parte local del correo del usuario>/<slug del primer tipo
 * activo>. Con `$campanaId` lleva `?c=<id>`, que agenda.php usa para sumarle
 * la reunión a la campaña.
 */
function agenda_de_usuario(?int $usuarioId, ?int $campanaId = null): string
{
    if (!$usuarioId) { return ''; }
    $t = q1('SELECT t.slug, u.email FROM agenda_tipos t
             JOIN usuarios u ON u.id = t.usuario_id
             WHERE t.usuario_id = ? AND t.activo = 1
             ORDER BY t.id LIMIT 1', [$usuarioId]);
    if (!$t) { return ''; }
    return url_abs('r/' . explode('@', (string)$t['email'])[0] . '/' . $t['slug'])
         . ($campanaId ? '?c=' . $campanaId : '');
}

/**
 * Revisa si la campaña está lista para salir y devuelve los pasos pendientes.
 *
 * Esta es la lista que se muestra en la pantalla de la campaña: en vez de un
 * error suelto cuando algo falla, se ve de antemano qué falta, por qué hace
 * falta y adónde ir a resolverlo.
 *
 * @return array<int,array{clave:string,ok:bool,titulo:string,detalle:string,accion:?string,enlace:?string,bloqueante:bool}>
 */
function campana_pasos(array $c): array
{
    $pasos = [];
    $add = static function (string $clave, bool $ok, string $titulo, string $detalle,
                            ?string $accion = null, ?string $enlace = null, bool $bloq = true) use (&$pasos): void {
        $pasos[] = compact('clave', 'ok', 'titulo', 'detalle', 'accion', 'enlace') + ['bloqueante' => $bloq];
    };

    // 1. Plantilla
    $add('plantilla', !empty($c['plantilla_id']),
        'Elegir qué se manda',
        !empty($c['plantilla_id'])
            ? 'Plantilla: ' . ($c['plantilla'] ?? '')
            : 'Falta la plantilla con el diseño y el texto del correo.',
        'Elegir plantilla', url('campana-form?id=' . (int)$c['id']));

    // 2. Destinatarios
    $enCola = (int) qv("SELECT COUNT(*) FROM envios WHERE campana_id = ? AND estado <> 'omitido'", [(int)$c['id']]);
    $enLista = !empty($c['lista_id'])
        ? (int) qv('SELECT COUNT(*) FROM lista_miembros WHERE lista_id = ?', [(int)$c['lista_id']])
        : 0;
    $add('lista', $enLista > 0,
        'Elegir a quién se le manda',
        $enLista > 0
            ? 'Lista «' . ($c['lista'] ?? '') . '» con ' . number_format($enLista, 0, ',', '.') . ' destinatarios.'
            : 'Falta una lista con destinatarios. Se arma desde Listas, cargando contactos del CRM con un filtro.',
        'Ver listas', url('listas'));

    // 3. Cuenta de correo
    $cuenta = !empty($c['cuenta_correo_id'])
        ? cuenta_correo_get((int)$c['cuenta_correo_id'])
        : cuenta_correo_predeterminada();
    $cuentaOk = $cuenta && (int)$cuenta['activo'] === 1;
    // Si la campaña eligió una cuenta y está desactivada (o ya no existe), el
    // despachador la espera en vez de caer a otra casilla: es bloqueante.
    $elegidaMal = !empty($c['cuenta_correo_id']) && !$cuentaOk;
    $add('cuenta', $cuentaOk,
        'Desde qué casilla sale',
        $cuentaOk
            ? 'Sale desde «' . $cuenta['nombre'] . '» (' . $cuenta['remitente'] . '), '
              . 'hasta ' . (int)$cuenta['limite_diario'] . ' por día.'
            : ($elegidaMal
                ? 'La cuenta elegida para esta campaña está desactivada o ya no existe. Activala o elegí otra.'
                : (smtp_configurado()
                    ? 'No hay ninguna cuenta activa elegida. Se puede usar la de Ajustes, pero conviene cargar una cuenta propia.'
                    : 'Falta configurar la cuenta desde la que salen los correos.')),
        'Cuentas de correo', url('cuentas-correo'), $elegidaMal || !smtp_configurado());

    // 3b. ¿Vamos a poder VER las respuestas?
    //
    // El sistema detecta una respuesta leyendo una casilla concreta. Si el
    // «Responder a» de la campaña apunta a otra dirección, la respuesta le llega
    // a esa persona y el CRM no se entera nunca: no marca el envío como
    // respondido, no avisa y no queda registrada.
    if ($cuentaOk) {
        $leemos = normalizar_email((string)(cuenta_a_imap($cuenta)['user'] ?? ''));
        $vuelveA = normalizar_email((string)(($c['responder_a'] ?? '') ?: ($cuenta['responder_a'] ?? '')))
                ?: normalizar_email((string)$cuenta['remitente']);
        $seVe = $leemos !== '' && $vuelveA === $leemos;
        $add('respuestas', $seVe,
            'Las respuestas se van a detectar',
            $seVe
                ? 'Quien responda escribe a ' . $vuelveA . ', que es la casilla que el sistema lee. '
                . 'Cada respuesta marca el envío y te avisa.'
                : 'Las respuestas van a llegar a ' . ($vuelveA ?: 'ninguna parte')
                . ', pero el sistema lee ' . ($leemos ?: 'ninguna casilla') . '. '
                . 'Van a llegarle a esa persona y el CRM NO las va a registrar ni te va a avisar. '
                . 'Dejá «Responder a» vacío, o poné ' . $leemos . '.',
            'Corregirlo', url('campana-form?id=' . (int)$c['id']), false);
    }

    // 4. La agenda, sólo si la plantilla la usa
    $usaAgenda = !empty($c['cuerpo_html']) && str_contains((string)$c['cuerpo_html'], '{{agenda}}');
    if ($usaAgenda) {
        $enlace = agenda_de_usuario((int)($c['propietario_id'] ?? 0));
        $add('agenda', $enlace !== '',
            'Publicar tu agenda',
            $enlace !== ''
                ? 'El botón «Agendar» va a apuntar a ' . $enlace
                : 'La plantilla tiene un botón de agenda, pero ' . ($c['propietario'] ?? 'el responsable')
                  . ' todavía no publicó ningún tipo de reunión: el botón saldría sin enlace.',
            'Crear un tipo de reunión', url('agenda-form'));
    }

    // 5. Cola preparada
    //
    // Va con el tiempo estimado. Sin ese número cada uno hace la cuenta por su
    // cuenta y le da cualquier cosa: es fácil creer que el cron manda un correo
    // por corrida —porque se despierta cada cinco minutos— y concluir que 500
    // correos son casi dos días. No: en cada corrida manda todos los que
    // entran en su presupuesto de tiempo.
    $ritmo = campana_ritmo($c, $enCola);
    $add('cola', $enCola > 0,
        'Preparar la cola',
        $enCola > 0
            ? number_format($enCola, 0, ',', '.') . ' correos en cola. A razón de unos '
              . $ritmo['por_hora'] . ' por hora, van a tardar ' . $ritmo['texto'] . ' en salir todos'
              . ($ritmo['freno'] ? '; lo que lo limita es ' . $ritmo['freno'] : '') . '.'
            : 'Todavía no se armó la cola de envío. Al prepararla se descartan los repetidos, los dados de baja y los que ya rebotaron.',
        null, null);

    return $pasos;
}

/**
 * ¿Se puede arrancar la campaña?
 *
 * Sí cuando no falta ningún paso bloqueante. La cola se excluye a propósito:
 * «Iniciar» la prepara sola. Lo usan la vista (texto del último paso) y
 * campana-accion antes de iniciar.
 */
function campana_lista_para_enviar(array $c): bool
{
    foreach (campana_pasos($c) as $p) {
        if ($p['bloqueante'] && !$p['ok'] && $p['clave'] !== 'cola') { return false; }
    }
    return true;
}

/**
 * Cuánto va a tardar una campaña en salir entera.
 *
 * Hay que decirlo ANTES de arrancar. Sin este número cada uno hace la cuenta
 * por su cuenta y le da cualquier cosa: es fácil creer que el cron manda un
 * correo cada cinco minutos —porque cada cinco minutos se despierta— y concluir
 * que 500 correos son 41 horas. No: en cada corrida manda todos los que entran
 * en su presupuesto de tiempo, uno cada 25 a 45 segundos.
 *
 * Se tienen en cuenta las tres cosas que limitan: el ritmo, el tope
 * diario de la campaña (`limite_diario`) y la franja horaria en la que se
 * permite enviar. El cupo propio de la cuenta de correo no entra en la
 * cuenta (lo comparten todas las campañas de esa casilla).
 *
 * Es una estimación: supone que la corrida trabaja todos los minutos
 * (CRON_PERIODO_SEG) y que ninguna otra campaña comparte la casilla.
 *
 * @param array    $c          la campaña (pausas, franja, límite).
 * @param int|null $pendientes cuántos faltan; null = usa `$c['total']`.
 * @return array{por_hora:int, horas:float, texto:string, freno:?string}
 */
function campana_ritmo(array $c, ?int $pendientes = null): array
{
    $min   = max(1, (int)($c['pausa_min_seg'] ?? 25));
    $max   = max($min, (int)($c['pausa_max_seg'] ?? 45));
    $media = ($min + $max) / 2;

    // Lo que entra en una corrida del cron: el primero sale sin esperar, y
    // después uno por pausa mientras quede presupuesto.
    $porCorrida = (int) floor(CRON_PRESUPUESTO_SEG / $media) + 1;
    $porHora    = (int) round($porCorrida * (3600 / CRON_PERIODO_SEG));

    // La franja horaria: fuera de ella la campaña se frena hasta el otro día.
    $horasDia = 24.0;
    if (!empty($c['enviar_desde']) && !empty($c['enviar_hasta'])) {
        $d = (int)substr((string)$c['enviar_desde'], 0, 2) * 60 + (int)substr((string)$c['enviar_desde'], 3, 2);
        $h = (int)substr((string)$c['enviar_hasta'], 0, 2) * 60 + (int)substr((string)$c['enviar_hasta'], 3, 2);
        $horasDia = (($h > $d ? $h - $d : 1440 - $d + $h)) / 60;
    }

    $tope    = max(1, (int)($c['limite_diario'] ?? 300));
    $porDia  = min((int) round($porHora * $horasDia), $tope);
    $total   = $pendientes ?? (int)($c['total'] ?? 0);

    if ($total <= 0) {
        return ['por_hora' => $porHora, 'horas' => 0.0, 'texto' => '', 'freno' => null];
    }

    $dias  = $total / max(1, $porDia);
    $horas = $dias <= 1 ? $total / max(1, $porHora) : $dias * 24;

    // Qué es lo que manda: el ritmo, el tope diario o la franja. Saberlo es lo
    // que permite decidir qué tocar si el número no gusta.
    $freno = null;
    if ($total > $porDia) {
        $freno = $tope <= (int) round($porHora * $horasDia)
            ? 'el tope de ' . $tope . ' correos por día de la campaña'
            : 'la franja horaria de envío (' . round($horasDia) . ' h por día)';
    }

    if ($dias > 1) {
        $texto = 'unos ' . ceil($dias) . ' días (' . number_format($porDia, 0, ',', '.') . ' por día)';
    } elseif ($horas >= 1) {
        $h = (int) floor($horas);
        $m = (int) round(($horas - $h) * 60);
        $texto = $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
    } else {
        $texto = max(1, (int) round($horas * 60)) . ' min';
    }

    return ['por_hora' => $porHora, 'horas' => $horas, 'texto' => $texto, 'freno' => $freno];
}

/**
 * ¿Estamos dentro de la ventana horaria permitida de la campaña?
 *
 * Sin franja cargada, siempre sí. Compara cadenas 'HH:MM:SS' con la hora de
 * PHP; la base usa el mismo huso porque db.php alinea `time_zone` al conectar.
 */
function campana_en_horario(array $c): bool
{
    if (empty($c['enviar_desde']) || empty($c['enviar_hasta'])) {
        return true;
    }
    $ahora = date('H:i:s');
    // Una ventana que cruza la medianoche (22:00 a 06:00) se lee al revés: el
    // «desde» es mayor que el «hasta». Con la comparación directa nunca se
    // cumplirían las dos condiciones a la vez y la campaña no saldría nunca.
    if ($c['enviar_desde'] > $c['enviar_hasta']) {
        return $ahora >= $c['enviar_desde'] || $ahora <= $c['enviar_hasta'];
    }
    return $ahora >= $c['enviar_desde'] && $ahora <= $c['enviar_hasta'];
}

/**
 * Cuántos correos se mandaron hoy en esta campaña (para el límite diario).
 *
 * Cuenta por `enviado_en`, que sólo se llena al entregar: los rechazados y
 * los errores no gastan cupo. `DATE(enviado_en)` no aprovecha índice, pero
 * el filtro por campana_id ya acota.
 */
function campana_enviados_hoy(int $campanaId): int
{
    return (int) qv('SELECT COUNT(*) FROM envios WHERE campana_id = ? AND DATE(enviado_en) = CURDATE()',
                    [$campanaId]);
}

/**
 * Arma el mensaje concreto para un destinatario de la campaña.
 * Se separa del despacho para poder mostrar una vista previa real en pantalla.
 *
 * @param array      $c      la campaña tal como la devuelve campana_get()
 *                           (necesita cuerpo_html, cuerpo_texto y asuntos).
 * @param array      $envio  la fila de `envios` (token, email, nombre, empresa).
 * @param array|null $cuenta la casilla de salida, para remitente y Reply-To
 *                           cuando la campaña no los define.
 * @param bool       $pixel  false para la vista previa del CRM: sin el píxel
 *                           de apertura, que si no contaba como «abierto» al
 *                           primer destinatario cada vez que alguien miraba
 *                           la campaña.
 * @return array el mensaje en el formato que espera smtp_enviar(), más
 *               `baja_url`. No escribe nada en la base.
 */
function campana_armar_mensaje(array $c, array $envio, ?array $cuenta = null, bool $pixel = true): array
{
    $baja   = url_abs('baja?t=' . $envio['token']);
    // Enlace a la agenda del responsable de la campaña. Lleva el id de la
    // campaña pegado (?c=) para que la reunión que salga de ahí quede atribuida
    // a esta campaña y se pueda medir cuántas reuniones trajo.
    $agenda = agenda_de_usuario((int)($c['propietario_id'] ?? 0), (int)$c['id']);

    // La cola guarda el nombre completo en un solo campo: la primera palabra
    // se toma como nombre de pila y el resto como apellido.
    $partes = preg_split('/\s+/', trim((string)$envio['nombre'])) ?: [];
    $datos = [
        'nombre'            => $partes[0] ?? '',
        'apellido'          => count($partes) > 1 ? implode(' ', array_slice($partes, 1)) : '',
        'empresa'           => (string)($envio['empresa'] ?? ''),
        'cargo'             => campana_cargo_destinatario($envio),
        'email'             => (string)$envio['email'],
        'remitente'         => (string)(($c['remitente_nombre'] ?? null)
                                        ?: ($cuenta['remitente_nombre'] ?? '')
                                        ?: ($c['propietario'] ?? null) ?: 'INTELLIHELP'),
        'remitente_empresa' => (string) setting_get('empresa_nombre', 'INTELLIHELP'),
        'empresa_web'       => (string) setting_get('empresa_web', ''),
        'agenda'            => $agenda,
        'baja'              => $baja,
    ];

    $asunto = render_plantilla((string)(($c['asunto'] ?? null) ?: ($c['plantilla_asunto'] ?? null) ?: ''), $datos);
    // El HTML con los valores escapados; el asunto y el texto plano, tal cual.
    $html   = render_plantilla((string)($c['cuerpo_html'] ?? ''), $datos, true);
    $texto  = render_plantilla((string)($c['cuerpo_texto'] ?? ''), $datos);

    // El pie de baja es obligatorio: si la plantilla no usó {{baja}}, se agrega.
    // En el HTML el enlace quedó escapado (el «?» no cambia, pero se busca la
    // forma escapada por si algún día la dirección lleva «&»).
    if (stripos($html, e($baja)) === false) {
        $html .= '<hr style="border:none;border-top:1px solid #e7e4ef;margin:24px 0 12px">'
               . '<p style="font-family:Arial,sans-serif;font-size:11px;color:#8a8596;line-height:1.5">'
               . 'Recibís este correo porque estás en nuestra base comercial. '
               . '<a href="' . e($baja) . '" style="color:#663399">Darme de baja</a>.</p>';
    }
    if (trim($texto) === '') {
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    }
    if (stripos($texto, $baja) === false) {
        $texto .= "\n\n---\nPara no recibir más correos: " . $baja;
    }

    // Píxel de apertura: es lo que pide la ruta `px` y llena «abierto_en».
    // Va último y sin borde, para que no ensucie el diseño; los clientes que
    // bloquean imágenes no lo cargan y esa apertura no se cuenta, que es lo
    // normal.
    if ($pixel) {
        $html .= '<img src="' . e(url_abs('px?t=' . $envio['token'])) . '" width="1" height="1"'
               . ' alt="" style="display:block;width:1px;height:1px;border:0;">';
    }

    return [
        'para'        => (string)$envio['email'],
        'para_nombre' => (string)($envio['nombre'] ?? ''),
        'asunto'      => $asunto,
        'html'        => $html,
        'texto'       => $texto,
        'de'          => ($c['remitente_email'] ?? null) ?: ($cuenta['remitente'] ?? null),
        'de_nombre'   => ($c['remitente_nombre'] ?? null) ?: ($cuenta['remitente_nombre'] ?? null),
        'responder_a' => ($c['responder_a'] ?? null) ?: ($cuenta['responder_a'] ?? null),
        'cc'          => array_filter(array_map('trim', preg_split('/[;,\s]+/', (string)($c['copia_a'] ?? '')) ?: [])),
        'baja_url'    => $baja,
    ];
}

/**
 * El cargo del destinatario, para {{cargo}}: sale de la ficha del contacto o
 * del posible cliente (la cola no lo guarda). Cadena vacía si no hay ficha
 * o no se puede leer.
 */
function campana_cargo_destinatario(array $envio): string
{
    try {
        if (!empty($envio['contacto_id'])) {
            return trim((string) qv('SELECT cargo FROM contactos WHERE id = ?', [(int)$envio['contacto_id']]));
        }
        if (!empty($envio['posible_cliente_id'])) {
            return trim((string) qv('SELECT cargo FROM posibles_clientes WHERE id = ?', [(int)$envio['posible_cliente_id']]));
        }
    } catch (\Throwable $e) {
        // Sin el dato, la variable sale vacía: no es motivo para no mandar.
    }
    return '';
}

/** Lo que se anota en `envios.motivo` justo antes de entregarle el mensaje al servidor. */
const CAMPANA_MARCA_ENTREGA = 'Entregando al servidor…';

/** Motivo de un envío que pudo haber salido y no se reintenta solo. */
const CAMPANA_MOTIVO_DUDOSO = 'Dudoso: el servidor pudo haberlo recibido. No se reintenta solo para no duplicarlo.';

/**
 * Qué hacer con un envío según lo que devolvió smtp_enviar().
 *
 * Pura, para poder probarla. 'enviado' y 'rechazado' los resuelve el
 * despachador aparte (tienen más efectos); acá van los fallos.
 *
 * @param string $resultado 'fallido' | 'dudoso' | 'error'.
 * @param int    $intentos  intentos contando éste.
 * @param string $err       el motivo que dio smtp_enviar.
 * @return array{estado:string, motivo:string, reintentar_min:?int}
 *               reintentar_min null = no se reintenta.
 */
function campana_resultado_fallo(string $resultado, int $intentos, string $err): array
{
    if ($resultado === 'fallido') {
        return ['estado' => 'error', 'motivo' => recortar('Rechazado por el servidor (no se reintenta): ' . $err, 250),
                'reintentar_min' => null];
    }
    if ($resultado === 'dudoso') {
        return ['estado' => 'error', 'motivo' => recortar(CAMPANA_MOTIVO_DUDOSO . ' ' . $err, 250),
                'reintentar_min' => null];
    }
    if ($intentos >= 20) {
        return ['estado' => 'error', 'motivo' => recortar('Sin éxito tras ' . $intentos . ' intentos: ' . $err, 250),
                'reintentar_min' => null];
    }
    // La espera crece de a 5 minutos, con techo de 60.
    return ['estado' => 'pendiente', 'motivo' => recortar($err, 250), 'reintentar_min' => min(60, $intentos * 5)];
}

/**
 * Despacha correos pendientes. Lo llama el cron.
 *
 * Manda de a varios por corrida, esperando entre uno y otro la pausa aleatoria
 * configurada. Ese rango (25–45 s por defecto) no es capricho: por debajo,
 * Gmail interpreta el ritmo como robótico y empieza a marcar spam; por encima,
 * la campaña se vuelve eterna.
 *
 * La corrida se corta sola al llegar a $segundos, para terminar antes de que el
 * cron vuelva a dispararse. Mandar uno solo por campaña y por corrida, con el
 * cron cada 5 minutos, daría 12 correos por hora en vez de los 80–100 que
 * promete la pantalla: una campaña de 500 destinatarios tardaría 42 horas en
 * vez de 5.
 *
 * Garantías contra el doble envío: la clave única campaña+correo en la cola
 * y el UPDATE condicional que «reclama» cada fila antes de mandarla. Para
 * los cortes a mitad de camino:
 *   · Justo antes de entregar el mensaje (el servidor ya aceptó el DATA) se
 *     anota CAMPANA_MARCA_ENTREGA en el envío. Si el proceso muere o se cae
 *     la base después de eso, el rescate de 15 minutos NO lo vuelve a la
 *     cola: lo deja en 'error' como dudoso, porque pudo haber salido.
 *   · Si se pierde la respuesta 250 del DATA, smtp_enviar devuelve 'dudoso'
 *     y tampoco se reintenta.
 *   · La conexión a la base se renueva (db_reanudar) antes de anotar el
 *     resultado: la charla con el servidor SMTP puede pasar los 20 s de
 *     inactividad que tolera el hosting.
 * Lo que se rescata y vuelve a la cola es sólo lo que se cortó antes de
 * llegar al DATA, que seguro no salió.
 *
 * En modo prueba (es_testing()) sólo se manda a la lista blanca
 * (campana_lista_blanca()); el resto queda 'omitido' con su motivo.
 *
 * El tope diario de la campaña y el cupo de la casilla se miran antes de
 * CADA correo, así que tampoco se pasan cuando varias campañas comparten
 * casilla. Si la campaña eligió una cuenta y está desactivada, la campaña
 * espera: no se cae a otra casilla que nadie eligió.
 *
 * Una excepción corta la corrida y la atrapa quien llama (app/cron.php o
 * campana-accion); las sesiones SMTP abiertas se cierran igual.
 *
 * @param int      $maxPorCorrida se multiplica por 6 para el LIMIT de
 *                                pendientes que se leen por campaña; en la
 *                                práctica el tope real lo pone $segundos.
 * @param int      $segundos     presupuesto de tiempo de esta corrida (0 =
 *                               manda uno y corta, que es lo que usa el
 *                               arranque manual)
 * @param int|null $soloCampana  trabajar sólo sobre esta campaña (el arranque
 *                               manual manda el primer correo en el acto, sin
 *                               tocar las demás campañas que estén corriendo)
 * @return array resumen de lo hecho: enviados, rebotados, errores, omitidos
 *               y los ids de las campañas que se trabajaron.
 * Efectos: escribe envios, envio_eventos, campanas, contactos, cuentas_correo,
 * manda correos, duerme (sleep) entre uno y otro y puede leer la casilla IMAP.
 */
function campanas_despachar(int $maxPorCorrida = 5, int $segundos = 240, ?int $soloCampana = null): array
{
    $res = ['enviados' => 0, 'rebotados' => 0, 'errores' => 0, 'omitidos' => 0, 'campanas' => []];
    $hasta = microtime(true) + max(0, $segundos);

    // Envíos que quedaron a mitad de camino. Se pasan a 'enviando' justo antes
    // de hablar con el servidor, así que si el proceso se corta ahí (tope de
    // tiempo del hosting, socket colgado, reinicio) la fila quedaría trabada en
    // ese estado indefinidamente: nadie la volvería a tomar y, como sólo se cuentan
    // las pendientes, la campaña se daría por completada igual. Quince minutos
    // es más que suficiente para cualquier envío real.
    // Al tomar un envío se le pone `proximo_intento_en = NOW()`, que en ese
    // estado significa «lo estoy trabajando ahora». Si esa marca quedó vieja,
    // es que el proceso se cortó.
    // Los que llegaron a entregarle el mensaje al servidor (CAMPANA_MARCA_ENTREGA)
    // pudieron haber salido: van a 'error' como dudosos, no a la cola.
    $vencido = "(proximo_intento_en IS NULL OR proximo_intento_en < DATE_SUB(NOW(), INTERVAL 15 MINUTE))";
    $dudosos = q("UPDATE envios SET estado = 'error', motivo = ?
                  WHERE estado = 'enviando' AND motivo = ? AND $vencido",
                 [CAMPANA_MOTIVO_DUDOSO, CAMPANA_MARCA_ENTREGA])->rowCount();
    $rescatados = q("UPDATE envios SET estado = 'pendiente'
                     WHERE estado = 'enviando' AND (motivo IS NULL OR motivo <> ?) AND $vencido",
                    [CAMPANA_MARCA_ENTREGA])->rowCount();
    if ($rescatados > 0) {
        log_app('campana.rescate', ['tipo' => 'sistema', 'actor' => 'cron',
            'detalle' => $rescatados . ' envío(s) trabados volvieron a la cola']);
    }
    if ($dudosos > 0) {
        log_app('campana.rescate', ['tipo' => 'error', 'actor' => 'cron',
            'detalle' => $dudosos . ' envío(s) se cortaron después de entregarle el mensaje al servidor: '
                       . 'quedan como dudosos y no se reintentan solos']);
    }

    // Modo prueba: la lista blanca se lee una vez por corrida.
    $testing = function_exists('es_testing') && es_testing();
    $lista   = $testing ? campana_lista_blanca() : null;

    // El id se concatena ya convertido a entero, así que no hay inyección.
    $filtro = $soloCampana ? ' AND id = ' . (int)$soloCampana : '';
    $campanas = q("SELECT * FROM campanas
                   WHERE eliminado_en IS NULL AND estado IN ('enviando','programada') $filtro
                   ORDER BY inicio_en IS NULL, inicio_en")->fetchAll();

    // Una sesión SMTP abierta POR CUENTA: varias campañas que comparten la
    // misma casilla reusan la conexión.
    // Las campañas se procesan una detrás de otra dentro de la misma vuelta:
    // el presupuesto ($hasta) es uno solo para todas, así que la primera
    // campaña se lo gasta y las siguientes mandan uno cada una (el primero de
    // cada campaña sale sin mirar el presupuesto) y cortan.
    $sesiones = [];
    try {
        foreach ($campanas as $c) {
            $cid = (int)$c['id'];

            // Programada que todavía no llegó su hora: no se toca.
            // Una programada sin fecha arranca en la primera corrida.
            if ($c['estado'] === 'programada') {
                if (!empty($c['programada_para']) && strtotime((string)$c['programada_para']) > time()) {
                    continue;
                }
                campana_estado($cid, 'enviando');
                $c['estado'] = 'enviando';
            }

            if (!campana_en_horario($c))                                { continue; }
            if (campana_enviados_hoy($cid) >= (int)$c['limite_diario']) { continue; }

            // Casilla desde la que sale esta campaña. Si eligió una y está
            // desactivada (o ya no existe), la campaña espera: caer a otra
            // casilla mandaría desde un remitente que nadie eligió.
            if (!empty($c['cuenta_correo_id'])) {
                $cuenta = cuenta_correo_get((int)$c['cuenta_correo_id']);
                if (!$cuenta || (int)$cuenta['activo'] !== 1) { continue; }
            } else {
                $cuenta = cuenta_correo_predeterminada();
                if ($cuenta && (int)$cuenta['activo'] !== 1) { $cuenta = null; }
            }
            if ($cuenta && !cuenta_tiene_cupo($cuenta)) {
                // La casilla llegó a su tope diario: la campaña espera a mañana en
                // vez de forzar un envío que arruinaría la reputación.
                continue;
            }

            $pendientes = q("SELECT * FROM envios
                             WHERE campana_id = ? AND estado = 'pendiente'
                               AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW())
                             ORDER BY id LIMIT ?", [$cid, max($maxPorCorrida, 1) * 6])->fetchAll();

            if (!$pendientes) {
                // Se cuentan también las que están 'enviando': si hay una a mitad
                // de camino la campaña NO terminó, y darla por completada dejaba a
                // esa persona sin recibir nada, sin que nadie se enterara.
                $quedan = (int) qv("SELECT COUNT(*) FROM envios
                                    WHERE campana_id = ? AND estado IN ('pendiente','enviando')", [$cid]);
                if ($quedan === 0) {
                    campana_recontar($cid);
                    campana_estado($cid, 'completada');
                }
                continue;
            }

            // Datos completos de la campaña (plantilla incluida) una sola vez.
            $completa = campana_get($cid);
            if (!$completa) { continue; }

            foreach ($pendientes as $envio) {
                // Los topes se miran antes de cada correo, no una vez por
                // vuelta: si no, una vuelta se pasaba por uno o dos, y varias
                // campañas con la misma casilla lo pasaban juntas.
                if (campana_enviados_hoy($cid) >= (int)$c['limite_diario']) { break; }
                if ($cuenta && !cuenta_tiene_cupo($cuenta))                { break; }

                // Chequeo de última hora: entre que se armó la cola y ahora, la
                // persona pudo haberse dado de baja o el contacto pudo ir a la
                // papelera. Y en modo prueba, sólo la lista blanca.
                $omitir = null;
                if (qv('SELECT 1 FROM desuscripciones WHERE email = ?', [$envio['email']])) {
                    $omitir = 'Se dio de baja antes del envío';
                } elseif (!campana_destino_permitido((string)$envio['email'], $testing, $lista)) {
                    $omitir = CAMPANA_MOTIVO_TESTING;
                } elseif (!empty($envio['contacto_id'])
                          && qv('SELECT 1 FROM contactos WHERE id = ? AND eliminado_en IS NOT NULL', [(int)$envio['contacto_id']])) {
                    $omitir = 'Está en la papelera';
                }
                if ($omitir !== null) {
                    q("UPDATE envios SET estado = 'omitido', motivo = ? WHERE id = ? AND estado = 'pendiente'",
                      [$omitir, (int)$envio['id']]);
                    $res['omitidos']++;
                    continue;
                }

                $clave = $cuenta ? 'c' . (int)$cuenta['id'] : 'ajustes';
                $cfg   = $cuenta ? cuenta_a_config($cuenta) : null;
                if (!isset($sesiones[$clave])) {
                    $err = null;
                    $fp  = smtp_abrir($err, $cfg);
                    if (!$fp) {
                        log_app('campana.smtp_error', ['tipo' => 'error', 'entidad' => 'campana', 'entidad_id' => $cid,
                            'detalle' => ($cuenta ? 'Cuenta «' . $cuenta['nombre'] . '»: ' : '') . (string)$err]);
                        if ($cuenta) {
                            q('UPDATE cuentas_correo SET ultimo_error = ? WHERE id = ?',
                              [recortar($err, 250), (int)$cuenta['id']]);
                        }
                        continue 2;   // esta campaña no puede avanzar; las demás sí
                    }
                    $sesiones[$clave] = $fp;
                }
                $fp = $sesiones[$clave];

                // Se toma la fila con una condición sobre su estado: si otra
                // corrida se adelantó, este UPDATE afecta cero filas y el correo no
                // se manda dos veces. El bloqueo del cron por sí solo no alcanza,
                // porque la conexión se reabre después de cada pausa y con ella se
                // suelta el bloqueo.
                $tomada = q("UPDATE envios SET estado = 'enviando', intentos = intentos + 1,
                                    cuenta_correo_id = ?, proximo_intento_en = NOW()
                             WHERE id = ? AND estado = 'pendiente'",
                            [$cuenta ? (int)$cuenta['id'] : null, (int)$envio['id']])->rowCount();
                if ($tomada !== 1) { continue; }   // ya la agarró otra corrida

                $msg = campana_armar_mensaje($completa, $envio, $cuenta);
                $msg['message_id'] = nuevo_message_id();
                $err = null;
                $envioId = (int)$envio['id'];
                // Justo antes de entregarle el mensaje al servidor queda anotado:
                // desde acá el correo puede salir aunque después todo se corte.
                $alEntregar = static function () use ($envioId): void {
                    if (function_exists('db_reanudar')) { db_reanudar(); }
                    q('UPDATE envios SET motivo = ? WHERE id = ?', [CAMPANA_MARCA_ENTREGA, $envioId]);
                };
                $resultado = smtp_enviar($fp, $msg, $err, $cfg, $alEntregar);

                // La charla con el servidor SMTP pudo tardar más que los 20 s
                // que el hosting deja ociosa la conexión a la base: se renueva
                // antes de anotar el resultado, o el 'enviado' se perdía y el
                // rescate lo volvía a mandar.
                if (function_exists('db_reanudar')) { db_reanudar(); }

                if ($resultado === 'enviado') {
                    q("UPDATE envios SET estado = 'enviado', enviado_en = NOW(), message_id = ?, motivo = NULL WHERE id = ?",
                      [$msg['message_id'], $envioId]);
                    q("INSERT INTO envio_eventos (envio_id, tipo) VALUES (?, 'enviado')", [$envioId]);
                    if (!empty($envio['contacto_id'])) {
                        q('UPDATE contactos SET ultima_actividad_en = NOW() WHERE id = ?', [(int)$envio['contacto_id']]);
                    }
                    $res['enviados']++;
                } elseif ($resultado === 'rechazado') {
                    q("UPDATE envios SET estado = 'rechazado', motivo = ?, rebotado_en = NOW() WHERE id = ?",
                      [recortar($err, 250), $envioId]);
                    q("INSERT INTO envio_eventos (envio_id, tipo, detalle) VALUES (?, 'rebote', ?)",
                      [$envioId, recortar($err, 400)]);
                    // Un 5xx en el RCPT es el servidor diciendo «esa casilla no existe»:
                    // es definitivo, así que el contacto se archiva.
                    marcar_rebote((string)$envio['email'],
                                  'El servidor rechazó la dirección: ' . recortar($err, 150), true);
                    $res['rebotados']++;
                } else {
                    // 'fallido' (5xx fuera del RCPT) y 'dudoso' no se reintentan;
                    // 'error' (temporal) sí, con espera creciente y tope de 20.
                    $fallo = campana_resultado_fallo((string)$resultado, (int)$envio['intentos'] + 1, (string)$err);
                    if ($fallo['reintentar_min'] === null) {
                        q('UPDATE envios SET estado = ?, motivo = ? WHERE id = ?',
                          [$fallo['estado'], $fallo['motivo'], $envioId]);
                        $res['errores']++;
                    } else {
                        q("UPDATE envios SET estado = 'pendiente', motivo = ?, proximo_intento_en = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?",
                          [$fallo['motivo'], $fallo['reintentar_min'], $envioId]);
                    }
                    // La sesión SMTP puede haber quedado a mitad de camino: se
                    // reabre en la próxima vuelta.
                    smtp_cerrar($fp);
                    unset($sesiones[$clave]);
                }

                // El avance se actualiza acá, después de cada correo, y no al
                // terminar la campaña. Una corrida puede durar varios minutos: en
                // todo ese rato la pantalla mostraba «0 de 6» mientras los correos
                // ya estaban saliendo, y parecía que el envío no había arrancado.
                campana_recontar($cid);

                // Pausa aleatoria antes del siguiente de ESTA campaña. Es lo que
                // hace que el ritmo parezca humano y no una máquina.
                $pausa = random_int((int)$c['pausa_min_seg'], (int)$c['pausa_max_seg']);

                // Si no entra otra pausa dentro del presupuesto de la corrida, se
                // deja anotado para cuándo puede salir el siguiente y se corta acá:
                // sigue la próxima corrida del cron, sin perder el ritmo.
                if (microtime(true) + $pausa > $hasta) {
                    // Sólo se adelantan o atrasan las que ya podían salir antes
                    // de esa pausa: las que esperan su reintento (5, 10… 60 min)
                    // conservan su espera.
                    q("UPDATE envios SET proximo_intento_en = DATE_ADD(NOW(), INTERVAL ? SECOND)
                       WHERE campana_id = ? AND estado = 'pendiente'
                         AND (proximo_intento_en IS NULL OR proximo_intento_en < DATE_ADD(NOW(), INTERVAL ? SECOND))",
                      [$pausa, $cid, $pausa]);
                    break;
                }
                sleep($pausa);

                // Este hosting cierra la conexión a los 20 segundos de inactividad
                // y la pausa mínima es de 25: después de dormir, la conexión SIEMPRE
                // está muerta. Sin esto, la campaña mandaría el primer correo y todo
                // lo que viene después fallaría en silencio —el contador, la marca
                // del cron, hasta el registro del error—, con el avance clavado en
                // cero.
                if (function_exists('db_reanudar')) { db_reanudar(); }

                // Se mira la casilla en el medio del envío, no al final.
                //
                // Los rebotes vuelven a los pocos segundos de cada correo y las
                // respuestas empiezan a entrar enseguida. Leyendo sólo al terminar,
                // la campaña mostraría todo en cero mientras las cosas están
                // pasando, y después aparecería todo junto. Acá se aprovecha que
                // ya estamos en una corrida larga: cada minuto se pasa a mirar.
                if (function_exists('imap_quizas_revisar')) {
                    imap_quizas_revisar(60);
                }
            }

            campana_recontar($cid);

            // Si no quedó nada por mandar, la campaña se cierra ACÁ mismo, sin
            // esperar a la corrida siguiente del cron: con la cola vacía y el
            // avance al 100 % no puede seguir figurando como «Enviando».
            $faltan = (int) qv("SELECT COUNT(*) FROM envios
                                WHERE campana_id = ? AND estado IN ('pendiente','enviando')", [$cid]);
            if ($faltan === 0) {
                campana_estado($cid, 'completada');
            }

            $res['campanas'][] = $cid;
        }
    } finally {
        foreach ($sesiones as $fp) { smtp_cerrar($fp); }
    }
    return $res;
}

// ------------------------------------------------------------
//  Bajas y rebotes
// ------------------------------------------------------------

/**
 * Vuelve a habilitar a alguien que se había dado de baja.
 *
 * Existe porque la pantalla de baja le promete al destinatario «si fue un
 * error, escribinos y te damos de alta otra vez», y no había ninguna forma de
 * hacerlo: la fila de `desuscripciones` no se borraba desde ningún lado, así
 * que destildar «No incluir en campañas» en la ficha no servía de nada y la
 * persona seguía saliendo omitida en cada campaña.
 *
 * Queda registrado quién la reactivó: es una decisión que alguien tiene que
 * poder justificar.
 *
 * Además de borrar la baja, destilda «no incluir en campañas» en todos los
 * contactos y posibles clientes con ese correo principal. No toca la marca de
 * rebote (`email_rebotado`) ni restaura contactos archivados por rebote.
 *
 * @return bool true si había una baja registrada para ese correo.
 */
function reactivar_suscripcion(string $email): bool
{
    $email = normalizar_email($email);
    if ($email === '') { return false; }
    $n = q('DELETE FROM desuscripciones WHERE email = ?', [$email])->rowCount();
    q('UPDATE contactos SET no_email = 0 WHERE email = ?', [$email]);
    q('UPDATE posibles_clientes SET no_email = 0 WHERE email = ?', [$email]);
    log_app('campana.alta_manual', ['tipo' => 'accion',
        'detalle' => 'Se volvió a habilitar ' . $email . ' para recibir campañas']);
    return $n > 0;
}

/**
 * Da de baja un correo de todas las campañas presentes y futuras.
 *
 * Es la lista negra global: la fila en `desuscripciones` manda sobre todo lo
 * demás. Además marca «no incluir en campañas» en contactos y posibles
 * clientes con ese correo principal y saca de la cola los envíos pendientes
 * de cualquier campaña.
 *
 * Si el correo ya estaba dado de baja, se actualizan motivo y detalle pero
 * no la campaña ni la IP originales, y el contador `campanas.desuscriptos`
 * NO se vuelve a sumar: repetir la baja (el «un clic» de Gmail más el botón,
 * o dos clics) no la cuenta dos veces.
 *
 * La IP de quien pidió la baja se guarda como hash (ip_hash), nunca en
 * claro, y en un paso aparte que puede fallar sin afectar la baja.
 *
 * @param string   $email     se normaliza acá.
 * @param string   $motivo    'baja' (la pidió la persona) o 'rebote_duro'.
 * @param int|null $campanaId campaña desde la que llegó la baja, si la hay.
 * @param string|null $detalle texto libre para el registro.
 * @return bool true si es una baja nueva; false si ya estaba dado de baja.
 */
function desuscribir(string $email, string $motivo = 'baja', ?int $campanaId = null, ?string $detalle = null): bool
{
    $email = normalizar_email($email);
    $ya    = (bool) qv('SELECT 1 FROM desuscripciones WHERE email = ?', [$email]);
    if (!$ya) {
        try {
            q('INSERT INTO desuscripciones (email, motivo, campana_id, detalle) VALUES (?, ?, ?, ?)',
              [$email, $motivo, $campanaId, $detalle]);
        } catch (\PDOException $e) {
            // Dos pedidos a la vez (el «un clic» de Gmail y el botón): el otro
            // ganó la carrera. La baja ya está; se sigue como repetida.
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
            $ya = true;
        }
    }
    if ($ya) {
        q('UPDATE desuscripciones SET motivo = ?, detalle = ? WHERE email = ?', [$motivo, $detalle, $email]);
    } else {
        // La IP va aparte: si la columna todavía es la vieja (sin la
        // migración 015) o falla por cualquier motivo, la baja ya quedó.
        try {
            q('UPDATE desuscripciones SET ip = ? WHERE email = ?', [campana_ip_registro(), $email]);
        } catch (\Throwable $e) { }
    }
    q('UPDATE contactos SET no_email = 1 WHERE email = ?', [$email]);
    q('UPDATE posibles_clientes SET no_email = 1 WHERE email = ?', [$email]);
    q("UPDATE envios SET estado = 'omitido', motivo = 'Se dio de baja'
       WHERE email = ? AND estado = 'pendiente'", [$email]);
    if ($campanaId && !$ya) {
        q('UPDATE campanas SET desuscriptos = desuscriptos + 1 WHERE id = ?', [$campanaId]);
    }
    log_app('campana.baja', ['tipo' => 'sistema', 'actor' => $email,
        'detalle' => ($ya ? 'Baja repetida · ' : 'Baja de las campañas · ') . $motivo . ($detalle ? ' · ' . $detalle : '')]);
    return !$ya;
}

/**
 * La IP del visitante (baja, píxel) tal como se guarda: el hash con el
 * secreto de la instalación, nunca en claro (Ley 25.326). Null sin IP.
 */
function campana_ip_registro(): ?string
{
    return function_exists('ip_hash') ? ip_hash(ip_confiable()) : null;
}

/**
 * El destinatario de un enlace de baja, a partir del token.
 *
 * Primero en la cola (`envios`); si no está —la campaña se purgó de la
 * papelera y con ella su cola—, en `bajas_tokens`, donde purgar_papelera()
 * guarda los tokens antes de borrar (migración 015). Así el enlace de baja
 * de un correo ya entregado funciona siempre.
 *
 * @param string $token 32 hexadecimales (ya filtrado por quien llama).
 * @return array|null id (del envío; null si viene de bajas_tokens), email,
 *                    campana_id y campana (el nombre, si existe).
 */
function baja_buscar_token(string $token): ?array
{
    if (strlen($token) !== 32) { return null; }
    $envio = q1('SELECT e.id, e.email, e.campana_id, c.nombre AS campana FROM envios e
                 LEFT JOIN campanas c ON c.id = e.campana_id WHERE e.token = ?', [$token]);
    if ($envio) { return $envio; }
    try {
        $t = q1('SELECT email, campana_id FROM bajas_tokens WHERE token = ?', [$token]);
    } catch (\Throwable $e) {
        // Sin la migración 015 la tabla no existe: el token no se encontró.
        return null;
    }
    return $t ? ['id' => null, 'email' => $t['email'], 'campana_id' => $t['campana_id'], 'campana' => null] : null;
}

/**
 * Marca un correo como rebotado en todo el CRM.
 *
 * Un rebote DURO (la casilla no existe, el dominio no existe) además archiva
 * el contacto: no tiene sentido dejar en la base a alguien al que no se le
 * puede escribir, y hacerlo a mano con miles de contactos no escala.
 *
 * «Archivar» acá significa mandarlo a la papelera, no borrarlo: sigue estando
 * y se puede restaurar. La eliminación definitiva la hace el cron recién a los
 * 60 días (ver `purgar_papelera()`), por si el archivado fue un error.
 *
 * En la cola se marca como rebotado sólo el envío más reciente a ese correo
 * (el que salió en los últimos 7 días): el aviso de rebote llega a los pocos
 * minutos de cada correo y corresponde a ése, no a los de campañas viejas que
 * sí se entregaron. Un rebote duro, además, da de baja el correo
 * (desuscribir con motivo 'rebote_duro').
 *
 * @param string $email   se normaliza acá.
 * @param string $detalle el motivo que dio el servidor, para el registro.
 * @param bool   $duro    true si el rebote es definitivo.
 */
function marcar_rebote(string $email, string $detalle = '', bool $duro = false): void
{
    $email = normalizar_email($email);
    q('UPDATE contactos SET email_rebotado = 1 WHERE email = ?', [$email]);
    q('UPDATE posibles_clientes SET email_rebotado = 1 WHERE email = ?', [$email]);
    $ultimo = qv("SELECT id FROM envios
                  WHERE email = ? AND estado IN ('enviado','enviando')
                    AND (enviado_en IS NULL OR enviado_en >= NOW() - INTERVAL 7 DAY)
                  ORDER BY COALESCE(enviado_en, creado_en) DESC, id DESC LIMIT 1", [$email]);
    if ($ultimo) {
        q("UPDATE envios SET estado = 'rebotado', rebotado_en = NOW(), motivo = ? WHERE id = ?",
          [recortar($detalle, 250), (int)$ultimo]);
    }

    $archivados = 0;
    if ($duro) {
        // Sólo se archiva si el correo rebotado es el ÚNICO del contacto: si
        // tiene otra dirección válida, se sigue pudiendo trabajar con él.
        foreach (['contactos' => 'contacto', 'posibles_clientes' => 'posible_cliente'] as $tabla => $entidad) {
            $cols = $tabla === 'contactos' ? ['email', 'email2', 'email3'] : ['email', 'email2'];
            $otros = implode(' OR ', array_map(
                fn($c) => "($c IS NOT NULL AND $c <> '' AND $c <> ?)", array_slice($cols, 1)));
            $params = array_merge([$email], array_fill(0, count($cols) - 1, $email));
            $filas = q("SELECT id FROM $tabla
                        WHERE email = ? AND eliminado_en IS NULL AND NOT ($otros)", $params)->fetchAll();
            foreach ($filas as $r) {
                q("UPDATE $tabla SET eliminado_en = NOW() WHERE id = ?", [(int)$r['id']]);
                log_app($entidad . '.archivar_rebote', ['tipo' => 'sistema', 'actor' => 'sistema',
                    'entidad' => $entidad, 'entidad_id' => (int)$r['id'],
                    'detalle' => 'Archivado automáticamente: el correo ' . $email
                               . ' rebotó de forma definitiva. Se puede recuperar desde la papelera '
                               . 'durante ' . dias_papelera() . ' días.']);
                $archivados++;
            }
        }
        desuscribir($email, 'rebote_duro', null, recortar($detalle, 200));
    }

    log_app('campana.rebote', ['tipo' => 'sistema', 'actor' => $email,
        'detalle' => ($duro ? 'Rebote definitivo' : 'Rebote temporal')
                   . ($detalle ? ': ' . recortar($detalle, 200) : '')
                   . ($archivados ? ' · ' . $archivados . ' contacto(s) archivado(s)' : '')]);
}

/**
 * Cuántos días se conserva lo archivado antes de borrarlo definitivamente.
 * Un valor vacío, 0 o ilegible vale como el defecto (60), y nunca menos de 7:
 * antes un ajuste guardado vacío daba 1 día y la purga vaciaba la papelera.
 */
function dias_papelera(): int
{
    $n = (int) setting_get('papelera_dias', '60');
    return $n <= 0 ? 60 : max(7, $n);
}

/**
 * Borra definitivamente lo que lleva más de N días en la papelera.
 *
 * Es el único lugar del sistema que borra físicamente. Hasta que se cumple
 * el plazo, todo sigue recuperable desde la papelera.
 *
 * Campañas: `envios` tiene ON DELETE CASCADE sobre `campanas`, así que
 * purgar una campaña borra su cola y con ella los tokens de baja. Antes de
 * borrar, los tokens se copian a `bajas_tokens` (migración 015), que
 * baja_buscar_token() consulta cuando el token ya no está en la cola: el
 * enlace de baja de un correo entregado tiene que funcionar siempre. Si la
 * copia falla (por ejemplo, sin la migración), las campañas NO se purgan y
 * siguen en la papelera.
 *
 * Lo llama el cron. Las tablas salen de tablas_papelera().
 *
 * @return array<string,int> cuántos se borraron de cada módulo
 */
function purgar_papelera(?int $dias = null): array
{
    $dias = $dias ?? dias_papelera();
    $out  = [];
    foreach (tablas_papelera() as $entidad => $def) {
        // Una empresa con contactos vivos NO se borra. La clave foránea deja
        // `cuenta_id` en NULL, así que borrarla desengancharía en silencio a
        // contactos que nadie eliminó, y sin forma de saber a qué empresa
        // pertenecían. Se queda en la papelera hasta que se resuelva a mano.
        $protegidos = '';
        if ($def['tabla'] === 'cuentas') {
            $protegidos = ' AND id NOT IN (SELECT DISTINCT cuenta_id FROM contactos
                                           WHERE cuenta_id IS NOT NULL AND eliminado_en IS NULL)';
        }
        if ($def['tabla'] === 'campanas') {
            try {
                q("INSERT IGNORE INTO bajas_tokens (token, email, campana_id)
                   SELECT e.token, e.email, e.campana_id FROM envios e
                   JOIN campanas c ON c.id = e.campana_id
                   WHERE c.eliminado_en IS NOT NULL
                     AND c.eliminado_en < NOW() - INTERVAL ? DAY", [$dias]);
            } catch (\Throwable $e) {
                // Sin donde guardar los tokens, purgar dejaría enlaces de baja
                // rotos: las campañas esperan en la papelera.
                log_app('papelera.purga', ['tipo' => 'error', 'actor' => 'cron', 'entidad' => $entidad,
                    'detalle' => 'Las campañas no se purgaron: no se pudieron guardar sus tokens de baja '
                               . '(¿falta la migración 015?)']);
                continue;
            }
        }
        $st = q("DELETE FROM {$def['tabla']}
                 WHERE eliminado_en IS NOT NULL
                   AND eliminado_en < NOW() - INTERVAL ? DAY" . $protegidos, [$dias]);
        $n = $st->rowCount();
        if ($n > 0) {
            $out[$entidad] = $n;
            log_app('papelera.purga', ['tipo' => 'sistema', 'actor' => 'cron',
                'entidad' => $entidad,
                'detalle' => $n . ' ' . $def['etiqueta'] . '(s) eliminados definitivamente '
                           . 'tras ' . $dias . ' días en la papelera']);
        }
    }
    return $out;
}

/**
 * Marca que un destinatario respondió (lo llama el lector IMAP).
 *
 * Se atribuye al envío entregado más reciente a ese correo que todavía no
 * tenía respuesta, sin mirar el In-Reply-To: cualquier correo de esa persona
 * cuenta como respuesta a la última campaña que recibió. Avisa al
 * responsable de la campaña con una notificación que lleva a la conversación.
 *
 * @param string $email  remitente de la respuesta (se normaliza).
 * @param string $asunto asunto de la respuesta, para el registro y el aviso.
 */
function marcar_respuesta(string $email, string $asunto = ''): void
{
    $email = normalizar_email($email);
    $envio = q1("SELECT id, campana_id FROM envios
                 WHERE email = ? AND estado = 'enviado' AND respondido_en IS NULL
                 ORDER BY enviado_en DESC LIMIT 1", [$email]);
    if (!$envio) { return; }
    q('UPDATE envios SET respondido_en = NOW() WHERE id = ?', [(int)$envio['id']]);
    q("INSERT INTO envio_eventos (envio_id, tipo, detalle) VALUES (?, 'respuesta', ?)",
      [(int)$envio['id'], recortar($asunto, 400)]);
    campana_recontar((int)$envio['campana_id']);

    // Una respuesta es lo más valioso que produce una campaña: se avisa para
    // que no quede enterrada en la ficha, esperando que alguien la vaya a mirar.
    $camp = q1('SELECT nombre, propietario_id FROM campanas WHERE id = ?', [(int)$envio['campana_id']]);
    $quien = q1('SELECT id, nombre_completo FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);
    notificar('respuesta',
        'Respondió ' . ($quien['nombre_completo'] ?? $email),
        ['usuario_id' => $camp['propietario_id'] ?? null,
         'detalle'    => 'Campaña «' . ($camp['nombre'] ?? '') . '»'
                       . ($asunto !== '' ? ' · ' . recortar($asunto, 120) : ''),
         'entidad'    => 'campana', 'entidad_id' => (int)$envio['campana_id'],
         // A la conversación, no a la ficha: lo que uno quiere al enterarse de
         // una respuesta es LEERLA y contestarla, no ver los datos de contacto.
         'url'        => 'conversacion?email=' . urlencode($email)]);

    log_app('campana.respuesta', ['tipo' => 'sistema', 'entidad' => 'campana',
        'entidad_id' => (int)$envio['campana_id'], 'actor' => $email,
        'detalle' => 'Respondió' . ($asunto !== '' ? ': ' . recortar($asunto, 120) : '')]);
}
