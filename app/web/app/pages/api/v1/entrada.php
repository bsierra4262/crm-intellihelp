<?php
declare(strict_types=1);

// ============================================================
//  POST api/v1/entrada — una consulta del formulario de la web, sin reunión.
//
//  La manda la web además del correo a info@, que sigue saliendo como
//  siempre. Acá queda lo que el correo no da: el contacto y la empresa en el
//  CRM, una nota con el mensaje, la tarea de contestar a nombre del
//  responsable de las consultas, la alerta, y de qué página y por qué canal
//  llegó la persona.
//
//  No depende de ningún módulo: contactos y empresas son del núcleo, y una
//  consulta tiene que entrar aunque la agenda esté apagada.
//
//  Contrato: md/INTEGRACION-WEB.md §5.7. Orden: firma → límite por clave
//  (30/min) → idempotencia y hash del visitante → validación → reintento con
//  la misma clave (se contesta lo de antes, sin consumir límites del
//  visitante) → límites por visitante (5/hora, 10/día) → duplicada o nueva
//  (con un bloqueo por correo para que dos envíos simultáneos no entren los dos).
//  Tablas: entradas_web (la fila del pedido); lo demás (contacto, empresa,
//  nota, tarea, alerta) lo crea entrada_web_consulta() en crm.php.
// ============================================================

$entradaId = 0;
try {
    api_contexto(['accion' => 'api/v1/entrada']);
    api_metodo(['POST']);
    $token = api_autenticar('escritura');
    api_limitar('k:' . (int)$token['id'] . ':escritura:60', 30, 60);
    $idem   = api_idempotencia();
    $ipHash = api_ip_visitante();

    $j = api_cuerpo_json();
    [$d, $errores] = api_validar_entrada($j, false);
    api_exigir_validos($errores);
    $cuerpoHash = api_cuerpo_hash($j);

    $previa = api_atender_repetido((int)$token['id'], $idem, $cuerpoHash);

    api_limitar('ip:' . $ipHash . ':entrada:3600', 5, 3600);
    api_limitar('ip:' . $ipHash . ':entrada:86400', 10, 86400);

    $fila = [
        'idempotencia' => $idem,
        'cuerpo_hash'  => $cuerpoHash,
        'token_id'     => (int)$token['id'],
        'tipo'         => 'consulta',
        'ip_hash'      => $ipHash,
    ] + $d;

    // Doble clic, botón «atrás» y volver a mandar: si en la última media hora
    // ya entró la misma consulta (mismo correo, servicio y mensaje), ésta queda
    // descartada. Se guarda igual, para que se vea en la pantalla de entradas
    // que llegó dos veces, pero no genera otra tarea ni otra nota.
    // La respuesta lleva el id de la consulta ORIGINAL, no el de esta fila.
    // Buscar la duplicada y abrir la entrada van con un bloqueo por correo:
    // sin él, dos envíos en el mismo instante no se veían entre sí y entraban
    // los dos (dos tareas). La duplicada cuenta también las 'procesando', así
    // que alcanza con que el bloqueo cubra hasta la inserción.
    $bloqueo = 'ew:' . substr(sha1((string)$d['email']), 0, 20);
    $bloqueado = api_bloquear($bloqueo);
    try {
        $duplicada = entrada_web_duplicada($d, (int)$token['id'], $idem);
        $entradaId = api_entrada_abrir($fila, $previa, $cuerpoHash);
        api_contexto(['entrada_id' => $entradaId]);
        if ($duplicada > 0) {
            $respuesta = ['ok' => true, 'repetida' => true, 'entrada_id' => $duplicada];
            entrada_web_actualizar($entradaId, [
                'estado'    => 'descartada',
                'error'     => 'duplicada de la entrada ' . $duplicada,
                'respuesta' => api_respuesta_guardable(200, $respuesta),
            ]);
        }
    } finally {
        if ($bloqueado) { api_soltar($bloqueo); }
    }
    if ($duplicada > 0) {
        api_responder(200, $respuesta);
    }

    entrada_web_consulta($entradaId, $d);

    $respuesta = ['ok' => true, 'repetida' => false, 'entrada_id' => $entradaId];
    entrada_web_actualizar($entradaId, ['respuesta' => api_respuesta_guardable(201, $respuesta)]);
    api_responder(201, $respuesta);
} catch (\Throwable $e) {
    // La entrada queda 'error': un reintento de la web con la misma clave la
    // retoma (api_entrada_abrir) en vez de recibir «en proceso» para siempre.
    if ($entradaId > 0) {
        try {
            entrada_web_actualizar($entradaId, ['estado' => 'error', 'error' => 'error_interno']);
        } catch (\Throwable $e2) {}
    }
    api_fallo($e);
}
