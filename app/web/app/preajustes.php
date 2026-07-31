<?php
declare(strict_types=1);

// ============================================================
//  Plantillas prearmadas.
//
//  Cuatro puntos de partida, pensados para usos distintos. Se copian al
//  crear una plantilla nueva y desde ahí se editan libremente: no son
//  plantillas cerradas, son un borrador con el diseño ya resuelto.
//
//  La primera es la campaña de Pulsemail reproducida bloque por bloque, para
//  no perder el diseño que ya venía funcionando.
// ============================================================

function plantillas_preajustes(): array
{
    return [
        'pulsemail' => [
            'nombre'      => 'Outsourcing (la de Pulsemail)',
            'descripcion' => 'El diseño que ya usábamos en las campañas de correo masivo, '
                           . 'reproducido tal cual: barra bicolor, portada violeta, servicios y doble botón.',
            'asunto'      => 'Tecnología y personal para tu empresa | {{nombre}}',
            'ajustes'     => ['fondo' => '#eef0f4', 'tarjeta' => '#ffffff', 'marca' => '#5b2d91',
                              'acento' => '#8bc53f', 'texto' => '#4a4a55', 'titulo' => '#241a33',
                              'preencabezado' => 'Más de 15 años en outsourcing y selección de personal. Asesoría sin cargo.'],
            'bloques'     => [
                ['tipo' => 'encabezado', 'derecha' => '{{empresa_web}}', 'alto' => 42],
                ['tipo' => 'hero',
                 'volanta' => 'Outsourcing y selección de personal',
                 'titulo'  => 'El personal indicado para tu empresa, cuando lo necesitás.',
                 'bajada'  => 'Más de 15 años en outsourcing y selección de personal para empresas como la tuya.'],
                ['tipo' => 'texto', 'texto' => 'Hola {{nombre}},'],
                ['tipo' => 'texto',
                 'texto' => 'En {{remitente_empresa}} contamos con más de 15 años ayudando a las empresas a completar '
                          . 'sus equipos de trabajo. Nos ocupamos del outsourcing, la búsqueda y la selección de '
                          . 'personal: vos definís el perfil y nosotros te presentamos a los candidatos indicados.'],
                ['tipo' => 'lista', 'texto' =>
                    "Outsourcing de personal: tercerizá la búsqueda, selección y administración de tu equipo.\n"
                  . "Selección a medida: encontramos los recursos que encajan con tu operación.\n"
                  . "Más de 15 años de experiencia respaldando cada proceso."],
                ['tipo' => 'destacado',
                 'titulo' => 'Contactanos, te asesoramos sin cargo.',
                 'texto'  => 'Conversemos hoy mismo cómo podemos sumar a tu equipo.'],
                // Los dos botones de la campaña original: WhatsApp y reunión.
                ['tipo' => 'whatsapp', 'texto' => 'Contactanos por WhatsApp',
                 'mensaje' => 'Hola, me interesa conocer más sobre sus soluciones.',
                 'fondo' => '#25D366', 'color' => '#ffffff'],
                ['tipo' => 'agenda', 'texto' => 'Agendar reunión',
                 'fondo' => '#5b2d91', 'color' => '#ffffff'],
                ['tipo' => 'contacto'],
                ['tipo' => 'firma', 'cierre' => 'Saludos cordiales,', 'firma' => '{{remitente}}',
                 'cargo' => '{{remitente_empresa}}'],
            ],
        ],

        'sobria' => [
            'nombre'      => 'Presentación sobria',
            'descripcion' => 'Casi todo texto, un solo botón y sin imágenes. Es la que mejor llega a la bandeja '
                           . 'principal: los filtros premian el correo que parece escrito por una persona.',
            'asunto'      => '{{empresa}} · una consulta breve',
            'ajustes'     => ['fondo' => '#f6f5fa', 'tarjeta' => '#ffffff', 'marca' => '#663399',
                              'acento' => '#9CC930', 'texto' => '#3a3f4b', 'titulo' => '#161922',
                              'preencabezado' => 'Dos minutos de lectura, sin vueltas.'],
            'bloques'     => [
                ['tipo' => 'encabezado', 'alto' => 34],
                ['tipo' => 'espacio', 'alto' => 8],
                ['tipo' => 'texto', 'texto' => 'Hola {{nombre}},'],
                ['tipo' => 'texto',
                 'texto' => "Te escribo desde {{remitente_empresa}}. Vi que en {{empresa}} podrían estar necesitando "
                          . "resolver algo que hacemos todos los días, y quería presentarme sin ocuparte tiempo.\n\n"
                          . "Si te interesa, te propongo una charla de 20 minutos para entender tu situación y "
                          . "contarte si tiene sentido que trabajemos juntos. Si no, no te molesto más."],
                ['tipo' => 'agenda', 'texto' => 'Reservar 20 minutos',
                 'fondo' => '#663399', 'color' => '#ffffff', 'alineacion' => 'left'],
                ['tipo' => 'texto', 'texto' => 'Si preferís, respondeme este correo y coordinamos por acá.'],
                ['tipo' => 'firma', 'cierre' => 'Gracias por tu tiempo,', 'firma' => '{{remitente}}',
                 'cargo' => '{{remitente_empresa}}'],
            ],
        ],

        'novedad' => [
            'nombre'      => 'Novedad con imagen',
            'descripcion' => 'Para anunciar un servicio nuevo o un caso de éxito. Lleva una imagen destacada, '
                           . 'pero con suficiente texto alrededor para no desbalancear la proporción.',
            'asunto'      => 'Novedad para {{empresa}}',
            'ajustes'     => ['fondo' => '#f2f4f7', 'tarjeta' => '#ffffff', 'marca' => '#663399',
                              'acento' => '#9CC930', 'texto' => '#4a4a55', 'titulo' => '#241a33',
                              'preencabezado' => 'Te contamos algo nuevo en dos minutos.'],
            'bloques'     => [
                ['tipo' => 'encabezado', 'derecha' => '{{empresa_web}}', 'alto' => 40],
                ['tipo' => 'titulo', 'texto' => 'Una novedad que te puede servir', 'nivel' => 1],
                ['tipo' => 'texto', 'texto' => 'Hola {{nombre}},'],
                ['tipo' => 'texto',
                 'texto' => 'Queríamos contarte algo que venimos trabajando y que puede tener sentido para {{empresa}}. '
                          . 'Te lo resumimos en pocas líneas.'],
                ['tipo' => 'imagen', 'src' => '', 'alt' => 'Imagen de la novedad', 'ancho' => 512,
                 'alineacion' => 'center'],
                ['tipo' => 'lista', 'texto' =>
                    "Qué es y para qué sirve.\nEn qué se nota el cambio.\nCómo lo implementamos, paso a paso."],
                ['tipo' => 'separador'],
                ['tipo' => 'agenda', 'texto' => 'Quiero saber más',
                 'fondo' => '#663399', 'color' => '#ffffff'],
                ['tipo' => 'firma', 'cierre' => 'Quedamos a disposición,', 'firma' => '{{remitente}}',
                 'cargo' => '{{remitente_empresa}}'],
            ],
        ],

        'reunion' => [
            'nombre'      => 'Invitación a reunión',
            'descripcion' => 'Corta y directa al grano: una idea, un botón que abre tu agenda. '
                           . 'Es la que mejor funciona como segundo o tercer contacto.',
            'asunto'      => '¿Tenés 20 minutos esta semana, {{nombre}}?',
            'ajustes'     => ['fondo' => '#ffffff', 'tarjeta' => '#ffffff', 'marca' => '#663399',
                              'acento' => '#9CC930', 'texto' => '#3a3f4b', 'titulo' => '#161922',
                              'preencabezado' => 'Elegí el día y la hora que te queden cómodos.'],
            'bloques'     => [
                ['tipo' => 'encabezado', 'alto' => 32],
                ['tipo' => 'texto', 'texto' => 'Hola {{nombre}},'],
                ['tipo' => 'texto',
                 'texto' => 'Te escribo corto: me gustaría mostrarte en 20 minutos cómo ayudamos a empresas como '
                          . '{{empresa}}. Sin presentación larga ni compromiso.'],
                ['tipo' => 'destacado',
                 'titulo' => 'Elegí vos el día y la hora',
                 'texto'  => 'Se abre mi agenda con los horarios libres y queda confirmado al instante, con el enlace de la videollamada.'],
                ['tipo' => 'agenda', 'texto' => 'Ver horarios disponibles',
                 'fondo' => '#663399', 'color' => '#ffffff'],
                ['tipo' => 'texto', 'texto' => 'Si esta semana no te sirve, avisame y lo vemos más adelante.'],
                ['tipo' => 'firma', 'cierre' => 'Un saludo,', 'firma' => '{{remitente}}',
                 'cargo' => '{{remitente_empresa}}'],
            ],
        ],
    ];
}

/** Devuelve un preajuste listo para cargar en el editor. */
function preajuste_get(string $clave): ?array
{
    return plantillas_preajustes()[$clave] ?? null;
}
