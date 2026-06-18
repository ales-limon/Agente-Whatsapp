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
    $para  = trim((string)($c['numero_avisos'] ?? ''));
    $desde = trim((string)($c['numero_whatsapp'] ?? ''));
    if ($para === '' || $desde === '') return; // sin destino o sin remitente: no avisamos

    $mensaje = "Nueva cita en {$c['negocio']}:\n"
             . "Cliente: {$cita['nombre']}\n"
             . "Servicio: {$cita['servicio']}\n"
             . "Cuándo: {$cita['dia']} a las {$cita['hora']}";
    try {
        enviar_whatsapp($para, $mensaje, $desde);
    } catch (Throwable $e) {
        error_log('avisar_cita_agendada: ' . $e->getMessage());
    }
}
