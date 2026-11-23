<?php
declare(strict_types=1);

// ============================================================
//  GET api/v1/disponibilidad — los horarios libres de un tipo de reunión.
//
//  Lo pide la web (servidor a servidor) para dibujar el selector de días y
//  horarios en la landing. Son los mismos huecos que muestra la agenda pública:
//  horario laboral MENOS lo ocupado en el Google Calendar del vendedor MENOS lo
//  ya reservado en el CRM, con el margen entre reuniones.
//
//  Los feriados (nacionales y de la jurisdicción configurada) no tienen
//  horarios, y además vienen en `feriados` con la fecha y el nombre, para que
//  la web los muestre marcados en vez de como un día sin lugar.
//
//  Si no se pudo leer el calendario de Google no se publica nada (503): sin
//  ver el calendario no hay forma de saber qué está ocupado, y la web muestra
//  el formulario de siempre.
//
//  Contrato: md/INTEGRACION-WEB.md §5.5. Firma obligatoria (clave de
//  escritura), sin idempotencia ni hash de IP: un GET no cambia nada. Límite:
//  120 pedidos por minuto por clave; no hay límite por visitante porque el
//  GET no trae el hash de su IP (la web tiene su propia caché de 60 s).
//  Módulo: Agenda (503 modulo_apagado si está apagado).
//  Depende de agenda.php (agenda_tipo_publico, agenda_huecos_rango,
//  agenda_zona) y feriados.php (feriados_para_api). No escribe nada propio
//  salvo la auditoría y, de rebote, la caché de freeBusy y la sincronización
//  de feriados si tocaba.
// ============================================================

try {
    api_contexto(['accion' => 'api/v1/disponibilidad']);
    api_metodo(['GET']);
    $token = api_autenticar('escritura');
    api_limitar('k:' . (int)$token['id'] . ':disponibilidad:60', 120, 60);

    if (!modulo_activo('agenda')) {
        api_error(503, 'modulo_apagado', 'La agenda no está disponible en este momento.');
    }

    // ---- Parámetros ----
    $slug     = (string)($_GET['tipo'] ?? '');
    $vendedor = (string)($_GET['vendedor'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) {
        api_error(400, 'parametros_invalidos', 'Falta el tipo de reunión.', ['campos' => ['tipo' => 'Formato inválido.']]);
    }
    if ($vendedor !== '' && !preg_match('/^[a-z0-9._-]{1,64}$/', $vendedor)) {
        api_error(400, 'parametros_invalidos', 'El vendedor no es válido.', ['campos' => ['vendedor' => 'Formato inválido.']]);
    }

    // Fecha AAAA-MM-DD real (el ida y vuelta descarta un 2026-02-31 que PHP
    // correría al 3 de marzo). El rango se lee en la zona del servidor; la
    // ventana del tipo (antelación, ventana_dias) la recorta agenda_huecos_rango().
    $fecha = static function (string $v): ?DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return null; }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $d : null;
    };
    $desdeTxt = (string)($_GET['desde'] ?? '');
    $hastaTxt = (string)($_GET['hasta'] ?? '');
    $desde = $desdeTxt === '' ? new DateTimeImmutable('today') : $fecha($desdeTxt);
    if (!$desde) {
        api_error(400, 'parametros_invalidos', 'La fecha de inicio no es válida.', ['campos' => ['desde' => 'Usá AAAA-MM-DD.']]);
    }
    $hasta = $hastaTxt === '' ? $desde->modify('+14 days') : $fecha($hastaTxt);
    if (!$hasta) {
        api_error(400, 'parametros_invalidos', 'La fecha de fin no es válida.', ['campos' => ['hasta' => 'Usá AAAA-MM-DD.']]);
    }
    if ($hasta < $desde) {
        api_error(400, 'parametros_invalidos', 'El rango de fechas está invertido.', ['campos' => ['hasta' => 'Tiene que ser posterior a desde.']]);
    }
    // Hasta 31 días por pedido (los dos extremos incluidos). Cada pedido
    // consulta Google: un rango sin tope sería una forma fácil de gastarle la
    // cuota al vendedor.
    if ($hasta > $desde->modify('+30 days')) { $hasta = $desde->modify('+30 days'); }

    // ---- Tipo de reunión ----
    $motivo = null;
    $tipo = agenda_tipo_publico($slug, $vendedor !== '' ? $vendedor : null, $motivo);
    if (!$tipo) {
        if ($motivo === 'ambiguo') {
            api_error(409, 'tipo_ambiguo', 'Hay más de un vendedor con ese tipo de reunión: falta indicar cuál.');
        }
        api_error(404, 'tipo_inexistente', 'No hay un tipo de reunión activo con ese nombre.');
    }

    // ---- Huecos ----
    // Con la caché de freeBusy (AGENDA_CACHE_HORARIOS): lo que se muestra
    // puede tener hasta ese atraso; la reserva vuelve a mirar Google sin caché.
    $motivo = null;
    $huecos = agenda_huecos_rango($tipo, $desde->format('Y-m-d'), $hasta->format('Y-m-d'), $motivo, null,
                                  ['cache' => AGENDA_CACHE_HORARIOS]);
    if ($motivo === 'sin_calendario') {
        api_error(503, 'sin_calendario', 'No pudimos consultar el calendario en este momento.');
    }

    $zona = function_exists('agenda_zona') ? agenda_zona($tipo)
          : new DateTimeZone(date_default_timezone_get());
    $iso  = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($zona)->format('c');

    $dias = [];
    foreach ($huecos as $dia => $inicios) {
        $horarios = [];
        foreach ($inicios as $ts) {
            $horarios[] = ['inicio' => $iso((int)$ts), 'ts' => (int)$ts];
        }
        if ($horarios) { $dias[] = ['fecha' => (string)$dia, 'horarios' => $horarios]; }
    }

    api_responder(200, [
        'ok'    => true,
        'zona'  => $zona->getName(),
        'tipo'  => [
            'slug'         => (string)$tipo['slug'],
            'nombre'       => (string)$tipo['nombre'],
            'duracion_min' => (int)$tipo['duracion_min'],
            'con_meet'     => (bool)$tipo['con_meet'],
            'vendedor'     => (string)($tipo['usuario'] ?? ''),
        ],
        'desde'    => $desde->format('Y-m-d'),
        'hasta'    => $hasta->format('Y-m-d'),
        'dias'     => $dias,
        // Los feriados del rango pedido, con o sin horario laboral ese día:
        // [{fecha, nombre, tipo, nacional, jurisdiccion}]. Nunca traen horarios.
        'feriados' => feriados_para_api($desde->format('Y-m-d'), $hasta->format('Y-m-d')),
        'generado' => (new DateTimeImmutable('now', $zona))->format('c'),
    ]);
} catch (\Throwable $e) {
    // api_error()/api_responder() terminan con exit, que no lanza: acá llega
    // sólo lo inesperado, y se contesta 500 en JSON (o 503 si falta migrar).
    api_fallo($e);
}
