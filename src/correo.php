<?php
// Envio de correo. En produccion hay que conectar un SMTP real (SendGrid, etc.).
// En local, siempre deja rastro en storage/correos.log para poder leer el enlace.

require_once __DIR__ . '/../config/seguridad.php';

function enviar_correo(string $para, string $asunto, string $cuerpo): bool {
    cargar_entorno();
    $from    = env('CORREO_FROM', 'no-reply@localhost');
    $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";

    $ok = false;
    if (function_exists('mail')) {
        $ok = @mail($para, $asunto, $cuerpo, $headers);
    }

    // Respaldo de desarrollo: registrar el correo para poder verlo localmente.
    $log = __DIR__ . '/../storage/correos.log';
    @file_put_contents($log, '[' . date('c') . "] Para: $para | $asunto\n$cuerpo\n\n", FILE_APPEND | LOCK_EX);

    return $ok;
}
