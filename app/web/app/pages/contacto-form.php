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
//  La empresa nueva («crear una empresa nueva») se crea recién cuando todo el
//  formulario pasó la validación: si falla otro campo no queda una empresa
//  suelta. Un alta con `?cuenta_id=N` (botón «Nuevo contacto» de la ficha de
//  una empresa) llega con esa empresa elegida.
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

// Alta desde la ficha de una empresa: llega elegida.
if (!$id && (int)($_GET['cuenta_id'] ?? 0) > 0) {
    $c = ['cuenta_id' => (int)$_GET['cuenta_id']];
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

    // Los valores de lista se controlan acá: el navegador manda lo que quiera.
    if (trim((string)($d['fuente'] ?? '')) !== '' && !in_array($d['fuente'], fuentes(), true)) {
        $errores['fuente'] = 'La fuente no es una de la lista.';
    }
    // Un id que no existe hacía fallar el guardado (clave foránea) con un 500.
    // Se mira que exista, aunque esté en la papelera: un vínculo viejo no se rechaza.
    if (!empty($d['cuenta_id']) && !qv('SELECT 1 FROM cuentas WHERE id = ?', [(int)$d['cuenta_id']])) {
        $errores['cuenta_id'] = 'La empresa elegida ya no existe.';
    }
    if (!empty($d['propietario_id']) && !qv('SELECT 1 FROM usuarios WHERE id = ?', [(int)$d['propietario_id']])) {
        $errores['propietario_id'] = 'El propietario elegido no existe.';
    }

    // Alta de empresa al vuelo: si escribieron un nombre nuevo, la empresa se
    // crea al guardar, en vez de obligar a salir del formulario. Para validar
    // alcanza con saber que va a haber una; se crea más abajo, sólo si todo
    // lo demás está bien.
    $cuentaNueva = empty($d['cuenta_id']) ? trim((string)($d['cuenta_nueva'] ?? '')) : '';

    // Lo que pedimos siempre. El `required` del formulario es una comodidad
    // para quien lo llena, no una garantía: no lo aplica nadie fuera de un
    // navegador. La regla es una sola y vive en `contacto_falta()`.
    foreach (contacto_falta($d) as $queFalta) {
        if ($queFalta === 'empresa' && $cuentaNueva !== '') { continue; }
        $campo = ['nombre' => 'nombre', 'apellido' => 'apellido',
                  'correo principal' => 'email', 'empresa' => 'cuenta_id'][$queFalta] ?? null;
        if ($campo) { $errores[$campo] = 'Hace falta para poder usar la ficha: ' . $queFalta . '.'; }
    }

    // No se controla que el correo no esté ya en otro contacto: la conversión
    // de posibles clientes y la entrada web buscan por correo y toman el
    // primero que encuentran.
    if (!$errores) {
        // cuenta_asegurar() reutiliza una empresa con el mismo nombre exacto,
        // así que escribir el nombre de una existente no la duplica.
        if ($cuentaNueva !== '') {
            $d['cuenta_id'] = cuenta_asegurar(mb_substr($cuentaNueva, 0, 200),
                ['propietario_id' => ($d['propietario_id'] ?? null) ?: null]);
        }
        $nuevoId = contacto_guardar($d, $id ?: null);
        flash($id ? 'Contacto actualizado.' : 'Contacto creado.', 'success');
        redirect('contacto-ver?id=' . $nuevoId);
    }
    // Con errores se vuelve a dibujar con lo que se escribió encima de la
    // ficha original, para no obligar a cargarlo de nuevo.
    $c = array_merge($c ?? [], $d);
}

// El selector de empresas trae hasta 3000: una base más grande deja afuera
// las últimas en orden alfabético.

render('contacto-form', [
    'c'        => $c,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
    'cuentas'  => q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY nombre LIMIT 3000')->fetchAll(),
], $id ? 'Editar contacto' : 'Nuevo contacto');
