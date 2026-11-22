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
//
//  Contrato: md/INTEGRACION-WEB.md §5.6. Límites: 30/min por clave
//  (compartido con api/v1/entrada); por visitante, 3 reservas por hora y 6
//  por día que ENTRARON (un intento que termina en horario tomado, feriado o
//  error no gasta cupo, y un reintento con la misma clave de idempotencia
//  tampoco: se contesta antes), más un tope amplio de 20 intentos por hora
//  para frenar a quien prueba horarios en masa. Módulo: Agenda. Tablas:
//  entradas_web aquí; agenda_reservas, contactos, cuentas y actividades las
//  escribe agenda_reservar().
// ============================================================

$entradaId = 0;
$reservaId = 0;
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

    // Los intentos cuentan todos; las reservas, sólo las que entran (se suman
    // abajo, después de confirmarla).
    api_limitar('ip:' . $ipHash . ':reserva_intentos:3600', 20, 3600);
    api_exigir_cupo('ip:' . $ipHash . ':reserva:3600', 3, 3600);
    api_exigir_cupo('ip:' . $ipHash . ':reserva:86400', 6, 86400);

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
            // Un feriado: la web muestra el mensaje (trae el nombre del
            // feriado) y vuelve a pedir los horarios, que ya no lo ofrecen.
            'feriado'                            => [409, 'feriado'],
            'sin_calendario'                     => [503, 'sin_calendario'],
            'ocupado'                            => [409, 'en_proceso'],
            // Topes por correo o del día (agenda_controlar_topes): la web
            // muestra el mensaje y ofrece el formulario de siempre.
            'limite'                             => [429, 'limite_reservas'],
            default                              => [422, 'datos_invalidos'],
        };
        $datos = ['ok' => false, 'error' => $error, 'mensaje' => $e->getMessage()]
               + $e->datos
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

    $reservaId = (int)$reserva['id'];
    $respuesta = api_respuesta_reserva($reserva, $tipo, $entradaId);
    // La reserva ya está confirmada (y la entrada enlazada, dentro de la
    // transacción de agenda_reservar). Si el proceso muere antes de esta
    // línea, o si esta escritura falla, la entrada queda 'procesando' con la
    // reserva enlazada y el reintento con la misma clave la repara y recibe
    // esta misma reserva (api_reparar_previa()). Por eso una falla acá no
    // cambia la respuesta: la reunión existe y la web tiene que saberlo.
    try {
        entrada_web_actualizar($entradaId, [
            'estado'         => 'ok',
            'error'          => null,
            // Quien atiende la reunión: el dueño del tipo o quien lo cubre ese día.
            'propietario_id' => (int)$reserva['usuario_id'],
            'contacto_id'    => $reserva['contacto_id'] ?? null,
            'cuenta_id'      => $reserva['cuenta_id'] ?? null,
            'actividad_id'   => $reserva['actividad_id'] ?? null,
            'reserva_id'     => $reservaId,
            'respuesta'      => api_respuesta_guardable(201, $respuesta),
        ]);
    } catch (\Throwable $e) {
        log_error('error.api', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    // Recién ahora gasta cupo del visitante: entró una reserva.
    api_limite_sumar('ip:' . $ipHash . ':reserva:3600', 3600);
    api_limite_sumar('ip:' . $ipHash . ':reserva:86400', 86400);

    // ---- Responder y seguir con lo lento ----
    api_responder_y_seguir(201, $respuesta);
    try {
        agenda_reserva_completar($reservaId);
    } catch (\Throwable $e) {
        // agenda_reserva_completar() no debería lanzar nada, pero si pasa la
        // respuesta ya salió: queda registrado y el cron la completa.
        log_error('error.api', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    // Fin explícito: la respuesta ya salió y no queda nada por hacer.
    exit;
} catch (\Throwable $e) {
    // Sólo si todavía no se creó la reserva: marcar 'error' una entrada con
    // reunión haría que el reintento la retome y choque con su propia reunión.
    if ($entradaId > 0 && $reservaId === 0) {
        try {
            entrada_web_actualizar($entradaId, ['estado' => 'error', 'error' => 'error_interno']);
        } catch (\Throwable $e2) {}
    }
    api_fallo($e);
}
