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
<title>Crear cuenta — Agente de WhatsApp</title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  .pantalla { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .caja { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio-lg);
          box-shadow: var(--sombra); padding: 32px 32px 28px; width: 100%; max-width: 400px; }
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
      <h1>Crea tu cuenta</h1>
      <p class="sub">Registra tu negocio y empieza a recibir citas por WhatsApp.</p>

      <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

      <form method="post">
        <?= campo_csrf() ?>
        <label class="etiqueta" for="negocio">Nombre del negocio</label>
        <input class="campo" id="negocio" type="text" name="negocio" required autofocus value="<?= htmlspecialchars($_POST['negocio'] ?? '', ENT_QUOTES) ?>">
        <label class="etiqueta" for="nombre">Tu nombre</label>
        <input class="campo" id="nombre" type="text" name="nombre" required value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES) ?>">
        <label class="etiqueta" for="email">Correo</label>
        <input class="campo" id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
        <label class="etiqueta" for="password">Contraseña (mínimo 8 caracteres)</label>
        <input class="campo" id="password" type="password" name="password" required minlength="8">
        <label class="etiqueta" for="password2">Repite la contraseña</label>
        <input class="campo" id="password2" type="password" name="password2" required minlength="8">
        <button type="submit" class="btn btn--primario btn--bloque">Crear cuenta</button>
      </form>

      <p class="pie">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
    </div>
  </div>
</body>
</html>
