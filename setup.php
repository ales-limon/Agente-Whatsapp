<?php
// Setup inicial: crea el PRIMER superadmin. Se bloquea solo en cuanto exista uno.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/csrf.php';

aplicar_headers_seguridad();
iniciar_sesion_segura();

if (existe_superadmin()) {
    echo 'Ya existe un superadmin. <a href="login.php">Inicia sesión</a>.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $r = crear_usuario($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['nombre'] ?? '', 'superadmin', null);
    if ($r['exito']) {
        header('Location: login.php');
        exit;
    }
    $error = $r['mensaje'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuración inicial</title>
<style>
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a;
         display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .caja { background: #fff; border: 1px solid #e7e7e2; border-radius: 12px; padding: 28px 30px; width: 360px; }
  .caja h1 { font-size: 18px; font-weight: 500; margin: 0 0 4px; }
  .caja p.sub { font-size: 13px; color: #6a6a64; margin: 0 0 18px; }
  label { display: block; font-size: 13px; color: #6a6a64; margin: 12px 0 4px; }
  input { width: 100%; padding: 10px 12px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
  button { width: 100%; margin-top: 18px; background: #075e54; color: #fff; border: 0; border-radius: 6px; padding: 11px; font-size: 15px; cursor: pointer; }
  button:hover { background: #064a42; }
  .error { background: #fcebeb; color: #791f1f; border: 1px solid #f0a8a8; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
</style>
</head>
<body>
  <div class="caja">
    <h1>Crear superadmin</h1>
    <p class="sub">Esta es la cuenta dueña del sistema. Solo se crea una vez.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <?= campo_csrf() ?>
      <label>Nombre</label>
      <input type="text" name="nombre" required autofocus>
      <label>Correo</label>
      <input type="email" name="email" required>
      <label>Contraseña (mínimo 8 caracteres)</label>
      <input type="password" name="password" required minlength="8">
      <button type="submit">Crear superadmin</button>
    </form>
  </div>
</body>
</html>
