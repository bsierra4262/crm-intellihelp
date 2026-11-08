<?php
declare(strict_types=1);

// ============================================================
//  Aplicar una migración de la base.
//
//  Este script arranca con el bootstrap de SU instalación —el de crm/ o el de
//  crm-pruebas/—, así que usa la configuración y la base que le corresponden,
//  sin pedir credenciales a nadie. Lo llama `deploy.py migrar`.
//
//  No se importa con `deploy.py schema`: ese camino arma el comando de mysql
//  con la contraseña de la base y toma el nombre de una variable que por
//  defecto apunta a PRODUCCIÓN, así que una migración «de prueba» podría
//  terminar en los datos reales.
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
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Esto se ejecuta por línea de comandos.\n");
}

$raiz = is_file(__DIR__ . '/web/app/bootstrap.php') ? __DIR__ . '/web' : __DIR__;
require $raiz . '/app/bootstrap.php';

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
 * @return array<int,string>
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

/** Una línea corta que dice qué hace la sentencia, para el informe. */
function migrar_resumen(string $s): string
{
    $s = (string) preg_replace('/\s+/', ' ', $s);
    return mb_strlen($s) > 90 ? mb_substr($s, 0, 89) . '…' : $s;
}

$nombre     = basename($archivo);
$sentencias = migrar_sentencias((string) file_get_contents($archivo));
$base       = (string) qv('SELECT DATABASE()');
$entorno    = (string) setting_get('app_entorno', 'testing');

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

$ok = 0;
$fallas = [];
foreach ($sentencias as $n => $s) {
    try {
        db()->exec($s);
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

if (preg_match('/^migracion-(\d{3})/', $nombre, $m)) {
    setting_set('migracion_' . $m[1], date('Y-m-d H:i:s'));
}
log_app('migracion.aplicar', ['tipo' => 'sistema', 'actor' => 'migrar',
    'detalle' => $nombre . ' · ' . $ok . ' sentencias']);
echo "Listo.\n";
