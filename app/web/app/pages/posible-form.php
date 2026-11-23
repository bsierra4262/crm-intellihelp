<?php
declare(strict_types=1);

// ============================================================
//  Página `posible-form`: alta (sin `id`) y edición (`?id=N`) de un posible
//  cliente (lead).
//
//  GET dibuja views/posible-form.php. POST valida (apellido y empresa
//  obligatorios, correo y teléfonos con formato), guarda con
//  posible_guardar() y redirige a la ficha.
//
//  Tablas: escribe `posibles_clientes`; lee `usuarios`.
//  Módulo: `posibles` (se apaga aparte en producción, ver modulos.php).
//  Permisos: cualquier usuario con sesión edita cualquier posible cliente,
//  incluso uno ya convertido (la edición no se bloquea después de convertir).
//
//  Estado, fuente y calificación se validan contra sus listas; el propietario,
//  que exista.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? posible_get($id) : null;
if ($id && !$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Posible cliente inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    // La empresa es obligatoria porque la conversión crea (o reutiliza) una
    // cuenta con ese nombre.
    if (trim((string)($d['apellido'] ?? '')) === '') { $errores['apellido'] = 'El apellido es obligatorio.'; }
    if (trim((string)($d['empresa']  ?? '')) === '') { $errores['empresa']  = 'La empresa es obligatoria.'; }
    if (trim((string)($d['email'] ?? '')) !== '' && !email_valido($d['email'])) {
        $errores['email'] = 'La dirección de correo no es válida.';
    }
    if (trim((string)($d['email2'] ?? '')) !== '' && !email_valido($d['email2'])) {
        $errores['email2'] = 'El correo secundario no es válido.';
    }
    // Valores de lista: el navegador manda lo que quiera.
    foreach (['estado' => [estados_posible_cliente(), 'El estado'], 'fuente' => [fuentes(), 'La fuente'],
              'calificacion' => [calificaciones(), 'La calificación']] as $k => [$lista, $et]) {
        if (trim((string)($d[$k] ?? '')) !== '' && !in_array($d[$k], $lista, true)) {
            $errores[$k] = $et . ' no es una de la lista.';
        }
    }
    if (!empty($d['propietario_id']) && !qv('SELECT 1 FROM usuarios WHERE id = ?', [(int)$d['propietario_id']])) {
        $errores['propietario_id'] = 'El propietario elegido no existe.';
    }

    // El teléfono se valida en el servidor: `type="tel"` solo cambia el teclado
    // del celular y deja escribir letras.
    foreach (['telefono' => 'Teléfono directo', 'movil' => 'Móvil', 'conmutador' => 'Conmutador'] as $k => $et) {
        if (trim((string)($d[$k] ?? '')) !== '' && !telefono_valido($d[$k])) {
            $errores[$k] = "$et: sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.";
        }
    }

    if (!$errores) {
        $nuevoId = posible_guardar($d, $id ?: null);
        flash($id ? 'Posible cliente actualizado.' : 'Posible cliente creado.', 'success');
        redirect('posible-ver?id=' . $nuevoId);
    }
    // Con errores se vuelve a dibujar con lo escrito encima de la ficha.
    $p = array_merge($p ?? [], $d);
}

render('posible-form', [
    'p'        => $p,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
], $id ? 'Editar posible cliente' : 'Nuevo posible cliente');
