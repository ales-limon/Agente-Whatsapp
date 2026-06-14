<?php
// Auto-registro: el dueño crea su negocio + su cuenta admin, y entra directo.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/csrf.php';

aplicar_headers_seguridad();
iniciar_sesion_segura();

if (esta_autenticado()) { header('Location: login.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if ($p1 !== $p2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $r = registrar_cuenta($_POST['negocio'] ?? '', $_POST['nombre'] ?? '', $_POST['email'] ?? '', $p1);
        if ($r['exito']) {
            autenticar($_POST['email'] ?? '', $p1);
            $slug = negocio_por_id((int)$r['id_negocio'])['slug'] ?? '';
            header('Location: configuracion.php?t=' . urlencode($slug));
            exit;
        }
        $error = $r['mensaje'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crear cuenta</title>
<style>
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a;
         display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; }
  .caja { background: #fff; border: 1px solid #e7e7e2; border-radius: 12px; padding: 28px 30px; width: 360px; }
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
    <h1>Crea tu cuenta</h1>
    <p class="sub">Registra tu negocio y empieza a recibir citas por WhatsApp.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <?= campo_csrf() ?>
      <label>Nombre del negocio</label>
      <input type="text" name="negocio" required autofocus value="<?= htmlspecialchars($_POST['negocio'] ?? '', ENT_QUOTES) ?>">
      <label>Tu nombre</label>
      <input type="text" name="nombre" required value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES) ?>">
      <label>Correo</label>
      <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
      <label>Contraseña (mínimo 8 caracteres)</label>
      <input type="password" name="password" required minlength="8">
      <label>Repite la contraseña</label>
      <input type="password" name="password2" required minlength="8">
      <button type="submit">Crear cuenta</button>
    </form>
    <p class="pie">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
  </div>
</body>
</html>
