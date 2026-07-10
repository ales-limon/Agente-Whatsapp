<?php
// Avisos por WhatsApp al dueño del negocio (vía Twilio).
// Si faltan credenciales o el número de avisos, no hace nada (no rompe el agendado).

require_once __DIR__ . '/../config/seguridad.php';

function normalizar_para_wa(string $n): string {
    return trim(str_replace(['whatsapp:', ' '], '', $n));
}

function enviar_whatsapp(string $para, string $mensaje, string $desde): array {
    cargar_entorno();
    $sid   = (string) env('TWILIO_ACCOUNT_SID', '');
    $token = (string) env('TWILIO_AUTH_TOKEN', '');
    $para  = normalizar_para_wa($para);
    $desde = normalizar_para_wa($desde);

    if ($sid === '' || $token === '' || $para === '' || $desde === '') {
        error_log('enviar_whatsapp: faltan credenciales de Twilio o números (aviso omitido).');
        return ['exito' => false, 'mensaje' => 'Configuración de Twilio incompleta'];
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => "$sid:$token",
        CURLOPT_POSTFIELDS     => http_build_query([
            'From' => 'whatsapp:' . $desde,
            'To'   => 'whatsapp:' . $para,
            'Body' => $mensaje,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp   = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $codigo >= 400) {
        error_log("enviar_whatsapp error ($codigo): " . (string)$resp);
        return ['exito' => false];
    }
    return ['exito' => true];
}

// Envía un mensaje usando una PLANTILLA aprobada de WhatsApp (Content SID + variables).
// Necesario para mensajes proactivos al dueño fuera de la ventana de 24h.
function enviar_whatsapp_plantilla(string $para, string $desde, string $contentSid, array $vars): array {
    cargar_entorno();
    $sid   = (string) env('TWILIO_ACCOUNT_SID', '');
    $token = (string) env('TWILIO_AUTH_TOKEN', '');
    $para  = normalizar_para_wa($para);
    $desde = normalizar_para_wa($desde);

    if ($sid === '' || $token === '' || $para === '' || $desde === '' || $contentSid === '') {
        return ['exito' => false, 'mensaje' => 'Faltan credenciales, números o ContentSid'];
    }

    $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => "$sid:$token",
        CURLOPT_POSTFIELDS     => http_build_query([
            'From'             => 'whatsapp:' . $desde,
            'To'               => 'whatsapp:' . $para,
            'ContentSid'       => $contentSid,
            'ContentVariables' => json_encode($vars, JSON_UNESCAPED_UNICODE),
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp   = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $codigo >= 400) {
        error_log("enviar_whatsapp_plantilla error ($codigo): " . (string)$resp);
        return ['exito' => false];
    }
    return ['exito' => true];
}

// Avisa al dueño que un cliente pidió atención humana (escalación). $c = conocimiento.
function avisar_escalacion(array $c, string $contacto, string $motivo = ''): void {
    $para  = trim((string)($c['numero_avisos'] ?? ''));
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    if ($para === '' || $desde === '') return;

    $mensaje = "Atencion requerida en {$c['negocio']}:\n"
             . "El cliente {$contacto} necesita hablar con una persona"
             . ($motivo !== '' ? ".\nMotivo: {$motivo}" : '.') . "\n"
             . "El asistente quedo en pausa para ese chat hasta que lo reactives en el panel (seccion Citas).";
    try {
        enviar_whatsapp($para, $mensaje, $desde);
    } catch (Throwable $e) {
        error_log('avisar_escalacion: ' . $e->getMessage());
    }
}

// Avisa al dueño que se agendó una cita. $c es el conocimiento del negocio.
function avisar_cita_agendada(array $c, array $cita): void {
    cargar_entorno();
    $para  = trim((string)($c['numero_avisos'] ?? ''));
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    if ($para === '' || $desde === '') return; // sin destino o sin remitente: no avisamos

    $cuando = trim(($cita['dia'] ?? '') . ' a las ' . ($cita['hora'] ?? ''));

    // Si hay plantilla aprobada, la usamos (llega aunque el dueño no haya escrito en 24h).
    $contentSid = trim((string) env('TWILIO_PLANTILLA_CITA', ''));
    if ($contentSid !== '') {
        try {
            $r = enviar_whatsapp_plantilla($para, $desde, $contentSid, [
                '1' => (string)($c['negocio'] ?? ''),
                '2' => (string)($cita['nombre'] ?? ''),
                '3' => (string)($cita['servicio'] ?? ''),
                '4' => $cuando,
            ]);
            if (!empty($r['exito'])) return; // entregada por plantilla
            // si falló (ej. plantilla aún no aprobada) seguimos al mensaje libre como respaldo
        } catch (Throwable $e) {
            error_log('avisar_cita_agendada (plantilla): ' . $e->getMessage());
        }
    }

    // Sin plantilla: mensaje libre (solo entrega dentro de la ventana de 24h).
    $mensaje = "Nueva cita en {$c['negocio']}:\n"
             . "Cliente: {$cita['nombre']}\n"
             . "Servicio: {$cita['servicio']}\n"
             . "Cuándo: {$cuando}";
    try {
        enviar_whatsapp($para, $mensaje, $desde);
    } catch (Throwable $e) {
        error_log('avisar_cita_agendada: ' . $e->getMessage());
    }
}

// Avisa al dueño que una cita fue CANCELADA por el cliente. Usa plantilla aprobada
// (llega aunque el dueño no haya escrito en 24h) con respaldo a mensaje libre.
function avisar_cita_cancelada(array $c, array $cita): void {
    cargar_entorno();
    $para  = trim((string)($c['numero_avisos'] ?? ''));
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    if ($para === '' || $desde === '') return;

    $cuando = trim((string)($cita['dia_texto'] ?? '') . ' a las ' . (string)($cita['hora'] ?? ''));

    $contentSid = trim((string) env('TWILIO_PLANTILLA_CANCELADA', ''));
    if ($contentSid !== '') {
        try {
            $r = enviar_whatsapp_plantilla($para, $desde, $contentSid, [
                '1' => (string)($c['negocio'] ?? ''),
                '2' => (string)($cita['nombre'] ?? ''),
                '3' => (string)($cita['servicio'] ?? ''),
                '4' => $cuando,
            ]);
            if (!empty($r['exito'])) return;
        } catch (Throwable $e) {
            error_log('avisar_cita_cancelada (plantilla): ' . $e->getMessage());
        }
    }

    $mensaje = "Cita CANCELADA en {$c['negocio']}:\n"
             . "Cliente: {$cita['nombre']}\n"
             . "Era: {$cita['servicio']} el {$cuando}";
    try {
        enviar_whatsapp($para, $mensaje, $desde);
    } catch (Throwable $e) {
        error_log('avisar_cita_cancelada: ' . $e->getMessage());
    }
}

// Avisa al dueño que una cita fue REAGENDADA por el cliente. $nuevo = ['dia','hora','profesional'].
// Usa plantilla aprobada con respaldo a mensaje libre.
function avisar_cita_reagendada(array $c, array $citaAntes, array $nuevo): void {
    cargar_entorno();
    $para  = trim((string)($c['numero_avisos'] ?? ''));
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    if ($para === '' || $desde === '') return;

    $antes = trim((string)($citaAntes['dia_texto'] ?? '') . ' a las ' . (string)($citaAntes['hora'] ?? ''));
    $prof  = trim((string)($nuevo['profesional'] ?? ''));
    $ahora = trim((string)($nuevo['dia'] ?? '') . ' a las ' . (string)($nuevo['hora'] ?? '')) . ($prof !== '' ? ' con ' . $prof : '');

    $contentSid = trim((string) env('TWILIO_PLANTILLA_REAGENDADA', ''));
    if ($contentSid !== '') {
        try {
            $r = enviar_whatsapp_plantilla($para, $desde, $contentSid, [
                '1' => (string)($c['negocio'] ?? ''),
                '2' => (string)($citaAntes['nombre'] ?? ''),
                '3' => (string)($citaAntes['servicio'] ?? ''),
                '4' => $antes,
                '5' => $ahora,
            ]);
            if (!empty($r['exito'])) return;
        } catch (Throwable $e) {
            error_log('avisar_cita_reagendada (plantilla): ' . $e->getMessage());
        }
    }

    $mensaje = "Cita REAGENDADA en {$c['negocio']}:\n"
             . "Cliente: {$citaAntes['nombre']}\n"
             . "Servicio: {$citaAntes['servicio']}\n"
             . "Antes: {$antes}\n"
             . "Ahora: {$ahora}";
    try {
        enviar_whatsapp($para, $mensaje, $desde);
    } catch (Throwable $e) {
        error_log('avisar_cita_reagendada: ' . $e->getMessage());
    }
}

// Recordatorio al CLIENTE de su proxima cita (lo dispara recordatorios.php via cron).
// El destinatario es el numero del cliente (cita.contacto); el remitente, el WhatsApp del negocio.
// Necesita plantilla aprobada (el cliente no escribio en 24h). Devuelve true si se entrego.
function avisar_recordatorio_cliente(array $c, array $cita): bool {
    cargar_entorno();
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    $para  = trim((string)($cita['contacto'] ?? ''));
    if ($desde === '' || $para === '') return false;

    $cuando = trim((string)($cita['dia_texto'] ?? ''));
    if ($cuando === '') $cuando = trim((string)($cita['fecha'] ?? '') . ' ' . (string)($cita['hora'] ?? ''));
    else                $cuando .= ' a las ' . (string)($cita['hora'] ?? '');
    $prof = trim((string)($cita['profesional'] ?? ''));
    if ($prof !== '') $cuando .= ' con ' . $prof;

    $contentSid = trim((string) env('TWILIO_PLANTILLA_RECORDATORIO', ''));
    if ($contentSid !== '') {
        try {
            $r = enviar_whatsapp_plantilla($para, $desde, $contentSid, [
                '1' => (string)($cita['nombre'] ?? ''),
                '2' => (string)($c['negocio'] ?? ''),
                '3' => (string)($cita['servicio'] ?? ''),
                '4' => $cuando,
            ]);
            if (!empty($r['exito'])) return true;
        } catch (Throwable $e) {
            error_log('avisar_recordatorio_cliente (plantilla): ' . $e->getMessage());
        }
    }

    // Respaldo: mensaje libre (solo entrega si el cliente escribio en las ultimas 24h).
    $mensaje = "Hola, te recordamos tu cita en {$c['negocio']}.\n"
             . "Servicio: {$cita['servicio']}\n"
             . "Cuando: {$cuando}\n"
             . "Si necesitas cambiarla o cancelarla, respondenos por este medio.";
    try {
        $r = enviar_whatsapp($para, $mensaje, $desde);
        return !empty($r['exito']);
    } catch (Throwable $e) {
        error_log('avisar_recordatorio_cliente (libre): ' . $e->getMessage());
        return false;
    }
}
