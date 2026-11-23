<?php
declare(strict_types=1);
require_admin();

// ============================================================
//  Ruta `embudos` (sólo administradores): embudos de venta y sus etapas.
//
//  Acciones por POST (campo `accion`), todas con CSRF:
//    · embudo_guardar         crea un embudo (con cinco etapas de arranque)
//                             o, con id, le cambia nombre y estado;
//    · embudo_predeterminado  marca cuál se usa por defecto;
//    · etapa_guardar          crea o edita una etapa;
//    · etapa_borrar           borra una etapa sin negocios (vivos o en la
//                             papelera: los dos la referencian).
//  Después de cada POST se redirige acá con un aviso (flash), así recargar no
//  repite la acción. Los errores propios se muestran tal cual; uno de la base
//  se registra con log_error() y se muestra un mensaje claro.
//
//  Tablas: embudos, etapas; lee negocios para saber qué etapas están en uso.
//  Módulo «negocios». Vista: views/embudos.php.
// ============================================================
//
// Administración de los embudos de venta y sus etapas.
//
// Existe porque sin ningún embudo cargado la pantalla del Embudo mostraba un
// error y no ofrecía ninguna salida: no había forma de crear uno desde el
// sistema, había que entrar a la base de datos.

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'embudo_guardar') {
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 80);
            if ($nombre === '') { throw new RuntimeException('Poné un nombre para el embudo.'); }
            $id = (int)($_POST['id'] ?? 0);
            if (qv('SELECT id FROM embudos WHERE nombre = ? AND id <> ?', [$nombre, $id])) {
                throw new RuntimeException('Ya hay un embudo con ese nombre.');
            }
            if ($id) {
                // Renombrar o activar/desactivar, desde el formulario de cada embudo.
                $emb = q1('SELECT * FROM embudos WHERE id = ?', [$id]);
                if (!$emb) { throw new RuntimeException('Ese embudo ya no existe.'); }
                $activo = empty($_POST['activo']) ? 0 : 1;
                if (!$activo && (int)$emb['predeterminado']) {
                    throw new RuntimeException('El embudo que se usa por defecto no se puede desactivar: '
                        . 'elegí otro como predeterminado primero.');
                }
                q('UPDATE embudos SET nombre = ?, activo = ? WHERE id = ?', [$nombre, $activo, $id]);
            } else {
                $id = negocio_tx(function () use ($nombre): int {
                    $orden = (int) qv('SELECT COALESCE(MAX(orden), 0) + 1 FROM embudos');
                    // El primero que se crea queda como predeterminado: si no,
                    // habría embudos y ninguno elegido, que es el mismo callejón.
                    $primero = !qv('SELECT 1 FROM embudos LIMIT 1');
                    q('INSERT INTO embudos (nombre, activo, predeterminado, orden) VALUES (?, 1, ?, ?)',
                      [$nombre, $primero ? 1 : 0, $orden]);
                    $id = last_id();
                    // Un embudo sin etapas tampoco sirve: se arranca con cinco, las tres
                    // en curso que tiene cualquier proceso de venta más «Ganado» y
                    // «Perdido», y después se ajustan.
                    foreach ([['Contacto inicial', 10, 'abierta', '#663399', 1],
                              ['Propuesta enviada', 50, 'abierta', '#1f6f9c', 2],
                              ['Negociación', 75, 'abierta', '#a8620a', 3],
                              ['Ganado', 100, 'ganada', '#5f8a12', 4],
                              ['Perdido', 0, 'perdida', '#a12a1e', 5]] as [$n, $prob, $tipo, $color, $ord]) {
                        q('INSERT INTO etapas (embudo_id, nombre, probabilidad, tipo, color, orden, activo)
                           VALUES (?, ?, ?, ?, ?, ?, 1)', [$id, $n, $prob, $tipo, $color, $ord]);
                    }
                    return $id;
                });
            }
            log_app('embudo.guardar', ['entidad' => 'embudo', 'entidad_id' => $id, 'detalle' => $nombre]);
            flash('Embudo guardado.', 'success');

        } elseif ($accion === 'embudo_predeterminado') {
            $id = (int)($_POST['id'] ?? 0);
            if (!qv('SELECT 1 FROM embudos WHERE id = ?', [$id])) {
                throw new RuntimeException('Ese embudo ya no existe.');
            }
            // Los dos UPDATE juntos: nunca queda un rato sin predeterminado.
            negocio_tx(function () use ($id): void {
                q('UPDATE embudos SET predeterminado = 0 WHERE id <> ?', [$id]);
                q('UPDATE embudos SET predeterminado = 1, activo = 1 WHERE id = ?', [$id]);
            });
            log_app('embudo.predeterminado', ['entidad' => 'embudo', 'entidad_id' => $id]);
            flash('Es el embudo que se usa por defecto.', 'success');

        } elseif ($accion === 'etapa_guardar') {
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 80);
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
            $embudoId = $id ? (int) qv('SELECT embudo_id FROM etapas WHERE id = ?', [$id])
                            : (int)($_POST['embudo_id'] ?? 0);
            if (!$embudoId || !qv('SELECT 1 FROM embudos WHERE id = ?', [$embudoId])) {
                throw new RuntimeException('Esa etapa o su embudo ya no existen.');
            }
            if (qv('SELECT 1 FROM etapas WHERE embudo_id = ? AND nombre = ? AND id <> ?', [$embudoId, $nombre, $id])) {
                throw new RuntimeException('Ese embudo ya tiene una etapa con ese nombre.');
            }
            // Cambiar el tipo de una etapa con negocios adentro (de abierta a ganada,
            // por ejemplo) no toca cerrado_en ni la probabilidad de esos negocios.
            if ($id) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
                q("UPDATE etapas SET $sets WHERE id = ?", [...array_values($campos), $id]);
            } else {
                $campos['embudo_id'] = $embudoId;
                $cols = implode(', ', array_keys($campos));
                $ph   = implode(', ', array_fill(0, count($campos), '?'));
                q("INSERT INTO etapas ($cols) VALUES ($ph)", array_values($campos));
                $id = last_id();
            }
            log_app('etapa.guardar', ['entidad' => 'etapa', 'entidad_id' => $id, 'detalle' => $nombre]);
            flash('Etapa guardada.', 'success');

        } elseif ($accion === 'etapa_borrar') {
            $id = (int)($_POST['id'] ?? 0);
            // Una etapa con negocios adentro no se borra, estén vivos o en la
            // papelera: los dos apuntan a ella por clave foránea (el DELETE
            // fallaría) y un negocio restaurado quedaría sin etapa.
            // Los tramos de negocio_etapas no tienen clave foránea: quedan, y el
            // recorrido del negocio los muestra como «Etapa eliminada».
            $n = (int) qv('SELECT COUNT(*) FROM negocios WHERE etapa_id = ?', [$id]);
            if ($n > 0) {
                $enPapelera = (int) qv('SELECT COUNT(*) FROM negocios WHERE etapa_id = ? AND eliminado_en IS NOT NULL', [$id]);
                throw new RuntimeException("No se puede borrar: hay $n negocio(s) en esa etapa"
                    . ($enPapelera ? " ($enPapelera en la papelera)" : '') . '. '
                    . 'Movelos a otra y volvé a intentar. Si sólo querés sacarla del embudo, desactivala.');
            }
            q('DELETE FROM etapas WHERE id = ?', [$id]);
            log_app('etapa.borrar', ['entidad' => 'etapa', 'entidad_id' => $id, 'detalle' => 'Etapa eliminada']);
            flash('Etapa eliminada.', 'success');
        }
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
    } catch (\Throwable $e) {
        log_error('embudos.' . $accion, $e->getMessage(), $e->getFile(), $e->getLine());
        flash('No se pudo guardar el cambio. Probá de nuevo; si sigue pasando, avisale a quien mantiene el sistema.', 'error');
    }
    redirect('embudos');
}

// Se listan también los embudos y las etapas inactivos: es la pantalla donde
// se vuelven a activar.
$lista = q('SELECT * FROM embudos ORDER BY orden, id')->fetchAll();
$etapas = [];
foreach (q('SELECT * FROM etapas ORDER BY embudo_id, orden, id')->fetchAll() as $e) {
    $etapas[(int)$e['embudo_id']][] = $e;
}
// Cuántos negocios hay en cada etapa, contando los de la papelera: es el dato
// que decide si se puede borrar.
$uso = [];
foreach (q('SELECT etapa_id, COUNT(*) n FROM negocios GROUP BY etapa_id')->fetchAll() as $r) {
    $uso[(int)$r['etapa_id']] = (int)$r['n'];
}

// El resultado de cada POST llega como flash, que muestra el layout.
$aviso = null;
render('embudos', compact('lista', 'etapas', 'uso', 'aviso'), 'Embudos y etapas');
