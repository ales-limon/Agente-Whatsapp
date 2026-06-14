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
<title>Nueva contraseña</title>
<style>
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a;
         display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; }
  .caja { background: #fff; border: 1px solid #e7e7e2; border-radius: 12px; padding: 28px 30px; width: 350px; }
  .caja h1 { font-size: 18px; font-weight: 500; margin: 0 0 4px; }
  .caja p.sub { font-size: 13px; color: #6a6a64; margin: 0 0 18px; }
  label { display: block; font-size: 13px; color: #6a6a64; margin: 12px 0 4px; }
  input { width: 100%; padding: 10px 12px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
  button { width: 100%; margin-top: 18px; background: #075e54; color: #fff; border: 0; border-radius: 6px; padding: 11px; font-size: 15px; cursor: pointer; }
  button:hover { background: #064a42; }
  .error { background: #fcebeb; color: #791f1f; border: 1px solid #f0a8a8; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
  .pie { font-size: 13px; text-align: center; margin-top: 16px; color: #6a6a64; }
  .pie a { color: #075e54; }
</style>
</head>
<body>
  <div class="caja">
    <h1>Nueva contraseña</h1>
    <?php if (!$row): ?>
      <p class="sub">El enlace no es válido o ya expiró.</p>
      <p class="pie"><a href="recuperar.php">Solicitar uno nuevo</a></p>
    <?php else: ?>
      <p class="sub">Hola <?= htmlspecialchars($row['nombre']) ?>, crea tu nueva contraseña.</p>
      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
        <label>Nueva contraseña (mínimo 8 caracteres)</label>
        <input type="password" name="password" required minlength="8" autofocus>
        <label>Repite la contraseña</label>
        <input type="password" name="password2" required minlength="8">
        <button type="submit">Cambiar contraseña</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
