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
<title>Configuración inicial — Agente de WhatsApp</title>
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
</style>
</head>
<body>
  <div class="pantalla">
    <div class="caja">
      <div class="marca">
        <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
        <span class="marca__nombre">Agente de WhatsApp</span>
      </div>
      <h1>Crear superadmin</h1>
      <p class="sub">Esta es la cuenta dueña del sistema. Solo se crea una vez.</p>
      <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
      <form method="post">
        <?= campo_csrf() ?>
        <label class="etiqueta" for="nombre">Nombre</label>
        <input class="campo" id="nombre" type="text" name="nombre" required autofocus>
        <label class="etiqueta" for="email">Correo</label>
        <input class="campo" id="email" type="email" name="email" required>
        <label class="etiqueta" for="password">Contraseña (mínimo 8 caracteres)</label>
        <input class="campo" id="password" type="password" name="password" required minlength="8">
        <button type="submit" class="btn btn--primario btn--bloque">Crear superadmin</button>
      </form>
    </div>
  </div>
</body>
</html>
