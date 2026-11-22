<?php
declare(strict_types=1);

// ============================================================
//  Qué partes del sistema están en marcha.
//
//  El CRM está en uso mientras se terminan algunos módulos: la carga de
//  contactos y empresas ya funciona aunque las campañas sigan en desarrollo.
//  Lo que hay que evitar es que alguien entre a una pantalla a medio hacer
//  creyendo que está lista, cargue algo ahí y después no aparezca donde
//  esperaba.
//
//  Así que en producción se elige, una por una, qué partes están habilitadas.
//  En prueba está todo: es el lugar donde se termina lo que falta.
//
//  El núcleo no se puede apagar. Sin Contactos, Empresas o Ajustes no queda
//  sistema, y dejar la opción de apagarlos sólo sirve para que alguien se
//  encierre afuera.
//
//  Dónde se usa:
//    · index.php llama a ruta_habilitada() antes de cada controlador: una
//      ruta de un módulo apagado responde 404 «todavía no está en marcha»;
//    · layout.php esconde del menú lo que no está habilitado;
//    · el panel y otras pantallas no consultan lo que está apagado.
//  El estado de cada módulo es el ajuste `modulo_<clave>` ('1' encendido) de
//  la tabla `app_settings`, que se cambia desde Ajustes → Qué está en marcha.
//
//  Al crear una pantalla nueva hay que decidir a qué módulo pertenece y
//  agregar su ruta acá; si no figura en ningún módulo, queda en el núcleo y
//  siempre habilitada.
//
//  Las rutas compartidas del núcleo (registro-eliminar, nota-guardar,
//  etiquetas-guardar, adjunto, buscar) no están atadas a ningún módulo:
//  controlan adentro el módulo del registro sobre el que actúan
//  (entidad_habilitada() en registros.php, ruta_habilitada() en buscar).
// ============================================================

/**
 * Los módulos que se pueden encender y apagar.
 *
 * @return array<string,array{nombre:string,ayuda:string,rutas:string[]}>
 *         clave del módulo => nombre y ayuda para Ajustes, y sus rutas
 *
 * Cada uno lista TODAS sus rutas, incluidas las hijas: si se apaga Campañas y
 * la ruta de Plantillas queda abierta, alcanza con escribir la dirección para
 * entrar a una pantalla que se dio por no disponible.
 */
function modulos(): array
{
    // Importar NO está en esta lista a propósito. Cargar contactos y empresas
    // desde un archivo es el trabajo de todos los días —es LO PRIMERO que hace
    // falta al empezar a usar el sistema—, así que va con el núcleo y está
    // siempre. Lo que sí depende de las campañas es poder importar correos
    // sueltos a una lista, y eso se decide adentro de la pantalla.
    return [
        'posibles' => [
            'nombre' => 'Posibles clientes',
            'ayuda'  => 'La bandeja de los que todavía no son clientes, con su conversión a contacto.',
            'rutas'  => ['posibles', 'posible-form', 'posible-ver', 'posible-convertir'],
        ],
        'negocios' => [
            'nombre' => 'Negocios y embudo',
            'ayuda'  => 'El seguimiento de las oportunidades por etapa, con su pronóstico.',
            'rutas'  => ['negocios', 'negocio-form', 'negocio-ver', 'embudo', 'negocio-etapa',
                         'embudos', 'cuotas'],
        ],
        'actividades' => [
            'nombre' => 'Actividades',
            'ayuda'  => 'Tareas, llamadas y reuniones para no perder de vista lo que hay que hacer.',
            'rutas'  => ['actividades', 'actividad-form', 'actividad-completar'],
        ],
        'campanas' => [
            'nombre' => 'Campañas de correo',
            'ayuda'  => 'El envío masivo con sus plantillas, listas, casillas y seguimiento de respuestas.',
            'rutas'  => ['campanas', 'campana-form', 'campana-ver', 'campana-accion',
                         'plantillas', 'plantilla-form', 'plantilla-vista',
                         'listas', 'lista-form', 'lista-ver',
                         'cuentas-correo', 'imagenes', 'conversacion', 'api-campana'],
        ],
        'agenda' => [
            'nombre' => 'Agenda de reuniones',
            'ayuda'  => 'El enlace público para que reserven una reunión, con Google Calendar y Meet, '
                      . 'y las reservas que llegan desde la web.',
            'rutas'  => ['agenda', 'agenda-form', 'reservas', 'reserva-accion', 'reserva-mover',
                         'google-desconectar', 'feriados'],
        ],
        'adjuntos' => [
            'nombre' => 'Archivos adjuntos',
            'ayuda'  => 'Subir documentos a la ficha de un contacto o una empresa: contratos, remitos, presupuestos.',
            'rutas'  => ['adjunto', 'adjunto-subir'],
        ],
        'reportes' => [
            'nombre' => 'Reportes',
            'ayuda'  => 'Los números del mes: qué entró, qué se cerró y por dónde se cae.',
            'rutas'  => ['reportes'],
        ],
    ];
}

/**
 * ¿Está en marcha este módulo?
 *
 * Una clave que no está en modulos() se considera núcleo: siempre encendida.
 * En producción, un módulo que nunca se configuró está apagado (valor por
 * defecto '0').
 *
 * En prueba está todo encendido siempre: es el lugar donde se termina lo que
 * falta, y tener que ir a habilitarlo para poder probarlo no tendría sentido.
 */
function modulo_activo(string $clave): bool
{
    if (!isset(modulos()[$clave])) { return true; }   // el núcleo no se apaga
    if (es_testing())              { return true; }
    return setting_get('modulo_' . $clave, '0') === '1';
}

/** El módulo al que pertenece una ruta, o null si es del núcleo. */
function modulo_de_ruta(string $ruta): ?string
{
    foreach (modulos() as $clave => $m) {
        if (in_array($ruta, $m['rutas'], true)) { return $clave; }
    }
    return null;
}

/**
 * ¿Se puede entrar a esta ruta?
 *
 * @param string $ruta la ruta base (la clave de $rutas en index.php)
 *
 * Se comprueba en el enrutador y no sólo escondiendo la solapa: esconder un
 * enlace no impide entrar escribiendo la dirección, y quien lo haga llegaría a
 * una pantalla que el sistema da por no disponible.
 */
function ruta_habilitada(string $ruta): bool
{
    // Las alertas no son un módulo propio: avisan de lo que producen las
    // campañas y la agenda (una respuesta, una reunión, un rebote). Con los dos
    // apagados, la sección no se habilita.
    if ($ruta === 'alertas' || $ruta === 'api-alertas') {
        return modulo_activo('campanas') || modulo_activo('agenda');
    }

    $m = modulo_de_ruta($ruta);
    return $m === null || modulo_activo($m);
}
