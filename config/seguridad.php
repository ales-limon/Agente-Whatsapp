<?php
// Helpers de seguridad (mismas convenciones que el consultorio):
//  - headers de seguridad
//  - sesion segura (cookie flags + timeout)
//  - log de eventos de seguridad en la tabla eventos_seguridad
// NUNCA rompe el flujo principal si el log falla.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../src/entorno.php';

const SESION_NAME    = 'AGENTE_WHATSAPP';
const SESION_TIMEOUT = 1800; // 30 min

function obtener_ip_cliente(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function es_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);
}

function aplicar_headers_seguridad(): void {
    cargar_entorno();
    // En local mostramos errores; en produccion NUNCA (evita filtrar stack traces).
    $local = env('APP_ENV', 'local') === 'local';
    ini_set('display_errors', $local ? '1' : '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (es_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function iniciar_sesion_segura(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    session_name(SESION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    return true;
}

function log_evento_seguridad(string $tipo, array $detalle = [], $id_usuario = null, $id_negocio = null): bool {
    try {
        $pdo = conexion();
        $st  = $pdo->prepare(
            "INSERT INTO eventos_seguridad (tipo, detalle, ip, user_agent, id_usuario, id_negocio)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $detalle_json = !empty($detalle)
            ? json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
        $st->execute([$tipo, $detalle_json, obtener_ip_cliente(), $ua, $id_usuario, $id_negocio]);
        return true;
    } catch (Throwable $e) {
        error_log("log_evento_seguridad fallo (tipo=$tipo): " . $e->getMessage());
        return false;
    }
}

// URL base del sistema (para armar enlaces absolutos en correos).
function base_url(): string {
    cargar_entorno();
    $env = env('APP_URL');
    if ($env) return rtrim($env, '/');
    $scheme = es_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scheme . '://' . $host . rtrim($dir, '/');
}
