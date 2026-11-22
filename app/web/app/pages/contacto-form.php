<?php
declare(strict_types=1);

// ============================================================
//  Página `contacto-form`: alta (sin `id`) y edición (`?id=N`) de un contacto.
//
//  GET dibuja el formulario (views/contacto-form.php). POST valida, guarda con
//  contacto_guardar() y redirige a la ficha; si hay errores vuelve a dibujar
//  el formulario con lo que se mandó.
//
//  Tablas: escribe `contactos` (y `cuentas` cuando se da de alta una empresa
//  al vuelo); lee `cuentas` y `usuarios` para los selectores.
//  Módulo: núcleo (Contactos), no se puede apagar.
//
//  Permisos: alcanza con haber iniciado sesión. No se mira el propietario de
//  la ficha: cualquier usuario puede editar cualquier contacto y cambiarle el
//  dueño.
//
//  Trampa: la empresa nueva se crea ANTES de saber si el resto del formulario
//  pasa la validación. Si falla otro campo, la empresa ya quedó creada (y
//  aparece elegida al volver a dibujar el formulario).
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? contacto_get($id) : null;
// Un id que no existe o que está en la papelera (contacto_get filtra los
// eliminados) no se edita: se responde 404.
if ($id && !$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Contacto inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;

    if (trim((string)($d['apellido'] ?? '')) === '') {
        $errores['apellido'] = 'El apellido es obligatorio.';
    }
    foreach (['email' => 'Correo', 'email2' => 'Correo secundario', 'email3' => 'Correo terciario'] as $k => $et) {
        if (trim((string)($d[$k] ?? '')) !== '' && !email_valido($d[$k])) {
            $errores[$k] = "$et: la dirección no es válida.";
        }
    }

    // El teléfono se valida en el servidor: `type="tel"` solo cambia el teclado
    // del celular y deja escribir letras.
    foreach (['telefono' => 'Teléfono directo', 'movil' => 'Móvil', 'conmutador' => 'Conmutador'] as $k => $et) {
        if (trim((string)($d[$k] ?? '')) !== '' && !telefono_valido($d[$k])) {
            $errores[$k] = "$et: sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.";
        }
    }

    // Alta de empresa al vuelo: si escribieron un nombre nuevo, se crea la
    // cuenta en el momento en vez de obligar a salir del formulario.
    // cuenta_asegurar() reutiliza una empresa con el mismo nombre exacto, así
    // que escribir el nombre de una existente no la duplica.
    if (empty($d['cuenta_id']) && trim((string)($d['cuenta_nueva'] ?? '')) !== '') {
        $d['cuenta_id'] = cuenta_asegurar(trim((string)$d['cuenta_nueva']),
            ['propietario_id' => $d['propietario_id'] ?: null]);
    }

    // Lo que pedimos siempre. El `required` del formulario es una comodidad
    // para quien lo llena, no una garantía: no lo aplica nadie fuera de un
    // navegador. La regla es una sola y vive en `contacto_falta()`.
    foreach (contacto_falta($d) as $queFalta) {
        $campo = ['nombre' => 'nombre', 'apellido' => 'apellido',
                  'correo principal' => 'email', 'empresa' => 'cuenta_id'][$queFalta] ?? null;
        if ($campo) { $errores[$campo] = 'Hace falta para poder usar la ficha: ' . $queFalta . '.'; }
    }

    // No se controla que el correo no esté ya en otro contacto: la conversión
    // de posibles clientes y la entrada web buscan por correo y toman el
    // primero que encuentran.
    if (!$errores) {
        $nuevoId = contacto_guardar($d, $id ?: null);
        flash($id ? 'Contacto actualizado.' : 'Contacto creado.', 'success');
        redirect('contacto-ver?id=' . $nuevoId);
    }
    // Con errores se vuelve a dibujar con lo que se escribió encima de la
    // ficha original, para no obligar a cargarlo de nuevo.
    $c = array_merge($c ?? [], $d);
}

// El selector de empresa lleva sólo la vinculada (buscar_opcion_actual de
// crm.php); las demás se buscan al escribir (buscador.js).

render('contacto-form', [
    'c'        => $c,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
    'cuentas'  => buscar_opcion_actual('cuenta', $c['cuenta_id'] ?? null),
], $id ? 'Editar contacto' : 'Nuevo contacto');
