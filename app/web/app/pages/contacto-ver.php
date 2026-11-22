<?php
declare(strict_types=1);

// ============================================================
//  Página `contacto-ver?id=N`: la ficha de un contacto.
//
//  Junta todo lo que cuelga de la persona: notas, actividades, negocios,
//  envíos de campañas y la línea de tiempo de auditoría, y lo pasa a
//  views/contacto-ver.php.
//
//  Único POST: `accion=reactivar`, que vuelve a habilitar las campañas para
//  un correo dado de baja (sólo administradores; ver reactivar_suscripcion()
//  en campanas.php).
//
//  Tablas que lee: `contactos`, `notas`, `actividades`, `negocios`/`etapas`,
//  `envios`/`campanas`, `actividad` (auditoría).
//  Módulo: núcleo (Contactos). Las secciones de negocios, actividades,
//  campañas y adjuntos dependen de que su módulo esté encendido.
//  Permisos: cualquier usuario con sesión ve cualquier ficha.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? contacto_get($id) : null;
if (!$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Contacto inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// Volver a habilitar a alguien que se había dado de baja. La pantalla de baja
// le promete al destinatario que puede pedir el alta otra vez, así que tiene
// que haber una forma de hacerlo desde acá.
// Va por correo y no por contacto: la baja se registra por dirección, así que
// reactivar alcanza también a otras fichas con el mismo correo.
if (is_post() && ($_POST['accion'] ?? '') === 'reactivar') {
    verify_csrf();
    require_admin();
    $ok = reactivar_suscripcion((string)$c['email']);
    // reactivar_suscripcion() devuelve true sólo si había una baja registrada,
    // pero igual destilda «No incluirlo en campañas». Si la ficha tenía esa
    // marca (sin baja registrada), también cambió algo y se dice.
    $habiaMarca = !empty($c['no_email']);
    flash($ok
        ? 'Listo: ' . $c['email'] . ' vuelve a recibir campañas.'
        : ($habiaMarca
            ? 'Listo: se quitó la marca «No incluirlo en campañas». No había una baja pedida por la persona.'
            : 'No figuraba dado de baja, así que no hizo falta cambiar nada.'),
        $ok || $habiaMarca ? 'success' : 'info');
    redirect('contacto-ver?id=' . $id);
}

// Lo que no está en marcha no se muestra, y tampoco se consulta: tres
// consultas por ficha abierta para después no dibujar nada no le sirven a
// nadie, y la ficha del contacto es de las pantallas que más se abren.
$verNegocios    = ruta_habilitada('negocios');
$verActividades = ruta_habilitada('actividades');
$verCampanas    = ruta_habilitada('campanas');
$verAdjuntos    = ruta_habilitada('adjunto');

render('contacto-ver', [
    'c'           => $c,
    'notas'       => notas_de('contacto', $id),
    'actividades' => $verActividades ? actividades_de('contacto_id', $id) : [],
    'negocios'    => $verNegocios
        ? q('SELECT n.id, n.nombre, n.importe, n.moneda, n.fecha_cierre,
                    e.nombre AS etapa, e.tipo AS etapa_tipo, e.color
             FROM negocios n JOIN etapas e ON e.id = n.etapa_id
             WHERE n.contacto_id = ? AND n.eliminado_en IS NULL
             ORDER BY n.creado_en DESC', [$id])->fetchAll()
        : [],
    'envios'      => $verCampanas
        ? q('SELECT en.id, en.campana_id, en.estado, en.enviado_en, en.respondido_en, en.email, ca.nombre AS campana
             FROM envios en JOIN campanas ca ON ca.id = en.campana_id
             WHERE en.contacto_id = ? ORDER BY en.id DESC LIMIT 20', [$id])->fetchAll()
        : [],
    // Sólo la auditoría de la tabla `actividad` (altas, ediciones, actividades
    // agendadas y completadas), no las notas.
    'linea'       => timeline('contacto', $id),
    'verNegocios' => $verNegocios,
    'verActividades' => $verActividades,
    'verCampanas' => $verCampanas,
    'verAdjuntos' => $verAdjuntos,
], $c['nombre_completo']);
