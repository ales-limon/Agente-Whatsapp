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
<title>Iniciar sesión — Agente de WhatsApp</title>
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
  .pie { font-size: 13px; text-align: center; margin: 14px 0 0; color: var(--texto-2); }
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
      <h1>Inicia sesión</h1>
      <p class="sub">Administra tus negocios y conversaciones.</p>

      <?php if ($expirado): ?><div class="alerta alerta--aviso"><i class="fas fa-clock"></i><span>Tu sesión expiró. Inicia sesión de nuevo.</span></div><?php endif; ?>
      <?php if ($reseteado): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span>Contraseña actualizada. Inicia sesión.</span></div><?php endif; ?>
      <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

      <form method="post">
        <?= campo_csrf() ?>
        <label class="etiqueta" for="email">Correo</label>
        <input class="campo" id="email" type="email" name="email" required autofocus>
        <label class="etiqueta" for="password">Contraseña</label>
        <input class="campo" id="password" type="password" name="password" required>
        <button type="submit" class="btn btn--primario btn--bloque">Entrar</button>
      </form>

      <p class="pie"><a href="recuperar.php">¿Olvidaste tu contraseña?</a></p>
      <p class="pie">¿No tienes cuenta? <a href="registro.php">Regístrate</a></p>
    </div>
  </div>
</body>
</html>
