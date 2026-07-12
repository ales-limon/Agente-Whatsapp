<?php
// Cliente minimal de la API de Anthropic (Claude). Sin SDK, solo cURL.
// Soporta tool-use: si el modelo pide una herramienta, la ejecutamos y le
// devolvemos el resultado para que genere la respuesta final.

require_once __DIR__ . '/herramientas.php';

function llamar_claude_raw(string $apiKey, string $modelo, string $systemPrompt, array $mensajes, array $tools): array {
    $payload = [
        'model'      => $modelo,
        'max_tokens' => 600,
        'system'     => $systemPrompt,
        'messages'   => $mensajes,
    ];
    if ($tools) $payload['tools'] = $tools;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT    => 40,
    ]);
    $respuesta = curl_exec($ch);
    $codigo    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($respuesta === false || $codigo >= 400) {
        return ['ok' => false, 'error' => ($err !== '' ? $err : (string)$respuesta), 'codigo' => $codigo, 'data' => []];
    }
    return ['ok' => true, 'error' => '', 'codigo' => $codigo, 'data' => json_decode($respuesta, true) ?: []];
}

function responder_con_claude(string $systemPrompt, array $historial, string $mensajeUsuario, ?string $contacto, int $idNegocio, array &$uso = null, ?array $imagen = null): string {
    $uso = ['entrada' => 0, 'salida' => 0]; // tokens consumidos en esta respuesta (puede sumar varias vueltas de tool-use)
    $apiKey = env('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        error_log('Falta ANTHROPIC_API_KEY');
        return 'En este momento no puedo atenderte automaticamente. Una persona del consultorio te respondera en breve.';
    }
    $modelo = env('CLAUDE_MODELO', 'claude-haiku-4-5-20251001');

    // Historial previo + mensaje actual, en el formato de la API
    $mensajes = [];
    foreach ($historial as $m) {
        $rol = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $mensajes[] = ['role' => $rol, 'content' => (string)($m['content'] ?? '')];
    }
    if ($imagen && !empty($imagen['data'])) {
        // Mensaje con imagen (visión): bloque de imagen + texto.
        $mensajes[] = ['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $imagen['media_type'], 'data' => $imagen['data']]],
            ['type' => 'text', 'text' => $mensajeUsuario !== '' ? $mensajeUsuario : 'El cliente envió esta imagen.'],
        ]];
    } else {
        $mensajes[] = ['role' => 'user', 'content' => $mensajeUsuario];
    }

    $tools = herramientas_disponibles();

    // Ciclo: damos hasta 4 vueltas para resolver llamadas a herramientas.
    for ($vuelta = 0; $vuelta < 4; $vuelta++) {
        $r = llamar_claude_raw($apiKey, $modelo, $systemPrompt, $mensajes, $tools);
        if (!$r['ok']) {
            error_log("Claude API error ({$r['codigo']}): {$r['error']}");
            return 'Disculpa, tuve un problema para responderte. En un momento te atiende una persona del consultorio.';
        }

        $data    = $r['data'];
        $u       = $data['usage'] ?? [];
        $uso['entrada'] += (int)($u['input_tokens'] ?? 0);
        $uso['salida']  += (int)($u['output_tokens'] ?? 0);
        $content = $data['content'] ?? [];
        $stop    = $data['stop_reason'] ?? '';

        if ($stop === 'tool_use') {
            // El modelo pidio usar una o mas herramientas
            $mensajes[] = ['role' => 'assistant', 'content' => $content];
            $resultados = [];
            foreach ($content as $bloque) {
                if (($bloque['type'] ?? '') === 'tool_use') {
                    $salida = ejecutar_herramienta($bloque['name'], $bloque['input'] ?? [], $contacto, $idNegocio);
                    // Log de diagnostico: qué herramienta llamó el agente, con qué datos y qué respondió.
                    @file_put_contents(
                        __DIR__ . '/../storage/agente.log',
                        date('Y-m-d H:i:s') . " | neg $idNegocio | " . ($contacto ?? '?') . " | " . $bloque['name']
                        . ' ' . json_encode($bloque['input'] ?? [], JSON_UNESCAPED_UNICODE)
                        . ' => ' . mb_substr(trim((string)$salida), 0, 220) . "\n",
                        FILE_APPEND
                    );
                    $resultados[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $bloque['id'],
                        'content'     => $salida,
                    ];
                }
            }
            $mensajes[] = ['role' => 'user', 'content' => $resultados];
            continue; // volver a llamar para obtener el texto final
        }

        // Respuesta normal: juntar los bloques de texto
        $texto = '';
        foreach ($content as $bloque) {
            if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
        }
        $texto = trim($texto);
        @file_put_contents(
            __DIR__ . '/../storage/agente.log',
            date('Y-m-d H:i:s') . " | neg $idNegocio | " . ($contacto ?? '?') . " | RESPUESTA => " . mb_substr($texto, 0, 220) . "\n",
            FILE_APPEND
        );
        return $texto !== '' ? $texto : 'Disculpa, no entendi bien. Me puedes repetir tu mensaje?';
    }

    return 'Disculpa, tuve un problema procesando tu solicitud. En un momento te atiende una persona del consultorio.';
}
