<?php
declare(strict_types=1);

// ============================================================
//  Validación del formulario grande de Ajustes (pages/ajustes.php).
//
//  Funciones puras: reciben el POST y una forma de leer el valor actual de un
//  ajuste, y devuelven qué guardar y qué está mal. No tocan la base, así se
//  pueden probar sin ella (test/casos/e1.php). La página las carga con
//  require_once; no van en bootstrap porque sólo las usa esa pantalla.
//
//  Reglas (las mismas que anuncian los «hint» de la vista):
//    · Un campo que no llega en el POST no se toca.
//    · Números (pausas, límite diario, días de papelera): enteros. Vacío
//      conserva el valor guardado. Pausas y límite, mayores que 0, y la pausa
//      mínima no puede superar a la máxima. Papelera: de 7 a 365 días (con
//      menos, la purga del cron borraría lo archivado casi enseguida).
//    · Clave del cron: vacía (desactiva la vía por URL) o de 24 a 128
//      caracteres.
//    · Correo de contacto: vacío o un correo válido. Lista de avisos: vacía o
//      direcciones válidas separadas por coma (se guardan normalizadas).
//    · Teléfono: vacío o un teléfono (telefono_valido de crm.php). WhatsApp:
//      se guardan sólo los dígitos (es lo que usa el botón de wa.me), y tienen
//      que ser al menos 8.
//    · Modo de trabajo: 'testing' o 'produccion'. Volver a «Prueba» desde
//      «Producción» exige además la casilla `confirmar_prueba`.
// ============================================================

/**
 * Tipos de alerta cuyo interruptor se ofrece en Ajustes: todos menos
 * 'sistema', que queda siempre como está (encendido por defecto). La vista
 * dibuja exactamente estos y la página guarda exactamente estos: si una
 * lista y la otra no coinciden, guardar apaga en silencio el que falta (eso
 * le pasaba a avisar_sistema).
 *
 * @return array<string, array> clave del tipo => definición de notif_tipos()
 */
function ajustes_tipos_aviso(): array
{
    $t = notif_tipos();
    unset($t['sistema']);
    return $t;
}

/**
 * Valida el formulario grande de Ajustes.
 *
 * @param array    $post   el $_POST del formulario.
 * @param callable $actual fn(string $clave, string $defecto): string, el valor
 *                         guardado hoy (setting_get).
 * @return array{valores: array<string,string>, errores: string[], entorno: ?string}
 *   valores: clave => valor a guardar (sólo las que llegaron y son válidas);
 *   errores: mensajes para mostrar (si hay alguno no se guarda nada);
 *   entorno: 'a_prueba' o 'a_produccion' si cambia el modo de trabajo, o null.
 */
function ajustes_validar(array $post, callable $actual): array
{
    $valores = [];
    $errores = [];

    // Texto recortado de un campo, o null si no llegó. Un array (alguien que
    // arma el pedido a mano) cuenta como vacío.
    $txt = static function (string $k) use ($post): ?string {
        if (!array_key_exists($k, $post)) { return null; }
        return is_array($post[$k]) ? '' : trim((string)$post[$k]);
    };

    // Textos libres.
    foreach (['app_nombre', 'empresa_nombre', 'empresa_web'] as $k) {
        $v = $txt($k);
        if ($v !== null) { $valores[$k] = $v; }
    }

    $v = $txt('empresa_telefono');
    if ($v !== null) {
        if (!telefono_valido($v)) { $errores[] = 'El teléfono tiene que tener al menos seis números (se aceptan + ( ) - . y espacios).'; }
        else { $valores['empresa_telefono'] = $v; }
    }

    $v = $txt('empresa_whatsapp');
    if ($v !== null) {
        $dig = preg_replace('/\D/', '', $v) ?? '';
        if ($v !== '' && (preg_match('/[^0-9+().\/\s-]/', $v) || strlen($dig) < 8)) {
            $errores[] = 'El WhatsApp tiene que ser un número con código de país (al menos ocho dígitos).';
        } else {
            $valores['empresa_whatsapp'] = $dig;
        }
    }

    $v = $txt('empresa_email');
    if ($v !== null) {
        if ($v !== '' && !email_valido($v)) { $errores[] = 'El correo de contacto no es válido.'; }
        else { $valores['empresa_email'] = $v; }
    }

    $v = $txt('avisar_email_a');
    if ($v !== null) {
        $lista = array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
        $malas = array_values(array_filter($lista, static fn($x) => !email_valido($x)));
        if ($malas) {
            $errores[] = 'Estas direcciones de aviso no son válidas: ' . implode(', ', $malas) . '.';
        } else {
            $valores['avisar_email_a'] = implode(', ', $lista);
        }
    }

    // Enteros. Vacío = conservar lo guardado. Devuelve el valor que va a
    // quedar (el nuevo o el actual) para las comparaciones de después.
    $entero = static function (string $k, int $min, int $max, string $nombre, string $def)
        use ($txt, $actual, &$valores, &$errores): ?int {
        $v = $txt($k);
        if ($v === null || $v === '') {
            $a = trim((string)$actual($k, $def));
            return ctype_digit($a) ? (int)$a : null;
        }
        if (!ctype_digit($v)) {
            $errores[] = "«{$nombre}» tiene que ser un número entero.";
            return null;
        }
        $n = (int)$v;
        if ($n < $min || $n > $max) {
            $errores[] = "«{$nombre}» tiene que ser un número entre $min y $max.";
            return null;
        }
        $valores[$k] = (string)$n;
        return $n;
    };

    $pmin = $entero('campana_pausa_min', 1, 3600, 'Pausa mínima', '25');
    $pmax = $entero('campana_pausa_max', 1, 3600, 'Pausa máxima', '45');
    $entero('campana_limite_diario', 1, 100000, 'Límite diario', '300');
    $entero('papelera_dias', 7, 365, 'Días en la papelera', '60');
    if ($pmin !== null && $pmax !== null && $pmin > $pmax) {
        $errores[] = 'La pausa mínima no puede ser mayor que la máxima.';
        unset($valores['campana_pausa_min'], $valores['campana_pausa_max']);
    }

    $v = $txt('cron_clave');
    if ($v !== null) {
        $largo = mb_strlen($v);
        if ($v !== '' && ($largo < 24 || $largo > 128)) {
            $errores[] = 'La clave del cron tiene que tener entre 24 y 128 caracteres (o quedar vacía para no permitir el cron por URL).';
        } else {
            $valores['cron_clave'] = $v;
        }
    }

    // Modo de trabajo. Cualquier valor guardado distinto de 'testing' se
    // comporta como Producción (es_testing()).
    $entorno = null;
    $v = $txt('app_entorno');
    if ($v !== null) {
        if (!in_array($v, ['testing', 'produccion'], true)) {
            $errores[] = 'El modo de trabajo elegido no existe.';
        } else {
            $antes = (string)$actual('app_entorno', 'testing') === 'testing' ? 'testing' : 'produccion';
            if ($v !== $antes) {
                if ($v === 'testing' && (string)($post['confirmar_prueba'] ?? '') !== '1') {
                    $errores[] = 'Para volver a «Prueba» hay que tildar la confirmación: en ese modo se puede reiniciar todo y borrar la base.';
                } else {
                    $entorno = $v === 'testing' ? 'a_prueba' : 'a_produccion';
                }
            }
            if ($entorno !== null || $v === $antes) { $valores['app_entorno'] = $v; }
        }
    }

    return ['valores' => $valores, 'errores' => $errores, 'entorno' => $entorno];
}
