<?php
// Manejo de multimedia de WhatsApp (vía Twilio): descarga de media y
// transcripción de audio (Whisper de OpenAI). Las imágenes se mandan a Claude
// (visión) desde claude.php; aquí solo se descargan.

require_once __DIR__ . '/entorno.php';

// Descarga un archivo de media de Twilio. Requiere auth Basic (SID:token).
// Devuelve ['ok'=>bool, 'data'=>bytes, 'tipo'=>content-type].
function descargar_media_twilio(string $url): array {
    $sid   = (string) env('TWILIO_ACCOUNT_SID', '');
    $token = (string) env('TWILIO_AUTH_TOKEN', '');
    if ($url === '' || $sid === '' || $token === '') return ['ok' => false];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$sid:$token",
        CURLOPT_FOLLOWLOCATION => true, // Twilio redirige al host real del archivo
        CURLOPT_TIMEOUT        => 30,
    ]);
    $data   = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($data === false || $codigo >= 400) {
        error_log("descargar_media_twilio error ($codigo) $url");
        return ['ok' => false];
    }
    return ['ok' => true, 'data' => $data, 'tipo' => (string)$ctype];
}

// Transcribe audio (bytes) a texto con Whisper de OpenAI. Devuelve '' si no se puede.
function transcribir_audio(string $bytes, string $tipo): string {
    $key = (string) env('OPENAI_API_KEY', '');
    if ($key === '' || $bytes === '') return '';

    $ext = (strpos($tipo, 'ogg') !== false) ? 'ogg'
         : ((strpos($tipo, 'mp3') !== false || strpos($tipo, 'mpeg') !== false) ? 'mp3'
         : ((strpos($tipo, 'm4a') !== false || strpos($tipo, 'mp4') !== false) ? 'm4a' : 'ogg'));

    $tmp = tempnam(sys_get_temp_dir(), 'wa_aud_');
    if ($tmp === false) return '';
    $archivo = $tmp . '.' . $ext;
    file_put_contents($archivo, $bytes);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => [
            'model'    => 'whisper-1',
            'language' => 'es',
            'file'     => new CURLFile($archivo, $tipo ?: 'audio/ogg', 'audio.' . $ext),
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp   = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($archivo);
    @unlink($tmp);

    if ($resp === false || $codigo >= 400) {
        error_log("transcribir_audio (whisper) error ($codigo): " . substr((string)$resp, 0, 200));
        return '';
    }
    $j = json_decode($resp, true);
    return trim((string)($j['text'] ?? ''));
}
