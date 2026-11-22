<?php
declare(strict_types=1);

// ============================================================
//  Página `cuenta-form`: alta (sin `id`) y edición (`?id=N`) de una empresa.
//
//  GET dibuja views/cuenta-form.php. POST valida (nombre, CUIT con dígito
//  verificador, nombre no repetido, teléfono y los datos de cuenta_falta()),
//  guarda con cuenta_guardar() y redirige a la ficha.
//
//  Tablas: escribe `cuentas`; lee `cuentas` (repetidas) y `usuarios`.
//  Módulo: núcleo (Empresas).
//  Permisos: cualquier usuario con sesión edita cualquier empresa.
//
//  Ojo: cuenta_falta() acá funciona como validación obligatoria (CUIT, tipo
//  y sector son requisitos para guardar desde el formulario), aunque en el
//  resto del sistema —importación, alta al vuelo, web— la empresa entra
//  igual y sólo queda marcada como pendiente.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? cuenta_get($id) : null;
if ($id && !$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Empresa inexistente',
        'mensaje' => 'Puede que la hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;

    if (trim((string)($d['nombre'] ?? '')) === '') {
        $errores['nombre'] = 'El nombre de la empresa es obligatorio.';
    }
    if (trim((string)($d['cuit'] ?? '')) !== '' && !validar_cuit($d['cuit'])) {
        $errores['cuit'] = 'El CUIT no pasa la validación del dígito verificador.';
    }
    // Nombre repetido: es la causa número uno de bases duplicadas.
    // La comparación es por igualdad exacta (la intercalación de la base
    // ignora mayúsculas); «ACME S.A.» y «Acme SA» pasan como distintas. No es
    // la misma regla que empresa_clave(), la que usa la entrada web.
    // Además corre aunque el nombre esté vacío y pisa el mensaje de arriba.
    $repetida = q1('SELECT id FROM cuentas WHERE nombre = ? AND eliminado_en IS NULL AND id <> ? LIMIT 1',
                   [trim((string)$d['nombre']), $id]);
    if ($repetida) {
        $errores['nombre'] = 'Ya existe una empresa con ese nombre.';
    }


    // El teléfono se valida en el servidor: `type="tel"` solo cambia el teclado
    // del celular y deja escribir letras.
    foreach (['telefono' => 'Teléfono'] as $k => $et) {
        if (trim((string)($d[$k] ?? '')) !== '' && !telefono_valido($d[$k])) {
            $errores[$k] = "$et: sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.";
        }
    }

    // Lo que falta para que la ficha no quede pendiente; no pisa un error más
    // específico que ya tenga el campo (CUIT inválido, nombre repetido).
    foreach (cuenta_falta($d) as $queFalta) {
        $campo = ['nombre' => 'nombre', 'CUIT' => 'cuit',
                  'tipo' => 'tipo', 'sector' => 'sector'][$queFalta] ?? null;
        if ($campo && !isset($errores[$campo])) {
            $errores[$campo] = 'Hace falta para poder usar la ficha: ' . $queFalta . '.';
        }
    }

    if (!$errores) {
        $nuevoId = cuenta_guardar($d, $id ?: null);
        flash($id ? 'Empresa actualizada.' : 'Empresa creada.', 'success');
        redirect('cuenta-ver?id=' . $nuevoId);
    }
    // Con errores se vuelve a dibujar con lo escrito encima de la ficha.
    $c = array_merge($c ?? [], $d);
}

render('cuenta-form', [
    'c'        => $c,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
], $id ? 'Editar empresa' : 'Nueva empresa');
