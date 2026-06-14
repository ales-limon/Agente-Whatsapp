<?php
// Proteccion CSRF (mismas convenciones que el consultorio).
// Token de 32 bytes, validacion con hash_equals (constant-time), vive con la sesion.

require_once __DIR__ . '/config/seguridad.php';

function generar_token_csrf(): string {
    iniciar_sesion_segura();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validar_token_csrf($token): bool {
    iniciar_sesion_segura();
    if (!is_string($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function campo_csrf(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generar_token_csrf(), ENT_QUOTES, 'UTF-8') . '">';
}

function requiere_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!validar_token_csrf($token)) {
        log_evento_seguridad('csrf_invalido', ['ruta' => $_SERVER['REQUEST_URI'] ?? '']);
        http_response_code(403);
        die('Error de seguridad: token CSRF invalido. Recarga la pagina e intenta de nuevo.');
    }
}
