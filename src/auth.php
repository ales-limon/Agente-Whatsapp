<?php
// Autenticacion y guards (mismas convenciones que el consultorio, adaptado a este proyecto).
// Roles: 'superadmin' (ve todos los negocios) y 'admin' (dueño de UN negocio).

require_once __DIR__ . '/../config/seguridad.php';
require_once __DIR__ . '/negocios.php';

const AUTH_RATE_MAX    = 5;
const AUTH_RATE_WINDOW = 900; // 15 min

function usuario_actual(): ?array {
    iniciar_sesion_segura();
    return $_SESSION['usuario'] ?? null;
}

function esta_autenticado(): bool {
    return usuario_actual() !== null;
}

function obtener_id_usuario(): ?int {
    $u = usuario_actual();
    return $u ? (int)$u['id'] : null;
}

function obtener_rol(): ?string {
    return usuario_actual()['rol'] ?? null;
}

function obtener_id_negocio_usuario(): ?int {
    $u = usuario_actual();
    return ($u && $u['id_negocio'] !== null) ? (int)$u['id_negocio'] : null;
}

function es_superadmin(): bool { return obtener_rol() === 'superadmin'; }
function es_admin(): bool      { return obtener_rol() === 'admin'; }

function existe_superadmin(): bool {
    return (int)conexion()->query("SELECT COUNT(*) FROM usuarios WHERE rol='superadmin' AND activo=1")->fetchColumn() > 0;
}

function autenticar(string $email, string $password): array {
    iniciar_sesion_segura();
    $email = trim(strtolower($email));
    $ip    = obtener_ip_cliente();
    $pdo   = conexion();

    // Rate limit por IP (anti fuerza bruta)
    $st = $pdo->prepare("SELECT COUNT(*) FROM eventos_seguridad WHERE tipo='login_fallido' AND ip=? AND creado_en > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $st->execute([$ip, AUTH_RATE_WINDOW]);
    if ((int)$st->fetchColumn() >= AUTH_RATE_MAX) {
        log_evento_seguridad('rate_limit', ['email' => $email]);
        return ['exito' => false, 'mensaje' => 'Demasiados intentos fallidos. Espera unos minutos.'];
    }
    usleep(300000);

    $st = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        log_evento_seguridad('login_fallido', ['email' => $email]);
        return ['exito' => false, 'mensaje' => 'Credenciales incorrectas.'];
    }
    if ((int)$u['activo'] !== 1) {
        log_evento_seguridad('login_fallido', ['email' => $email, 'motivo' => 'inactivo'], (int)$u['id']);
        return ['exito' => false, 'mensaje' => 'Tu cuenta está suspendida.'];
    }

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'         => (int)$u['id'],
        'email'      => $u['email'],
        'nombre'     => $u['nombre'],
        'rol'        => $u['rol'],
        'id_negocio' => $u['id_negocio'] !== null ? (int)$u['id_negocio'] : null,
    ];
    $_SESSION['ultimo_acceso'] = time();
    log_evento_seguridad('login_exitoso', ['rol' => $u['rol']], (int)$u['id'], $u['id_negocio']);
    return ['exito' => true, 'usuario' => $_SESSION['usuario']];
}

function cerrar_sesion(): void {
    iniciar_sesion_segura();
    $u = usuario_actual();
    if ($u) log_evento_seguridad('logout', [], $u['id'], $u['id_negocio']);
    $_SESSION = [];
    if (isset($_COOKIE[SESION_NAME])) setcookie(SESION_NAME, '', time() - 3600, '/');
    session_destroy();
}

function requiere_autenticacion(): void {
    aplicar_headers_seguridad();
    iniciar_sesion_segura();
    if (esta_autenticado()) {
        $ultimo = $_SESSION['ultimo_acceso'] ?? 0;
        if (time() - $ultimo > SESION_TIMEOUT) {
            cerrar_sesion();
            header('Location: login.php?expirado=1');
            exit;
        }
        $_SESSION['ultimo_acceso'] = time();
        return;
    }
    header('Location: login.php');
    exit;
}

function requiere_superadmin(): void {
    requiere_autenticacion();
    if (!es_superadmin()) {
        log_evento_seguridad('acceso_denegado', ['ruta' => $_SERVER['REQUEST_URI'] ?? '', 'rol' => obtener_rol()], obtener_id_usuario());
        http_response_code(403);
        die('Acceso denegado: esta sección es solo para superadmin.');
    }
}

// Garantiza que el usuario actual puede operar sobre $idNegocio.
// Superadmin: cualquiera. Admin: solo el suyo. Otro intento => violacion_multitenant.
function requiere_acceso_negocio(int $idNegocio): void {
    requiere_autenticacion();
    if (es_superadmin()) return;
    if (obtener_id_negocio_usuario() === $idNegocio) return;
    log_evento_seguridad('violacion_multitenant', [
        'ruta' => $_SERVER['REQUEST_URI'] ?? '',
        'id_negocio_intentado' => $idNegocio,
    ], obtener_id_usuario(), obtener_id_negocio_usuario());
    http_response_code(403);
    die('No tienes acceso a este negocio.');
}

function crear_usuario(string $email, string $password, string $nombre, string $rol, ?int $idNegocio): array {
    $pdo   = conexion();
    $email = trim(strtolower($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['exito' => false, 'mensaje' => 'El email no es válido.'];
    if (strlen($password) < 8)                      return ['exito' => false, 'mensaje' => 'La contraseña debe tener al menos 8 caracteres.'];
    if (trim($nombre) === '')                       return ['exito' => false, 'mensaje' => 'El nombre es obligatorio.'];

    $rol = in_array($rol, ['superadmin', 'admin'], true) ? $rol : 'admin';
    if ($rol === 'superadmin') $idNegocio = null;
    if ($rol === 'admin' && !$idNegocio) return ['exito' => false, 'mensaje' => 'Un admin debe tener un negocio asignado.'];

    $st = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $st->execute([$email]);
    if ($st->fetch()) return ['exito' => false, 'mensaje' => 'Ya existe un usuario con ese email.'];

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $pdo->prepare("INSERT INTO usuarios (email, password_hash, nombre, rol, id_negocio, activo) VALUES (?, ?, ?, ?, ?, 1)");
    $st->execute([$email, $hash, trim($nombre), $rol, $idNegocio]);

    log_evento_seguridad('usuario_creado', ['email' => $email, 'rol' => $rol], obtener_id_usuario(), $idNegocio);
    return ['exito' => true, 'id' => (int)$pdo->lastInsertId()];
}

function listar_usuarios(): array {
    return conexion()->query(
        "SELECT u.id, u.email, u.nombre, u.rol, u.activo, u.id_negocio, n.nombre AS negocio_nombre
         FROM usuarios u LEFT JOIN negocios n ON u.id_negocio = n.id
         ORDER BY u.rol = 'superadmin' DESC, u.email"
    )->fetchAll();
}

/* -----------------------------------------------------------
 * AUTO-REGISTRO (onboarding self-service)
 * --------------------------------------------------------- */
// Crea un negocio + su usuario admin de forma atomica.
function registrar_cuenta(string $nombreNegocio, string $nombreDueno, string $email, string $password): array {
    $email = trim(strtolower($email));
    if (trim($nombreNegocio) === '') return ['exito' => false, 'mensaje' => 'El nombre del negocio es obligatorio.'];
    if (trim($nombreDueno) === '')   return ['exito' => false, 'mensaje' => 'Tu nombre es obligatorio.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['exito' => false, 'mensaje' => 'El email no es válido.'];
    if (strlen($password) < 8)       return ['exito' => false, 'mensaje' => 'La contraseña debe tener al menos 8 caracteres.'];

    $pdo = conexion();
    $st  = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $st->execute([$email]);
    if ($st->fetch()) return ['exito' => false, 'mensaje' => 'Ya existe una cuenta con ese email.'];

    $pdo->beginTransaction();
    try {
        $idNegocio = crear_negocio($nombreNegocio);
        $r = crear_usuario($email, $password, $nombreDueno, 'admin', $idNegocio);
        if (!$r['exito']) { $pdo->rollBack(); return $r; }
        $pdo->commit();
        log_evento_seguridad('cuenta_registrada', ['email' => $email], (int)$r['id'], $idNegocio);
        return ['exito' => true, 'id_negocio' => $idNegocio];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('registrar_cuenta: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo crear la cuenta. Intenta de nuevo.'];
    }
}

/* -----------------------------------------------------------
 * RECUPERACION DE CONTRASEÑA
 * --------------------------------------------------------- */
// Genera un token de reset (1h de vida). Devuelve el token en claro SOLO al caller
// (para que arme el enlace y lo envie). En BD se guarda solo su hash.
function solicitar_reset(string $email): array {
    $email = trim(strtolower($email));
    $st = conexion()->prepare("SELECT id FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return ['exito' => false]; // el caller muestra mensaje generico igual

    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $st = conexion()->prepare("INSERT INTO password_resets (id_usuario, token_hash, expira_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $st->execute([(int)$u['id'], $hash]);
    log_evento_seguridad('reset_solicitado', ['email' => $email], (int)$u['id']);
    return ['exito' => true, 'token' => $token];
}

function validar_reset(string $token): ?array {
    if ($token === '') return null;
    $st = conexion()->prepare(
        "SELECT pr.id AS reset_id, u.id, u.email, u.nombre
         FROM password_resets pr
         JOIN usuarios u ON u.id = pr.id_usuario
         WHERE pr.token_hash = ? AND pr.usado = 0 AND pr.expira_en > NOW()
         LIMIT 1"
    );
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

function restablecer_password(string $token, string $nuevaPassword): array {
    if (strlen($nuevaPassword) < 8) return ['exito' => false, 'mensaje' => 'La contraseña debe tener al menos 8 caracteres.'];
    $row = validar_reset($token);
    if (!$row) return ['exito' => false, 'mensaje' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.'];

    $pdo = conexion();
    $pdo->beginTransaction();
    try {
        $hash = password_hash($nuevaPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, (int)$row['id']]);
        $pdo->prepare("UPDATE password_resets SET usado = 1 WHERE id = ?")->execute([(int)$row['reset_id']]);
        $pdo->commit();
        log_evento_seguridad('password_restablecido', [], (int)$row['id']);
        return ['exito' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('restablecer_password: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo cambiar la contraseña.'];
    }
}
