<?php
declare(strict_types=1);

// ============================================================
//  Datos de prueba: saber exactamente cuáles son y poder sacarlos.
//
//  El problema que resuelve: la instalación es UNA sola. Mientras se prueba,
//  el sistema siembra contactos ficticios, una empresa, una lista y las
//  plantillas. El día que se pasa a producción esos datos no pueden quedar
//  mezclados con los reales —nadie quiere ver «Dominio Inexistente» entre sus
//  clientes—, pero tampoco se los puede borrar a ojo: para eso habría que
//  adivinar cuáles eran, y adivinar sobre la base de alguien es la peor idea.
//
//  Así que se anota qué se sembró. Al sembrar se guardan los identificadores
//  exactos; al limpiar se borran ESOS y ninguno más. Lo que cargó una persona
//  no se toca nunca, ni por accidente.
// ============================================================

/** Deja anotado qué sembró el script de prueba. */
function datos_prueba_anotar(array $ids): void
{
    setting_set('datos_prueba', json_encode($ids, JSON_UNESCAPED_UNICODE));
}

/** Lo que está anotado, por tabla. */
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
        $marcas = implode(',', array_fill(0, count($lista), '?'));
        $n = (int) qv("SELECT COUNT(*) FROM `$tabla` WHERE id IN ($marcas)", $lista);
        if ($n > 0) { $detalle[$texto] = $n; $total += $n; }
    }
    return ['total' => $total, 'detalle' => $detalle];
}

/**
 * Borra los datos de prueba, y sólo esos.
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

    $listas = array_map('intval', (array)($ids['listas'] ?? []));
    if ($listas) {
        $m = implode(',', array_fill(0, count($listas), '?'));
        // Primero lo que cuelga de la lista, después la lista.
        $camp = q("SELECT id FROM campanas WHERE lista_id IN ($m)", $listas)->fetchAll(PDO::FETCH_COLUMN);
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
        foreach (['mensajes' => 'contacto_id', 'notas' => 'registro_id',
                  'actividades' => 'contacto_id', 'lista_miembros' => 'contacto_id'] as $t => $col) {
            try { q("DELETE FROM `$t` WHERE `$col` IN ($m)", $contactos); } catch (\Throwable $e) {}
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

    // Las alertas y la marca de lectura de la casilla son ruido de la prueba.
    q('DELETE FROM notificaciones');
    setting_set('datos_prueba', '');

    log_app('datos_prueba.limpiar', ['tipo' => 'sistema',
        'detalle' => $hizo ? implode(' · ', array_map(fn($k, $v) => "$v $k", array_keys($hizo), $hizo))
                           : 'no quedaba nada por borrar']);
    return $hizo;
}
