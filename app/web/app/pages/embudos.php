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
//    · etapa_borrar           borra una etapa sin negocios vivos.
//  El resultado se muestra en la misma pantalla ($aviso); no redirige, así
//  que recargar la página vuelve a mandar el POST.
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

$aviso = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'embudo_guardar') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            if ($nombre === '') { throw new RuntimeException('Poné un nombre para el embudo.'); }
            $id = (int)($_POST['id'] ?? 0);
            // La vista no tiene formulario para editar un embudo existente: esta rama
            // (cambiar nombre o desactivar) hoy no se usa desde la pantalla.
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
                // Un embudo sin etapas tampoco sirve: se arranca con las tres etapas en
                // curso que tiene cualquier proceso de venta, más «Ganado» y «Perdido», y
                // después se ajustan.
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
            // Dos UPDATE sin transacción: con un id que no existe, ningún embudo queda
            // como predeterminado (embudo_predeterminado() toma entonces el primero).
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
            // Cambiar el tipo de una etapa con negocios adentro (de abierta a ganada,
            // por ejemplo) no toca cerrado_en ni la probabilidad de esos negocios.
            if ($id) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
                q("UPDATE etapas SET $sets WHERE id = ?", [...array_values($campos), $id]);
            } else {
                // Sin embudo_id válido el INSERT falla por la clave foránea y el error de
                // la base se muestra en el aviso.
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
            // Cuenta sólo los negocios vivos. Si hay alguno en la papelera en esa etapa,
            // el DELETE falla por la clave foránea de negocios.etapa_id y se muestra el
            // error de la base. Los tramos de negocio_etapas no tienen clave foránea y
            // quedan apuntando a una etapa que ya no existe.
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

// Se listan también los embudos y las etapas inactivos: es la pantalla donde
// se vuelven a activar.
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
