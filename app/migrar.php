<?php
declare(strict_types=1);

// ============================================================
//  Aplicar una migración de la base.
//
//  Este script arranca con el bootstrap de SU instalación —el de crm/ o el de
//  crm-pruebas/—, así que usa la configuración y la base que le corresponden,
//  sin pedir credenciales a nadie. Lo llama `deploy.py migrar`.
//
//  No se aplica con `deploy.py schema`: ese camino necesita la contraseña de
//  la base y sólo acepta una base vacía (instalación nueva). Este, en
//  cambio, usa la conexión que ya tiene la instalación.
//
//  Uso:
//      php migrar.php <archivo.sql>              aplica
//      php migrar.php <archivo.sql> --simular    sólo lista las sentencias
//
//  Imprime cuántas sentencias corrió y cuáles fallaron. Nunca la configuración.
//  Si todo salió bien deja la marca `migracion_<nnn>` en los ajustes.
//
//  Las migraciones se escriben idempotentes (IF NOT EXISTS, ON DUPLICATE KEY):
//  correr la misma dos veces no rompe nada.
//
//  Lo que NO hace, a propósito o no:
//    · No usa transacción: cada sentencia se aplica sola y, si una falla, las
//      anteriores quedan aplicadas y las siguientes se intentan igual. En
//      MariaDB los CREATE/ALTER confirman solos, así que una transacción no
//      serviría de todos modos. Por eso la idempotencia es obligatoria: el
//      arreglo es corregir y volver a correr la migración entera.
//    · No consulta la marca `migracion_<nnn>` antes de aplicar: si ya se
//      aplicó, se vuelve a aplicar (y la idempotencia lo hace inofensivo).
//    · No entiende DELIMITER: una migración con procedimientos o triggers
//      (que llevan «;» adentro del cuerpo) se cortaría mal.
//    · El «prueba/producción» que imprime sale del ajuste `app_entorno` de la
//      base conectada (sin la fila, «producción»); es informativo, no frena
//      nada.
//
//  Ubicación: en el repositorio vive en app/ y el núcleo en app/web/app/; en
//  el servidor, deploy.py lo deja en la raíz de la instalación, junto a app/.
//  `$raiz` prueba las dos.
// ============================================================

// Por HTTP no existe: el archivo queda dentro del webroot y cualquiera podría
// pedirlo, así que se contesta 404 antes de cargar nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Esto se ejecuta por línea de comandos.\n");
}

// Las pruebas (test/casos/e2.php) incluyen este archivo sólo para usar
// migrar_sentencias(): las funciones de abajo se declaran igual (PHP las
// registra al compilar el archivo) y acá se corta antes de conectar a nada.
if (defined('MIGRAR_SOLO_FUNCIONES')) { return; }

$raiz = is_file(__DIR__ . '/web/app/bootstrap.php') ? __DIR__ . '/web' : __DIR__;
require $raiz . '/app/bootstrap.php';

// El archivo es el primer argumento que no empieza con «--»; el resto se
// ignora. La ruta puede ser cualquiera legible: deploy.py lo sube a
// /home/<usuario>/crm_migracion/ con su nombre original.
$args    = array_slice($argv, 1);
$simular = in_array('--simular', $args, true);
$archivo = null;
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) { $archivo = $a; break; }
}
if ($archivo === null || !is_file($archivo) || !is_readable($archivo)) {
    fwrite(STDERR, "Uso: php migrar.php <archivo.sql> [--simular]\n");
    exit(2);
}

/**
 * Parte un archivo SQL en sentencias.
 *
 * No alcanza con cortar en cada «;»: los comentarios de las migraciones están
 * escritos en castellano y llevan punto y coma, comillas y apóstrofos, y los
 * textos entre comillas también pueden tenerlos. Se recorre carácter por
 * carácter sabiendo si se está adentro de un texto o de un comentario.
 *
 * Reglas que sigue, las mismas del cliente mysql:
 *  · «--» es comentario sólo si lo sigue un espacio o el fin del texto
 *    («--x» no lo es); «#» es comentario siempre, fuera de un texto.
 *  · Los comentarios se descartan (se reemplazan por un salto o un espacio
 *    para no pegar palabras), así que no llegan a la base.
 *  · Adentro de '...' y "..." se respeta la barra invertida como escape;
 *    adentro de `...` no. La comilla duplicada ('') cuenta como una literal.
 *  · Una sentencia final sin «;» también se devuelve.
 *  · Los comentarios ejecutables de MySQL («/*!» … ) se descartan como
 *    cualquier comentario de bloque: no se usan en las migraciones.
 *
 * @param string $sql contenido completo del archivo .sql
 * @return array<int,string> sentencias sin el «;» final, recortadas
 */
function migrar_sentencias(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $largo  = strlen($sql);
    $comilla = null;   // ', " o ` mientras se está adentro de un texto

    for ($i = 0; $i < $largo; $i++) {
        $c = $sql[$i];
        $sig = $i + 1 < $largo ? $sql[$i + 1] : '';

        if ($comilla !== null) {
            $actual .= $c;
            if ($c === '\\' && $comilla !== '`' && $sig !== '') {   // carácter escapado
                $actual .= $sig;
                $i++;
            } elseif ($c === $comilla) {
                if ($sig === $comilla) { $actual .= $sig; $i++; }      // comilla duplicada
                else { $comilla = null; }
            }
            continue;
        }

        // Comentario de línea: «-- » (con espacio o fin de línea) o «#».
        if (($c === '-' && $sig === '-' && ($i + 2 >= $largo || ctype_space($sql[$i + 2]))) || $c === '#') {
            $fin = strpos($sql, "\n", $i);
            $i = $fin === false ? $largo : $fin;
            $actual .= "\n";
            continue;
        }
        // Comentario de bloque.
        if ($c === '/' && $sig === '*') {
            $fin = strpos($sql, '*/', $i + 2);
            $i = $fin === false ? $largo : $fin + 1;
            $actual .= ' ';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $comilla = $c;
            $actual .= $c;
            continue;
        }
        if ($c === ';') {
            if (trim($actual) !== '') { $sentencias[] = trim($actual); }
            $actual = '';
            continue;
        }
        $actual .= $c;
    }
    if (trim($actual) !== '') { $sentencias[] = trim($actual); }
    return $sentencias;
}

/**
 * Una línea corta que dice qué hace la sentencia, para el informe.
 *
 * Junta los espacios y los saltos en uno y corta a 90 caracteres con «…».
 *
 * @param string $s sentencia completa
 * @return string resumen de una línea
 */
function migrar_resumen(string $s): string
{
    $s = (string) preg_replace('/\s+/', ' ', $s);
    return mb_strlen($s) > 90 ? mb_substr($s, 0, 89) . '…' : $s;
}

$nombre     = basename($archivo);
$sentencias = migrar_sentencias((string) file_get_contents($archivo));
$base       = (string) qv('SELECT DATABASE()');
// Sin la fila `app_entorno` se informa como producción: es lo prudente, y
// es lo mismo que hace reinicio.php.
$entorno    = (string) setting_get('app_entorno', 'produccion');

echo "Migración: $nombre\n";
echo "Base: $base (" . ($entorno === 'testing' ? 'prueba' : 'producción') . ")\n";
// Lo que necesita la API de escritura, sin mostrar el valor: sólo si alcanza.
echo 'Secreto de la instalación: '
   . (strlen((string)($GLOBALS['CONFIG']['app']['secret'] ?? '')) >= 32
        ? "correcto (32 caracteres o más)\n"
        : "CORTO o ausente: la API de escritura no va a funcionar hasta configurarlo\n");
echo count($sentencias) . " sentencias.\n";

if ($simular) {
    foreach ($sentencias as $n => $s) {
        printf("  %2d. %s\n", $n + 1, migrar_resumen($s));
    }
    echo "Simulación: no se aplicó nada.\n";
    exit(0);
}

// Se corren todas, aunque alguna falle, y al final se informa cuáles. Van por
// exec() en la misma conexión, así que las variables de sesión (@sql) y los
// PREPARE de una sentencia sirven para la siguiente (la migración 002 lo usa).
//
// Cada sentencia se manda como texto (preparación emulada) y se consume su
// resultado. Con exec(), una sentencia que devuelve filas —el EXECUTE de la
// 002 cuando la columna ya existe corre un 'SELECT 1'— dejaba el resultado
// sin leer y todas las siguientes fallaban con «Cannot execute queries while
// other unbuffered queries are active»: la 002 no se podía repetir. Y sin la
// emulación, PREPARE/EXECUTE no se pueden mandar como sentencia preparada.
db()->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$ok = 0;
$fallas = [];
foreach ($sentencias as $n => $s) {
    try {
        $st = db()->query($s);
        if ($st !== false) {
            if ($st->columnCount() > 0) { $st->fetchAll(); }
            $st->closeCursor();
        }
        $ok++;
    } catch (\Throwable $e) {
        $fallas[] = sprintf('  %2d. %s → %s', $n + 1, migrar_resumen($s), $e->getMessage());
    }
}

echo "$ok de " . count($sentencias) . " sentencias aplicadas.\n";
if ($fallas) {
    echo "Fallaron:\n" . implode("\n", $fallas) . "\n";
    log_app('migracion.error', ['tipo' => 'error', 'actor' => 'migrar',
        'detalle' => $nombre . ' · ' . count($fallas) . ' sentencias fallaron']);
    exit(1);
}

// La marca sólo queda si TODO salió bien, y sólo si el archivo se llama
// migracion-NNN-…: así queda constancia en la base de cuáles se aplicaron
// completas. Con alguna falla se salió arriba con código 1, sin marca.
if (preg_match('/^migracion-(\d{3})/', $nombre, $m)) {
    setting_set('migracion_' . $m[1], date('Y-m-d H:i:s'));
}
log_app('migracion.aplicar', ['tipo' => 'sistema', 'actor' => 'migrar',
    'detalle' => $nombre . ' · ' . $ok . ' sentencias']);
echo "Listo.\n";
