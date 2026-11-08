<?php
declare(strict_types=1);

/**
 * Conexión PDO única (singleton) a MySQL/MariaDB.
 *
 * @param bool $reabrir descarta la conexión actual y arma una nueva
 */
function db(bool $reabrir = false): PDO
{
    static $pdo = null;
    if ($reabrir) { $pdo = null; }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = $GLOBALS['CONFIG']['db'];
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=" . ($c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // La base tiene que hablar la MISMA hora que PHP. El servidor corre en UTC,
    // así que sin esto `NOW()` daría tres horas más que `date()`: los
    // recordatorios de reuniones saldrían a destiempo y la agenda pública
    // ofrecería horarios que ya pasaron.
    //
    // El desfase se calcula desde la zona configurada en PHP en vez de dejarlo
    // escrito a mano: si algún día se cambia la zona de la aplicación, la base
    // la sigue sola. No se usa el nombre de la zona («America/Argentina/...»)
    // porque este hosting no da permiso para leer la tabla de zonas de MySQL.
    $desfase = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = '" . $desfase . "'");
    return $pdo;
}

/**
 * Reabre la conexión si el servidor la cerró por inactividad.
 *
 * Este hosting tiene `wait_timeout = 20 segundos`, y el despachador de campañas
 * espera entre 25 y 45 segundos entre correo y correo para que el ritmo no
 * parezca de máquina. O sea: después de cada pausa la conexión está muerta, sin
 * excepción. Sin reabrirla, la campaña manda el primer correo y después falla
 * todo lo demás —el contador, la marca del cron y hasta el registro del
 * error—, porque todo usa la misma conexión.
 *
 * Hay que llamarla después de cualquier espera larga.
 */
function db_reanudar(): void
{
    try {
        db()->query('SELECT 1');
        return;                       // sigue viva, no hay nada que hacer
    } catch (\Throwable $e) {
        // Se cayó por inactividad: se abre de nuevo.
    }
    db(true);
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Primera fila de una consulta, o null. */
function q1(string $sql, array $params = []): ?array
{
    $fila = q($sql, $params)->fetch();
    return $fila === false ? null : $fila;
}

/** Primer valor de la primera fila. */
function qv(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

/** Id del último INSERT. */
function last_id(): int
{
    return (int) db()->lastInsertId();
}

// ---- Ajustes de la aplicación (tabla app_settings) ----

/**
 * Lee un ajuste.
 *
 * Los ajustes se cargan una sola vez por proceso: son pocos y se consultan
 * muchas veces. El caché se invalida al escribir, y también a mano con
 * `setting_refrescar()` para los procesos largos —el cron corre varios
 * minutos, y sin eso seguiría leyendo los valores que había al arrancar.
 */
function setting_get(string $clave, ?string $def = null): ?string
{
    static $cache = null;
    if ($GLOBALS['__settings_sucio'] ?? false) {
        $cache = null;
        unset($GLOBALS['__settings_sucio']);
    }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q('SELECT clave, valor FROM app_settings')->fetchAll() as $r) {
                $cache[$r['clave']] = $r['valor'];
            }
        } catch (\Throwable $e) {
            // Si la tabla no existe todavía, degradamos a los valores por defecto.
        }
    }
    return $cache[$clave] ?? $def;
}

function setting_set(string $clave, ?string $valor): void
{
    q('INSERT INTO app_settings (clave, valor) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, $valor]);
    // Sin esto, guardar un ajuste y volver a leerlo en la misma request
    // devolvía el valor viejo: el caché no se enteraba de la escritura.
    setting_refrescar();
}

/**
 * Obliga a releer los ajustes de la base en la próxima consulta.
 *
 * Hace falta en los procesos largos. El cron corre varios minutos y, sin esto,
 * arrastra los valores que había al arrancar: si alguien pasa el sistema a
 * producción o cambia el tope diario mientras hay una campaña saliendo, la
 * corrida en curso lo ignora hasta que termina.
 */
function setting_refrescar(): void
{
    $GLOBALS['__settings_sucio'] = true;
}

/** ¿La app está en modo testing? (bloquea envíos reales de campaña). */
function es_testing(): bool
{
    return setting_get('app_entorno', 'testing') === 'testing';
}
