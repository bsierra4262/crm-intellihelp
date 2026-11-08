<?php
declare(strict_types=1);

// ============================================================
//  POST api/v1/reserva — una reunión reservada desde la landing de la web.
//
//  Orden de las cosas, y por qué:
//    1. firma, límites y validación: lo barato primero;
//    2. la fila de `entradas_web` con la clave de idempotencia, ANTES de tocar
//       la agenda: si la web reintenta, el segundo pedido encuentra el primero
//       y no se toma otro horario;
//    3. agenda_reservar(): bloquea el calendario del vendedor, comprueba que el
//       hueco siga libre y guarda reserva, contacto, empresa y reunión. Es todo
//       base de datos, menos de un segundo;
//    4. se responde a la web y, con la conexión ya cerrada, se crea el evento de
//       Google con Meet y se manda el correo (agenda_reserva_completar()). Si el
//       proceso se corta ahí, el cron completa lo que haya quedado pendiente.
//
//  La respuesta NO trae el token de la reserva, el Meet ni los enlaces para
//  cambiar el horario o cancelar: eso le llega sólo a quien reservó, por
//  correo, que así demuestra que la dirección es suya.
// ============================================================

$entradaId = 0;
try {
    api_contexto(['accion' => 'api/v1/reserva']);
    api_metodo(['POST']);
    $token = api_autenticar('escritura');
    api_limitar('k:' . (int)$token['id'] . ':escritura:60', 30, 60);
    $idem   = api_idempotencia();
    $ipHash = api_ip_visitante();

    $j = api_cuerpo_json();
    [$d, $errores] = api_validar_entrada($j, true);
    api_exigir_validos($errores);
    $cuerpoHash = api_cuerpo_hash($j);

    $previa = api_atender_repetido((int)$token['id'], $idem, $cuerpoHash);

    api_limitar('ip:' . $ipHash . ':reserva:3600', 3, 3600);
    api_limitar('ip:' . $ipHash . ':reserva:86400', 6, 86400);

    if (!modulo_activo('agenda')) {
        api_error(503, 'modulo_apagado', 'La agenda no está disponible en este momento.');
    }
    $motivo = null;
    $tipo = agenda_tipo_publico($d['tipo'], $d['vendedor'] !== '' ? $d['vendedor'] : null, $motivo);
    if (!$tipo) {
        if ($motivo === 'ambiguo') {
            api_error(409, 'tipo_ambiguo', 'Hay más de un vendedor con ese tipo de reunión: falta indicar cuál.');
        }
        api_error(404, 'tipo_inexistente', 'No hay un tipo de reunión activo con ese nombre.');
    }

    // ---- La entrada, con la idempotencia ----
    // Las claves propias van primero: en $d, 'tipo' es el tipo de reunión y en
    // la entrada es «consulta» o «reserva».
    $entradaId = api_entrada_abrir([
        'idempotencia'    => $idem,
        'cuerpo_hash'     => $cuerpoHash,
        'token_id'        => (int)$token['id'],
        'tipo'            => 'reserva',
        'ip_hash'         => $ipHash,
        'tipo_reunion_id' => (int)$tipo['id'],
        'inicio_pedido'   => (int)$d['inicio'],
    ] + $d, $previa, $cuerpoHash);
    api_contexto(['entrada_id' => $entradaId]);

    // ---- La reserva ----
    try {
        $reserva = agenda_reservar($tipo, [
            'inicio'     => (int)$d['inicio'],
            'nombre'     => $d['nombre'],
            'email'      => $d['email'],
            'empresa'    => $d['empresa'],
            'telefono'   => $d['telefono'],
            'servicio'   => $d['servicio'],
            'comentario' => $d['mensaje'],
        ], [
            'origen'                 => 'web',
            'validar_hueco'          => true,
            'consentimiento_en'      => $d['consentimiento_en'],
            'consentimiento_version' => $d['consentimiento_version'],
            'novedades'              => $d['novedades'],
            'descripcion_extra'      => entrada_web_resumen($d['origen']),
            'entrada_id'             => $entradaId,
            'pagina'                 => $d['origen']['pagina'],
        ]);
    } catch (AgendaExcepcion $e) {
        [$codigo, $error] = match ($e->motivo) {
            'horario_tomado', 'horario_invalido' => [409, 'horario_tomado'],
            'sin_calendario'                     => [503, 'sin_calendario'],
            'ocupado'                            => [409, 'en_proceso'],
            // Topes por correo o del día (agenda_controlar_topes): la web
            // muestra el mensaje y ofrece el formulario de siempre.
            'limite'                             => [429, 'limite_reservas'],
            default                              => [422, 'datos_invalidos'],
        };
        $datos = ['ok' => false, 'error' => $error, 'mensaje' => $e->getMessage()]
               + (in_array($error, ['datos_invalidos', 'limite_reservas'], true) && $e->campo
                  ? ['campos' => [$e->campo => $e->getMessage()]] : []);
        // Queda el motivo para la pantalla de entradas. Con la misma clave la
        // web puede volver a intentar con otro horario: la entrada se retoma.
        entrada_web_actualizar($entradaId, [
            'estado'    => 'error',
            'error'     => $e->motivo . ($e->campo ? ':' . $e->campo : ''),
            'respuesta' => api_respuesta_guardable($codigo, $datos),
        ]);
        api_responder($codigo, $datos);
    }

    $zona = function_exists('agenda_zona') ? agenda_zona($tipo)
          : new DateTimeZone(date_default_timezone_get());
    $iso  = static fn(string $f): string => (new DateTimeImmutable($f))->setTimezone($zona)->format('c');

    $respuesta = [
        'ok'         => true,
        'repetida'   => false,
        'entrada_id' => $entradaId,
        'reserva'    => [
            'id'           => (int)$reserva['id'],
            'inicio'       => $iso((string)$reserva['inicia_el']),
            'fin'          => $iso((string)$reserva['termina_el']),
            'duracion_min' => (int)$tipo['duracion_min'],
            'estado'       => (string)$reserva['estado'],
        ],
        'mensaje'    => 'Reunión confirmada. Te mandamos un mail con el enlace de la videollamada.',
    ];
    entrada_web_actualizar($entradaId, [
        'estado'         => 'ok',
        'error'          => null,
        'propietario_id' => (int)$tipo['usuario_id'],
        'contacto_id'    => $reserva['contacto_id'] ?? null,
        'cuenta_id'      => $reserva['cuenta_id'] ?? null,
        'actividad_id'   => $reserva['actividad_id'] ?? null,
        'reserva_id'     => (int)$reserva['id'],
        'respuesta'      => api_respuesta_guardable(201, $respuesta),
    ]);

    // ---- Responder y seguir con lo lento ----
    api_responder_y_seguir(201, $respuesta);
    try {
        agenda_reserva_completar((int)$reserva['id']);
    } catch (\Throwable $e) {
        // agenda_reserva_completar() no debería lanzar nada, pero si pasa la
        // respuesta ya salió: queda registrado y el cron la completa.
        log_error('error.api', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    exit;
} catch (\Throwable $e) {
    if ($entradaId > 0) {
        try {
            entrada_web_actualizar($entradaId, ['estado' => 'error', 'error' => 'error_interno']);
        } catch (\Throwable $e2) {}
    }
    api_fallo($e);
}
