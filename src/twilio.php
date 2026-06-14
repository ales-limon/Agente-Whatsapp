<?php
// Valida que el webhook venga realmente de Twilio y no de un tercero que quiera
// quemar tu cuota de API. Doc: https://www.twilio.com/docs/usage/security

function validar_firma_twilio(string $url, array $params, string $firma, string $authToken): bool {
    if ($firma === '' || $authToken === '') return false;
    // Twilio firma la URL concatenada con cada par clave-valor ordenado por clave
    ksort($params);
    $data = $url;
    foreach ($params as $clave => $valor) {
        $data .= $clave . $valor;
    }
    $esperada = base64_encode(hash_hmac('sha1', $data, $authToken, true));
    return hash_equals($esperada, $firma);
}
