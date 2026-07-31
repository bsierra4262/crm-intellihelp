<?php
declare(strict_types=1);
require_admin();

// Administración de los embudos de venta y sus etapas.
//
// Existe porque sin ningún embudo cargado la pantalla del Embudo mostraba un
// error y no ofrecía ninguna salida: no había forma de crear uno desde el
// sistema, había que entrar a la base de datos.

$aviso = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'embudo_guardar') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            if ($nombre === '') { throw new RuntimeException('Poné un nombre para el embudo.'); }
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                q('UPDATE embudos SET nombre = ?, activo = ? WHERE id = ?',
                  [$nombre, empty($_POST['activo']) ? 0 : 1, $id]);
            } else {
                $orden = (int) qv('SELECT COALESCE(MAX(orden), 0) + 1 FROM embudos');
                // El primero que se crea queda como predeterminado: si no,
                // habría embudos y ninguno elegido, que es el mismo callejón.
                $primero = !qv('SELECT 1 FROM embudos LIMIT 1');
                q('INSERT INTO embudos (nombre, activo, predeterminado, orden) VALUES (?, 1, ?, ?)',
                  [$nombre, $primero ? 1 : 0, $orden]);
                $id = last_id();
                // Un embudo sin etapas tampoco sirve: se arranca con las tres
                // que tiene cualquier proceso de venta y después se ajustan.
                foreach ([['Contacto inicial', 10, 'abierta', '#663399', 1],
                          ['Propuesta enviada', 50, 'abierta', '#1f6f9c', 2],
                          ['Negociación', 75, 'abierta', '#a8620a', 3],
                          ['Ganado', 100, 'ganada', '#5f8a12', 4],
                          ['Perdido', 0, 'perdida', '#a12a1e', 5]] as [$n, $prob, $tipo, $color, $ord]) {
                    q('INSERT INTO etapas (embudo_id, nombre, probabilidad, tipo, color, orden, activo)
                       VALUES (?, ?, ?, ?, ?, ?, 1)', [$id, $n, $prob, $tipo, $color, $ord]);
                }
            }
            log_app('embudo.guardar', ['entidad' => 'embudo', 'entidad_id' => $id, 'detalle' => $nombre]);
            $aviso = ['success', 'Embudo guardado.'];

        } elseif ($accion === 'embudo_predeterminado') {
            $id = (int)($_POST['id'] ?? 0);
            q('UPDATE embudos SET predeterminado = 0');
            q('UPDATE embudos SET predeterminado = 1, activo = 1 WHERE id = ?', [$id]);
            $aviso = ['success', 'Es el embudo que se usa por defecto.'];

        } elseif ($accion === 'etapa_guardar') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            if ($nombre === '') { throw new RuntimeException('Poné un nombre para la etapa.'); }
            $tipo = in_array($_POST['tipo'] ?? '', ['abierta', 'ganada', 'perdida'], true)
                  ? (string)$_POST['tipo'] : 'abierta';
            $campos = [
                'nombre'       => $nombre,
                'probabilidad' => max(0, min(100, (int)($_POST['probabilidad'] ?? 0))),
                'tipo'         => $tipo,
                'color'        => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? ''))
                                  ? (string)$_POST['color'] : '#663399',
                'orden'        => (int)($_POST['orden'] ?? 0),
                'activo'       => empty($_POST['activo']) ? 0 : 1,
            ];
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
                q("UPDATE etapas SET $sets WHERE id = ?", [...array_values($campos), $id]);
            } else {
                $campos['embudo_id'] = (int)($_POST['embudo_id'] ?? 0);
                $cols = implode(', ', array_keys($campos));
                $ph   = implode(', ', array_fill(0, count($campos), '?'));
                q("INSERT INTO etapas ($cols) VALUES ($ph)", array_values($campos));
                $id = last_id();
            }
            log_app('etapa.guardar', ['entidad' => 'etapa', 'entidad_id' => $id, 'detalle' => $nombre]);
            $aviso = ['success', 'Etapa guardada.'];

        } elseif ($accion === 'etapa_borrar') {
            $id = (int)($_POST['id'] ?? 0);
            // Una etapa con negocios adentro no se borra: esos negocios
            // quedarían apuntando a una etapa que no existe y desaparecerían
            // del embudo sin que nadie se entere.
            $n = (int) qv('SELECT COUNT(*) FROM negocios WHERE etapa_id = ? AND eliminado_en IS NULL', [$id]);
            if ($n > 0) {
                throw new RuntimeException("No se puede borrar: hay $n negocio(s) en esa etapa. "
                    . 'Movelos a otra y volvé a intentar. Si sólo querés sacarla del embudo, desactivala.');
            }
            q('DELETE FROM etapas WHERE id = ?', [$id]);
            log_app('etapa.borrar', ['entidad' => 'etapa', 'entidad_id' => $id, 'detalle' => 'Etapa eliminada']);
            $aviso = ['success', 'Etapa eliminada.'];
        }
    } catch (\Throwable $e) {
        $aviso = ['error', $e->getMessage()];
    }
}

$lista = q('SELECT * FROM embudos ORDER BY orden, id')->fetchAll();
$etapas = [];
foreach (q('SELECT * FROM etapas ORDER BY embudo_id, orden, id')->fetchAll() as $e) {
    $etapas[(int)$e['embudo_id']][] = $e;
}
// Cuántos negocios hay en cada etapa: es el dato que decide si se puede borrar.
$uso = [];
foreach (q('SELECT etapa_id, COUNT(*) n FROM negocios WHERE eliminado_en IS NULL GROUP BY etapa_id')->fetchAll() as $r) {
    $uso[(int)$r['etapa_id']] = (int)$r['n'];
}

render('embudos', compact('lista', 'etapas', 'uso', 'aviso'), 'Embudos y etapas');
