<?php
// Endpoint que recibe los mensajes de WhatsApp (via Twilio). Enruta por el numero
// que recibio el mensaje (campo To) hacia el negocio correspondiente, y responde con la IA.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/conversaciones.php';
require_once __DIR__ . '/src/claude.php';
require_once __DIR__ . '/src/twilio.php';
require_once __DIR__ . '/src/uso.php';

cargar_entorno();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// --- Seguridad: el webhook debe venir de Twilio ---
if (env('VALIDAR_FIRMA_TWILIO', '1') === '1') {
    $authToken = (string) env('TWILIO_AUTH_TOKEN', '');
    $url       = (string) env('WEBHOOK_URL', '');
    $firma     = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if (!validar_firma_twilio($url, $_POST, $firma, $authToken)) {
        http_response_code(403);
        exit;
    }
}

$to     = trim($_POST['To'] ?? '');     // numero del NEGOCIO (whatsapp:+...)
$from   = trim($_POST['From'] ?? '');   // numero del CLIENTE
$cuerpo = trim($_POST['Body'] ?? '');

if ($from === '' || $cuerpo === '') {
    http_response_code(200);
    exit;
}

// --- Enrutar al negocio segun el numero que recibio el mensaje ---
$negocio = negocio_por_numero($to);
if (!$negocio) {
    // Numero no asignado a ningun negocio: no respondemos.
    http_response_code(200);
    exit;
}
$idNegocio = (int)$negocio['id'];

// Responder por WhatsApp (TwiML) y terminar.
function responder_whatsapp(string $texto): void {
    header('Content-Type: text/xml; charset=utf-8');
    $escapada = htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo "<Response><Message>{$escapada}</Message></Response>";
}

// --- Tope de uso del plan: si el negocio alcanzó su límite mensual, NO llamamos
//     a la IA (cada respuesta cuesta API + Twilio). Guardamos el mensaje entrante
//     para que el dueño lo vea y respondemos con cortesía. ---
if (!dentro_de_limite($negocio)) {
    guardar_mensaje($idNegocio, $from, 'user', $cuerpo);
    responder_whatsapp(
        'Gracias por tu mensaje. En este momento no puedo atenderte de forma automática; '
        . 'una persona de ' . $negocio['nombre'] . ' te responderá lo antes posible.'
    );
    exit;
}

$c            = cargar_conocimiento($idNegocio);
$systemPrompt = construir_system_prompt($c);
$historial    = cargar_historial($idNegocio, $from);

$uso       = ['entrada' => 0, 'salida' => 0];
$respuesta = responder_con_claude($systemPrompt, $historial, $cuerpo, $from, $idNegocio, $uso);

guardar_mensaje($idNegocio, $from, 'user', $cuerpo);
guardar_mensaje($idNegocio, $from, 'assistant', $respuesta);
registrar_uso($idNegocio, (int)$uso['entrada'], (int)$uso['salida']);

responder_whatsapp($respuesta);
