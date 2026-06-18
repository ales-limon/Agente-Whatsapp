<?php
// Crear nueva contraseña con un token de recuperacion valido.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/csrf.php';

aplicar_headers_seguridad();
iniciar_sesion_segura();

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$row   = validar_reset($token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    requiere_csrf();
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if ($p1 !== $p2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $r = restablecer_password($token, $p1);
        if ($r['exito']) {
            header('Location: login.php?reset=1');
            exit;
        }
        $error = $r['mensaje'];
        $row   = validar_reset($token); // revalidar por si el token sigue vivo
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nueva contraseña — Agente de WhatsApp</title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  .pantalla { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .caja { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio-lg);
          box-shadow: var(--sombra); padding: 32px 32px 28px; width: 100%; max-width: 384px; }
  .caja .marca { margin-bottom: 22px; }
  .caja h1 { font-size: 23px; margin: 0 0 6px; }
  .caja .sub { font-size: 14px; color: var(--texto-2); margin: 0 0 22px; }
  .caja form { margin: 0; }
  .caja .etiqueta:first-of-type { margin-top: 0; }
  .caja .btn { margin-top: 22px; }
  .pie { font-size: 13px; text-align: center; margin: 16px 0 0; color: var(--texto-2); }
  .pie a { font-weight: 500; }
</style>
</head>
<body>
  <div class="pantalla">
    <div class="caja">
      <div class="marca">
        <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
        <span class="marca__nombre">Agente de WhatsApp</span>
      </div>
      <h1>Nueva contraseña</h1>
      <?php if (!$row): ?>
        <p class="sub">El enlace no es válido o ya expiró.</p>
        <p class="pie"><a href="recuperar.php">Solicitar uno nuevo</a></p>
      <?php else: ?>
        <p class="sub">Hola <?= htmlspecialchars($row['nombre']) ?>, crea tu nueva contraseña.</p>
        <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
        <form method="post">
          <?= campo_csrf() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <label class="etiqueta" for="password">Nueva contraseña (mínimo 8 caracteres)</label>
          <input class="campo" id="password" type="password" name="password" required minlength="8" autofocus>
          <label class="etiqueta" for="password2">Repite la contraseña</label>
          <input class="campo" id="password2" type="password" name="password2" required minlength="8">
          <button type="submit" class="btn btn--primario btn--bloque">Cambiar contraseña</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
