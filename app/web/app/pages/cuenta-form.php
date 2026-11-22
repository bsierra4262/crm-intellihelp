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
//  Valida también que tipo y sector sean de sus listas y que el nombre no
//  esté repetido con otra forma societaria (cuenta_repetida()).
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
    // Nombre repetido: es la causa número uno de bases duplicadas. Con la
    // misma regla que la entrada web (empresa_clave()): «ACME S.A.» y
    // «Acme SA» son la misma empresa. Con el nombre vacío no se busca, para
    // no pisar el «obligatorio» de arriba. En una edición sólo se mira si el
    // nombre cambió: si en la base ya había dos parecidas de antes, no se
    // puede impedir editar los otros datos de cualquiera de ellas.
    $nombreNuevo = (string)($d['nombre'] ?? '');
    $sinCambio   = $id && empresa_clave($nombreNuevo) === empresa_clave((string)($c['nombre'] ?? ''));
    $repetida    = $sinCambio ? null : cuenta_repetida($nombreNuevo, $id);
    if ($repetida) {
        $errores['nombre'] = 'Ya existe una empresa con ese nombre: «' . $repetida['nombre'] . '».';
    }

    // Tipo y sector tienen que ser de sus listas (el sector, de la del tipo).
    $tipo = trim((string)($d['tipo'] ?? ''));
    if ($tipo !== '' && !in_array($tipo, tipos_cuenta(), true)) {
        $errores['tipo'] = 'El tipo no es uno de la lista.';
    } elseif ($tipo !== '' && trim((string)($d['sector'] ?? '')) !== ''
              && !in_array($d['sector'], sectores_por_tipo()[$tipo], true)) {
        $errores['sector'] = 'El sector no corresponde a ese tipo de empresa.';
    }
    if (!empty($d['propietario_id']) && !qv('SELECT 1 FROM usuarios WHERE id = ?', [(int)$d['propietario_id']])) {
        $errores['propietario_id'] = 'El propietario elegido no existe.';
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
