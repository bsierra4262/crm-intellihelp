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
//  No se validan contra sus listas el estado, la fuente ni la calificación:
//  llegan tal cual del POST.
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
    // cuenta con ese nombre. El correo secundario (`email2`) no se valida.
    if (trim((string)($d['apellido'] ?? '')) === '') { $errores['apellido'] = 'El apellido es obligatorio.'; }
    if (trim((string)($d['empresa']  ?? '')) === '') { $errores['empresa']  = 'La empresa es obligatoria.'; }
    if (trim((string)($d['email'] ?? '')) !== '' && !email_valido($d['email'])) {
        $errores['email'] = 'La dirección de correo no es válida.';
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
