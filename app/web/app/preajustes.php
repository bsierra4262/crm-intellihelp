<?php
declare(strict_types=1);

// ============================================================
//  Plantillas prearmadas.
//
//  Una por cada servicio que ofrece la empresa, más tres formatos genéricos
//  para cuando hay que escribir algo que no entra en ninguna. Se copian al
//  crear una plantilla nueva y desde ahí se editan libremente: no son
//  plantillas cerradas, son un borrador con el diseño ya resuelto.
//
//  TODAS las de servicio comparten exactamente la misma estructura y los
//  mismos colores —barra bicolor, portada violeta, lista de servicios, doble
//  botón—: lo único que cambia es el texto. Eso es lo que hace que las
//  campañas se reconozcan como de la misma empresa en vez de parecer ocho
//  correos de ocho lugares distintos.
//
//  La primera es la campaña de Pulsemail reproducida bloque por bloque, para
//  no perder el diseño que ya venía funcionando. Las demás salen de los
//  servicios publicados en intellihelp.tech.
// ============================================================

/**
 * Arma una plantilla de servicio con la estructura común.
 *
 * Existe para que las ocho plantillas salgan idénticas: escritas a mano, alguna
 * termina con el botón de otro color o sin el bloque de contacto.
 */
function preajuste_servicio(array $d): array
{
    return [
        'nombre'      => $d['nombre'],
        'descripcion' => $d['descripcion'],
        'asunto'      => $d['asunto'],
        'ajustes'     => ['fondo' => '#eef0f4', 'tarjeta' => '#ffffff', 'marca' => '#5b2d91',
                          'acento' => '#8bc53f', 'texto' => '#4a4a55', 'titulo' => '#241a33',
                          'preencabezado' => $d['preencabezado']],
        'bloques'     => [
            ['tipo' => 'encabezado', 'derecha' => '{{empresa_web}}', 'alto' => 42],
            ['tipo' => 'hero',
             'volanta' => $d['volanta'],
             'titulo'  => $d['titulo'],
             'bajada'  => $d['bajada']],
            ['tipo' => 'texto', 'texto' => 'Hola {{nombre}},'],
            ['tipo' => 'texto', 'texto' => $d['intro']],
            ['tipo' => 'lista', 'texto' => implode("\n", $d['puntos'])],
            ['tipo' => 'destacado',
             'titulo' => $d['cta_titulo'],
             'texto'  => $d['cta_texto']],
            ['tipo' => 'whatsapp', 'texto' => 'Escribinos por WhatsApp',
             'mensaje' => $d['whatsapp'],
             'fondo' => '#25D366', 'color' => '#ffffff'],
            ['tipo' => 'agenda', 'texto' => 'Agendar una reunión',
             'fondo' => '#5b2d91', 'color' => '#ffffff'],
            ['tipo' => 'contacto'],
            ['tipo' => 'firma', 'cierre' => 'Saludos cordiales,', 'firma' => '{{remitente}}',
             'cargo' => '{{remitente_empresa}}'],
        ],
    ];
}

function plantillas_preajustes(): array
{
    return [
        'pulsemail' => [
            'nombre'      => 'Outsourcing y selección de personal',
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

        // ---- Un correo por cada servicio de la empresa ----
        // Todos con la misma cara: cambia lo que se cuenta, no cómo se ve.

        'soporte_empresas' => preajuste_servicio([
            'nombre'      => 'Soporte técnico para empresas',
            'descripcion' => 'Para pymes que hoy resuelven la tecnología a los tirones. '
                           . 'Habla de soporte remoto y presencial, mantenimiento y redes.',
            'asunto'       => 'Que la tecnología deje de frenarte, {{nombre}}',
            'preencabezado'=> 'Soporte remoto y presencial, mantenimiento preventivo y redes. Asesoría sin cargo.',
            'volanta'      => 'Soporte técnico para empresas',
            'titulo'       => 'Tu empresa funcionando, sin sobresaltos.',
            'bajada'       => 'Soporte remoto y presencial, mantenimiento preventivo y gestión de redes.',
            'intro'        => 'En {{remitente_empresa}} nos ocupamos de toda la tecnología de tu empresa para que vos '
                            . 'te ocupes de tu negocio. Atendemos lo urgente cuando pasa, y sobre todo prevenimos lo '
                            . 'que puede pasar: la mayoría de las caídas se evitan antes de que alguien las note.',
            'puntos'       => [
                'Asistencia remota y presencial, con tiempos de respuesta acordados.',
                'Mantenimiento preventivo y correctivo de equipos y servidores.',
                'Instalación y configuración de las herramientas de trabajo del día a día.',
                'Gestión de redes y conexiones: que la conectividad no sea el cuello de botella.',
                'Equipamiento tecnológico a medida de lo que tu operación necesita.',
            ],
            'cta_titulo'   => 'Contactanos, te asesoramos sin cargo.',
            'cta_texto'    => 'Revisamos cómo está hoy tu infraestructura y te decimos qué conviene resolver primero.',
            'whatsapp'     => 'Hola, quiero consultar por el soporte técnico para empresas.',
        ]),

        'seguridad' => preajuste_servicio([
            'nombre'      => 'Seguridad electrónica y videovigilancia',
            'descripcion' => 'Cámaras IP, control de acceso, reconocimiento facial y patentes. '
                           . 'Para consorcios, industrias, countries y empresas con planta.',
            'asunto'       => 'Seguridad que ve lo que pasa | {{empresa}}',
            'preencabezado'=> 'Videovigilancia IP, control de acceso y reconocimiento facial con IA.',
            'volanta'      => 'Seguridad electrónica',
            'titulo'       => 'Saber qué pasa en tu empresa, en el momento en que pasa.',
            'bajada'       => 'Videovigilancia IP, control de acceso y reconocimiento con inteligencia artificial.',
            'intro'        => 'En {{remitente_empresa}} implementamos soluciones de seguridad electrónica que no sólo '
                            . 'graban: detectan, avisan y dejan registro. Trabajamos con equipamiento HIKVISION y '
                            . 'armamos cada proyecto según lo que hay que cuidar y quién tiene que entrar.',
            'puntos'       => [
                'Vigilancia por video IP, con acceso remoto desde el celular.',
                'Control de acceso biométrico y convencional, con registro de quién entró y cuándo.',
                'Reconocimiento facial y de patentes con inteligencia artificial.',
                'Parking inteligente y soluciones de portería para consorcios y barrios cerrados.',
                'Cercos perimetrales, tótems y detección por rayos X o de metales.',
            ],
            'cta_titulo'   => 'Te armamos el proyecto sin cargo.',
            'cta_texto'    => 'Vemos el lugar, entendemos qué hay que cuidar y te pasamos la propuesta.',
            'whatsapp'     => 'Hola, quiero consultar por soluciones de seguridad y cámaras.',
        ]),

        'redes' => preajuste_servicio([
            'nombre'      => 'Redes empresariales HUAWEI',
            'descripcion' => 'Redes cableadas, ópticas e inalámbricas, almacenamiento y nube. '
                           . 'Para empresas que crecieron y la red les quedó chica.',
            'asunto'       => 'Una red que aguante lo que viene | {{empresa}}',
            'preencabezado'=> 'Redes empresariales, ópticas e inalámbricas. Almacenamiento y HUAWEI Cloud.',
            'volanta'      => 'Redes e infraestructura',
            'titulo'       => 'La red no se nota hasta que falla.',
            'bajada'       => 'Redes empresariales, ópticas e inalámbricas, almacenamiento y nube.',
            'intro'        => 'En {{remitente_empresa}} diseñamos e implementamos redes empresariales con tecnología '
                            . 'HUAWEI. La mayoría de las empresas no tiene un problema de internet: tiene una red que '
                            . 'creció sin plan y hoy no da abasto. Eso se arregla, y no siempre es caro.',
            'puntos'       => [
                'Redes empresariales cableadas y ópticas, pensadas para crecer.',
                'Redes inalámbricas con cobertura pareja en toda la planta o la oficina.',
                'Almacenamiento de datos, con respaldo y recuperación probados.',
                'HUAWEI Cloud: la infraestructura que conviene tener afuera, afuera.',
            ],
            'cta_titulo'   => 'Relevamos tu red sin cargo.',
            'cta_texto'    => 'Te mostramos dónde está el cuello de botella y qué se puede hacer con lo que ya tenés.',
            'whatsapp'     => 'Hola, quiero consultar por redes empresariales.',
        ]),

        'ftth' => preajuste_servicio([
            'nombre'      => 'Redes de fibra óptica (FTTH)',
            'descripcion' => 'Fibra hasta el puesto de trabajo. Para edificios, barrios cerrados, '
                           . 'plantas industriales y proveedores de internet.',
            'asunto'       => 'Fibra óptica hasta el puesto de trabajo | {{empresa}}',
            'preencabezado'=> 'Más capacidad, más alcance y menos mantenimiento que el cableado tradicional.',
            'volanta'      => 'Redes FTTH',
            'titulo'       => 'Fibra óptica: más capacidad, menos problemas.',
            'bajada'       => 'Mayor alcance, inmunidad a interferencias y bajo costo de mantenimiento.',
            'intro'        => 'En {{remitente_empresa}} tendemos redes FTTH, fibra óptica hasta el punto final. '
                            . 'Donde el cableado tradicional se queda corto —por distancia, por interferencias o por '
                            . 'costo de mantener—, la fibra resuelve y queda instalada por años.',
            'puntos'       => [
                'Mayor capacidad, flexibilidad y alcance que el cableado de cobre.',
                'Inmunidad a interferencias electromagnéticas: rendimiento parejo aun en planta industrial.',
                'Mayor seguridad en el transporte de los datos.',
                'Bajos costos de mantenimiento a lo largo de la vida de la instalación.',
            ],
            'cta_titulo'   => 'Te hacemos el relevamiento sin cargo.',
            'cta_texto'    => 'Medimos distancias, vemos el tendido y te pasamos la propuesta con números.',
            'whatsapp'     => 'Hola, quiero consultar por una red de fibra óptica.',
        ]),

        'iot' => preajuste_servicio([
            'nombre'      => 'Internet de las cosas (IoT)',
            'descripcion' => 'Sensores, hardware conectado y tablero en la nube con indicadores en vivo. '
                           . 'Para operaciones que hoy se controlan a ojo o en papel.',
            'asunto'       => 'Medir lo que hoy se controla a ojo | {{empresa}}',
            'preencabezado'=> 'Sensores conectados y tablero en la nube con indicadores en tiempo real.',
            'volanta'      => 'Soluciones IoT',
            'titulo'       => 'Lo que no se mide, no se puede mejorar.',
            'bajada'       => 'Hardware y software conectados, con indicadores en tiempo real en la nube.',
            'intro'        => 'En {{remitente_empresa}} armamos soluciones de Internet de las Cosas: sensores que '
                            . 'miden lo que importa de tu operación y lo muestran en un tablero, en vivo. Temperatura, '
                            . 'consumo, presencia, stock, lo que haga falta seguir sin tener a alguien anotándolo.',
            'puntos'       => [
                'Solución integral de hardware y software, conectada a la nube.',
                'Tablero con indicadores en tiempo real, desde cualquier dispositivo.',
                'Distintos protocolos según el caso: WiFi, LoRa, LoRaWAN, Bluetooth o TCP/IP.',
                'Integración con asistentes virtuales: Alexa, Google Assistant, Siri y Cortana.',
            ],
            'cta_titulo'   => 'Charlemos qué se puede medir en tu operación.',
            'cta_texto'    => 'En una reunión corta identificamos qué conviene instrumentar primero.',
            'whatsapp'     => 'Hola, quiero consultar por soluciones IoT.',
        ]),

        'energia' => preajuste_servicio([
            'nombre'      => 'Energía solar y renovables',
            'descripcion' => 'Generación solar on grid, off grid e híbrida, y datacenter modular. '
                           . 'Habla del ahorro y del asesoramiento por los subsidios.',
            'asunto'       => 'Bajar la factura de luz de {{empresa}}',
            'preencabezado'=> 'Generación solar on grid, off grid e híbrida. Te asesoramos con los subsidios.',
            'volanta'      => 'Energías renovables',
            'titulo'       => 'Generar tu propia energía ya es más barato que comprarla.',
            'bajada'       => 'Soluciones solares on grid, off grid e híbridas, escalables según lo que necesites.',
            'intro'        => 'En {{remitente_empresa}} instalamos sistemas de generación de energía solar para '
                            . 'empresas. Se empieza por lo que la inversión permita y se amplía después: no hace falta '
                            . 'resolver todo el consumo de una vez para empezar a ver la diferencia en la factura.',
            'puntos'       => [
                'Generación solar autónoma: sistemas on grid, off grid e híbridos.',
                'Soluciones escalables: se arranca chico y se amplía cuando conviene.',
                'Fusion Module 5000: datacenter modular HUAWEI, eficiente y listo para instalar.',
                'Asesoramiento en los trámites de subsidios, que es donde todos se traban.',
            ],
            'cta_titulo'   => 'Te calculamos el ahorro sin cargo.',
            'cta_texto'    => 'Con una factura de luz tuya te decimos cuánto bajarías y en cuánto se recupera.',
            'whatsapp'     => 'Hola, quiero consultar por energía solar para mi empresa.',
        ]),

        'soporte_hogar' => preajuste_servicio([
            'nombre'      => 'Soporte técnico para personas',
            'descripcion' => 'La versión para usuarios particulares: PC lenta, fallas, wifi que no llega, '
                            . 'cámaras para la casa y venta de equipos.',
            'asunto'       => 'Que tu computadora vuelva a andar bien',
            'preencabezado'=> 'Instalación, optimización, resolución de fallas y cámaras para tu casa.',
            'volanta'      => 'Soporte técnico para personas',
            'titulo'       => 'Tu computadora, como el primer día.',
            'bajada'       => 'Instalación, optimización, resolución de fallas y equipamiento a tu medida.',
            'intro'        => 'En {{remitente_empresa}} también atendemos usuarios particulares. Una PC lenta casi '
                            . 'nunca es una PC vieja, y el wifi que no llega al fondo de la casa casi nunca es culpa '
                            . 'del proveedor. Lo miramos, te decimos qué pasa y lo resolvemos.',
            'puntos'       => [
                'Instalación y configuración de sistemas operativos.',
                'Optimización del rendimiento: que la máquina rinda lo que puede rendir.',
                'Diagnóstico y corrección de fallas en dispositivos y aplicaciones.',
                'Problemas de conexión y wifi que no llega a toda la casa.',
                'Cámaras de seguridad para el hogar y venta de equipos.',
            ],
            'cta_titulo'   => 'Escribinos y lo vemos.',
            'cta_texto'    => 'Contanos qué te está pasando y te decimos si se resuelve remoto o hay que ir.',
            'whatsapp'     => 'Hola, necesito soporte técnico para mi computadora.',
        ]),

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
