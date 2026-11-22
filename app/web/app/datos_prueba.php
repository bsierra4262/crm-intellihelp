<?php
declare(strict_types=1);

// ============================================================
//  Datos de prueba: saber exactamente cuáles son y poder sacarlos.
//
//  El problema que resuelve: cuando esto se escribió la instalación era UNA
//  sola (hoy hay dos, crm/ y crm-pruebas/, cada una con su base, y la de
//  pruebas se siembra con app/reinicio.php). Mientras se prueba,
//  el sistema siembra contactos ficticios, una empresa, una lista y las
//  plantillas. El día que se pasa a producción esos datos no pueden quedar
//  mezclados con los reales —nadie quiere ver «Dominio Inexistente» entre sus
//  clientes—, pero tampoco se los puede borrar a ojo: para eso habría que
//  adivinar cuáles eran, y adivinar sobre la base de alguien es la peor idea.
//
//  Así que se anota qué se sembró. Al sembrar se guardan los identificadores
//  exactos; al limpiar se borran ESOS y ninguno más. Lo que cargó una persona
//  no se toca nunca, ni por accidente.
//
//  Lo anotado vive en el ajuste `datos_prueba` de `app_settings`, como JSON:
//  {"contactos":[ids], "cuentas":[ids], "listas":[ids], "plantillas":[ids]}.
//  Lo usa la pantalla Ajustes (pages/ajustes.php) al pasar a producción.
// ============================================================

/**
 * Deja anotado qué sembró el script de prueba (reemplaza lo anterior).
 *
 * @param array<string,int[]> $ids tabla => ids sembrados
 */
function datos_prueba_anotar(array $ids): void
{
    setting_set('datos_prueba', json_encode($ids, JSON_UNESCAPED_UNICODE));
}

/** Lo que está anotado, por tabla; [] si no hay nada o el JSON no se entiende. */
function datos_prueba_ids(): array
{
    $d = json_decode((string) setting_get('datos_prueba', ''), true);
    return is_array($d) ? $d : [];
}

/**
 * Cuántos datos de prueba quedan, y de qué tipo.
 *
 * Se cuenta contra la base y no contra lo anotado: si alguien ya borró un
 * contacto de prueba a mano, no tiene sentido seguir diciendo que está.
 *
 * @return array{total:int, detalle:array<string,int>}
 */
function datos_prueba_resumen(): array
{
    $ids = datos_prueba_ids();
    $nombres = [
        'contactos'  => 'contactos ficticios',
        'cuentas'    => 'empresa de prueba',
        'listas'     => 'lista «Prueba del circuito»',
        'plantillas' => 'plantillas prearmadas',
    ];
    $detalle = [];
    $total   = 0;
    foreach ($nombres as $tabla => $texto) {
        $lista = array_map('intval', (array)($ids[$tabla] ?? []));
        if (!$lista) { continue; }
        // El nombre de tabla sale de la lista fija de arriba, nunca del ajuste.
        $marcas = implode(',', array_fill(0, count($lista), '?'));
        $n = (int) qv("SELECT COUNT(*) FROM `$tabla` WHERE id IN ($marcas)", $lista);
        if ($n > 0) { $detalle[$texto] = $n; $total += $n; }
    }
    return ['total' => $total, 'detalle' => $detalle];
}

/**
 * Borra los datos de prueba, y sólo esos.
 *
 * Orden: campañas y envíos de las listas de prueba, las listas, lo que cuelga
 * de los contactos, los contactos, y al final la empresa (si quedó libre).
 * No va en una transacción: un error a mitad deja la limpieza a medias, pero
 * como el ajuste se vacía recién al final, se puede volver a correr.
 *
 * Se van también las campañas y los envíos que los usaron: dejar una campaña
 * apuntando a una lista que ya no existe sólo sirve para confundir a quien
 * mire los números después.
 *
 * Las plantillas NO se borran: son el punto de partida para escribir las
 * campañas reales, y volver a armarlas a mano sería trabajo perdido.
 *
 * @return array<string,int> qué se borró de cada cosa
 */
function datos_prueba_limpiar(): array
{
    $ids  = datos_prueba_ids();
    $hizo = [];
    $campanasBorradas = [];

    $listas = array_map('intval', (array)($ids['listas'] ?? []));
    if ($listas) {
        $m = implode(',', array_fill(0, count($listas), '?'));
        // Primero lo que cuelga de la lista, después la lista.
        $camp = q("SELECT id FROM campanas WHERE lista_id IN ($m)", $listas)->fetchAll(PDO::FETCH_COLUMN);
        $campanasBorradas = $camp;
        foreach ($camp as $cid) {
            q('DELETE FROM envio_eventos WHERE envio_id IN (SELECT id FROM envios WHERE campana_id = ?)', [(int)$cid]);
            q('DELETE FROM envios WHERE campana_id = ?', [(int)$cid]);
        }
        if ($camp) {
            $mc = implode(',', array_fill(0, count($camp), '?'));
            $hizo['campañas de prueba'] = q("DELETE FROM campanas WHERE id IN ($mc)", $camp)->rowCount();
        }
        q("DELETE FROM lista_miembros WHERE lista_id IN ($m)", $listas);
        $hizo['listas de prueba'] = q("DELETE FROM listas WHERE id IN ($m)", $listas)->rowCount();
    }

    $contactos = array_map('intval', (array)($ids['contactos'] ?? []));
    if ($contactos) {
        $m = implode(',', array_fill(0, count($contactos), '?'));
        // Todo lo que quedó colgado de esos contactos mientras se probaba.
        // Cada borrado va en su try porque alguna tabla o columna puede no
        // existir en una base vieja; el error se calla. Notas, etiquetas y
        // adjuntos son polimórficos (entidad + entidad_id), no tienen
        // `contacto_id`.
        foreach (['mensajes' => 'contacto_id', 'actividades' => 'contacto_id',
                  'lista_miembros' => 'contacto_id'] as $t => $col) {
            try { q("DELETE FROM `$t` WHERE `$col` IN ($m)", $contactos); } catch (\Throwable $e) {}
        }
        foreach (['notas', 'etiqueta_registro'] as $t) {
            try { q("DELETE FROM `$t` WHERE entidad = 'contacto' AND entidad_id IN ($m)", $contactos); } catch (\Throwable $e) {}
        }
        q("DELETE FROM desuscripciones WHERE email IN (SELECT email FROM contactos WHERE id IN ($m))", $contactos);
        $hizo['contactos ficticios'] = q("DELETE FROM contactos WHERE id IN ($m)", $contactos)->rowCount();
    }

    // La empresa de prueba se borra sólo si no quedó ningún contacto real
    // colgado de ella: si alguien la usó para algo suyo, manda lo suyo.
    foreach (array_map('intval', (array)($ids['cuentas'] ?? [])) as $cta) {
        if ((int) qv('SELECT COUNT(*) FROM contactos WHERE cuenta_id = ? AND eliminado_en IS NULL', [$cta])) { continue; }
        if ((int) qv('SELECT COUNT(*) FROM negocios WHERE cuenta_id = ?', [$cta])) { continue; }
        $hizo['empresa de prueba'] = ($hizo['empresa de prueba'] ?? 0)
            + q('DELETE FROM cuentas WHERE id = ?', [$cta])->rowCount();
    }

    // Las alertas que generaron los datos de prueba (una respuesta a la campaña
    // de prueba, un rebote de un contacto ficticio) son ruido: se borran ésas y
    // ninguna más. Las alertas reales de la instalación no se tocan.
    $hizo['alertas de prueba'] = datos_prueba_borrar_alertas([
        'contacto' => $contactos,
        'cuenta'   => array_map('intval', (array)($ids['cuentas'] ?? [])),
        'campana'  => array_map('intval', $campanasBorradas),
        'lista'    => $listas,
    ]);
    if (!$hizo['alertas de prueba']) { unset($hizo['alertas de prueba']); }
    setting_set('datos_prueba', '');

    log_app('datos_prueba.limpiar', ['tipo' => 'sistema',
        'detalle' => $hizo ? implode(' · ', array_map(fn($k, $v) => "$v $k", array_keys($hizo), $hizo))
                           : 'no quedaba nada por borrar']);
    return $hizo;
}

/**
 * Borra las alertas ligadas a registros sembrados (por entidad + entidad_id)
 * y sus marcas de lectura. Devuelve cuántas alertas borró.
 *
 * @param array<string,int[]> $porEntidad entidad => ids
 */
function datos_prueba_borrar_alertas(array $porEntidad): int
{
    $n = 0;
    foreach ($porEntidad as $entidad => $lista) {
        $lista = array_values(array_filter(array_map('intval', (array)$lista)));
        if (!$lista) { continue; }
        $m = implode(',', array_fill(0, count($lista), '?'));
        $params = array_merge([(string)$entidad], $lista);
        try {
            q("DELETE FROM notificacion_lecturas WHERE notificacion_id IN
                 (SELECT id FROM notificaciones WHERE entidad = ? AND entidad_id IN ($m))", $params);
        } catch (\Throwable $e) {
            // Sin la migración 010 no hay tabla de lecturas: no hay nada que borrar.
        }
        $n += q("DELETE FROM notificaciones WHERE entidad = ? AND entidad_id IN ($m)", $params)->rowCount();
    }
    return $n;
}
