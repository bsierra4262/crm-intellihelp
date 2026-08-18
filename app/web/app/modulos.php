<?php
declare(strict_types=1);

// ============================================================
//  Qué partes del sistema están en marcha.
//
//  El CRM se usa de verdad mucho antes de estar terminado, y eso está bien: la
//  carga de contactos y empresas ya sirve aunque las campañas todavía se estén
//  puliendo. Lo que NO sirve es que alguien entre a una pantalla a medio hacer
//  creyendo que está lista, cargue algo ahí y después no aparezca donde
//  esperaba.
//
//  Así que en producción se elige, una por una, qué partes están habilitadas.
//  En prueba está todo: es el lugar donde se termina lo que falta.
//
//  El núcleo no se puede apagar. Sin Contactos, Empresas o Ajustes no queda
//  sistema, y dejar la opción de apagarlos sólo sirve para que alguien se
//  encierre afuera.
// ============================================================

/**
 * Los módulos que se pueden encender y apagar.
 *
 * Cada uno lista TODAS sus rutas, incluidas las hijas: si se apaga Campañas y
 * la ruta de Plantillas queda abierta, alcanza con escribir la dirección para
 * entrar a una pantalla que se dio por no disponible.
 */
function modulos(): array
{
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
            'ayuda'  => 'El enlace público para que reserven una reunión, con Google Calendar y Meet.',
            'rutas'  => ['agenda', 'agenda-form', 'reservas', 'reserva-accion', 'reserva-mover',
                         'google-desconectar'],
        ],
        'reportes' => [
            'nombre' => 'Reportes',
            'ayuda'  => 'Los números del mes: qué entró, qué se cerró y por dónde se cae.',
            'rutas'  => ['reportes'],
        ],
        'importar' => [
            'nombre' => 'Importar desde archivo',
            'ayuda'  => 'La carga masiva de contactos y empresas desde un CSV.',
            'rutas'  => ['importar'],
        ],
    ];
}

/**
 * ¿Está en marcha este módulo?
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
 * Se comprueba en el enrutador y no sólo escondiendo la solapa: esconder un
 * enlace no impide entrar escribiendo la dirección, y quien lo haga llegaría a
 * una pantalla que el sistema da por no disponible.
 */
function ruta_habilitada(string $ruta): bool
{
    $m = modulo_de_ruta($ruta);
    return $m === null || modulo_activo($m);
}
