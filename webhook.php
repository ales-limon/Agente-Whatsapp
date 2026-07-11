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
require_once __DIR__ . '/src/escalacion.php';
require_once __DIR__ . '/src/media.php';
require_once __DIR__ . '/src/caja.php';

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
$cuerpo   = trim($_POST['Body'] ?? '');
$numMedia = (int)($_POST['NumMedia'] ?? 0);

if ($from === '' || ($cuerpo === '' && $numMedia === 0)) {
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

// --- Consulta de CAJA del dueño: si quien escribe es el numero de avisos del
//     negocio y su mensaje pide claramente un corte/ingresos, respondemos con el
//     resumen y NO lo tratamos como cliente. Se exige intencion de caja explicita
//     para no estorbar cuando el dueño prueba el bot como cliente. ---
$numAvisos = trim((string)($negocio['numero_avisos'] ?? ''));
if ($numAvisos !== '' && mismo_numero($from, $numAvisos) && es_consulta_caja($cuerpo)) {
    responder_whatsapp(corte_texto($idNegocio, periodo_de_texto($cuerpo)));
    exit;
}

// --- Atención humana: si este chat fue escalado, el bot NO responde (lo lleva una
//     persona). Guardamos el mensaje entrante para que el dueño lo vea y no enviamos
//     nada al cliente, hasta que se reactive el bot desde el panel. ---
if (handoff_activo($idNegocio, $from)) {
    guardar_mensaje($idNegocio, $from, 'user', $cuerpo !== '' ? $cuerpo : '[multimedia]');
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

// --- Tope de uso del plan: si el negocio alcanzó su límite mensual, NO llamamos
//     a la IA (cada respuesta cuesta API + Twilio). Guardamos el mensaje entrante
//     para que el dueño lo vea y respondemos con cortesía. ---
if (!dentro_de_limite($negocio)) {
    guardar_mensaje($idNegocio, $from, 'user', $cuerpo !== '' ? $cuerpo : '[multimedia]');
    responder_whatsapp(mensaje_limite($negocio));
    exit;
}

// --- Multimedia: imágenes (visión de Claude) y audios (transcripción Whisper) ---
$imagen = null;
if ($numMedia > 0) {
    $tipoMedia = (string)($_POST['MediaContentType0'] ?? '');
    $urlMedia  = (string)($_POST['MediaUrl0'] ?? '');

    if (strpos($tipoMedia, 'audio/') === 0) {
        $media = descargar_media_twilio($urlMedia);
        $txt   = $media['ok'] ? transcribir_audio($media['data'], $tipoMedia) : '';
        if ($txt === '' && $cuerpo === '') {
            guardar_mensaje($idNegocio, $from, 'user', '[nota de voz]');
            responder_whatsapp('No pude entender tu nota de voz. ¿Me lo puedes escribir por texto, por favor?');
            exit;
        }
        if ($txt !== '') $cuerpo = $cuerpo !== '' ? ($cuerpo . "\n" . $txt) : $txt;
    } elseif (strpos($tipoMedia, 'image/') === 0) {
        $media = descargar_media_twilio($urlMedia);
        if ($media['ok']) {
            $imagen = ['media_type' => $tipoMedia, 'data' => base64_encode($media['data'])];
        }
        if ($cuerpo === '') $cuerpo = 'El cliente te envió esta imagen.';
    } else {
        if ($cuerpo === '') {
            guardar_mensaje($idNegocio, $from, 'user', '[archivo no soportado]');
            responder_whatsapp('Recibí tu archivo, pero por ahora solo puedo leer texto, imágenes y notas de voz. ¿Me lo puedes escribir?');
            exit;
        }
    }
}

$c            = cargar_conocimiento($idNegocio);
$systemPrompt = construir_system_prompt($c);
$historial    = cargar_historial($idNegocio, $from);

$uso       = ['entrada' => 0, 'salida' => 0];
$respuesta = responder_con_claude($systemPrompt, $historial, $cuerpo, $from, $idNegocio, $uso, $imagen);

guardar_mensaje($idNegocio, $from, 'user', $cuerpo !== '' ? $cuerpo : '[multimedia]');
guardar_mensaje($idNegocio, $from, 'assistant', $respuesta);
registrar_uso($idNegocio, (int)$uso['entrada'], (int)$uso['salida']);

responder_whatsapp($respuesta);
