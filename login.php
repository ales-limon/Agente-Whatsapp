<?php
// Inicio de sesion. Redirige segun el rol. Si no hay superadmin aun, manda al setup.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/csrf.php';

aplicar_headers_seguridad();
iniciar_sesion_segura();

if (!existe_superadmin()) {
    header('Location: setup.php');
    exit;
}

function ir_segun_rol(): void {
    if (es_superadmin()) {
        header('Location: superadmin.php');
    } else {
        $neg = obtener_id_negocio_usuario() ? negocio_por_id(obtener_id_negocio_usuario()) : null;
        header('Location: ' . ($neg ? 'panel.php?t=' . urlencode($neg['slug']) : 'login.php'));
    }
    exit;
}

if (esta_autenticado()) ir_segun_rol();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $r = autenticar($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($r['exito']) ir_segun_rol();
    $error = $r['mensaje'];
}
$expirado  = isset($_GET['expirado']);
$reseteado = isset($_GET['reset']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión</title>
<style>
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a;
         display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .caja { background: #fff; border: 1px solid #e7e7e2; border-radius: 12px; padding: 28px 30px; width: 340px; }
  .caja h1 { font-size: 18px; font-weight: 500; margin: 0 0 4px; }
  .caja p.sub { font-size: 13px; color: #6a6a64; margin: 0 0 20px; }
  label { display: block; font-size: 13px; color: #6a6a64; margin: 12px 0 4px; }
  input { width: 100%; padding: 10px 12px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
  button { width: 100%; margin-top: 18px; background: #075e54; color: #fff; border: 0; border-radius: 6px; padding: 11px; font-size: 15px; cursor: pointer; }
  button:hover { background: #064a42; }
  .error { background: #fcebeb; color: #791f1f; border: 1px solid #f0a8a8; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
  .aviso { background: #faeeda; color: #854f0b; border: 1px solid #f0c987; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
  .ok { background: #eaf3de; color: #3b6d11; border: 1px solid #c0dd97; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
  .pie { font-size: 13px; text-align: center; margin-top: 14px; color: #6a6a64; }
  .pie a { color: #075e54; }
</style>
</head>
<body>
  <div class="caja">
    <h1>Agente de WhatsApp</h1>
    <p class="sub">Inicia sesión para administrar tus negocios.</p>
    <?php if ($expirado): ?><div class="aviso">Tu sesión expiró. Inicia sesión de nuevo.</div><?php endif; ?>
    <?php if ($reseteado): ?><div class="ok">Contraseña actualizada. Inicia sesión.</div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <?= campo_csrf() ?>
      <label>Correo</label>
      <input type="email" name="email" required autofocus>
      <label>Contraseña</label>
      <input type="password" name="password" required>
      <button type="submit">Entrar</button>
    </form>
    <p class="pie"><a href="recuperar.php">¿Olvidaste tu contraseña?</a></p>
    <p class="pie">¿No tienes cuenta? <a href="registro.php">Regístrate</a></p>
  </div>
</body>
</html>
