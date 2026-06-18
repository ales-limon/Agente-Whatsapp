<?php
// Solicitar recuperacion de contraseña. Manda un enlace con token (1h de vida).

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/correo.php';
require_once __DIR__ . '/csrf.php';

aplicar_headers_seguridad();
iniciar_sesion_segura();

$enviado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $email = trim($_POST['email'] ?? '');
    $r = solicitar_reset($email);
    if (!empty($r['exito'])) {
        $enlace = base_url() . '/restablecer.php?token=' . $r['token'];
        enviar_correo(
            $email,
            'Recupera tu contraseña',
            "Hola,\n\nPara crear una nueva contraseña abre este enlace (válido 1 hora):\n$enlace\n\nSi no fuiste tú, ignora este correo."
        );
    }
    // Mensaje generico SIEMPRE (no revelar si el correo existe o no).
    $enviado = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar contraseña — Agente de WhatsApp</title>
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
      <h1>Recuperar contraseña</h1>
      <?php if ($enviado): ?>
        <div class="alerta alerta--ok"><i class="fas fa-envelope"></i><span>Si ese correo está registrado, te enviamos un enlace para crear una nueva contraseña. Revisa tu bandeja.</span></div>
        <p class="pie"><a href="login.php">Volver a iniciar sesión</a></p>
      <?php else: ?>
        <p class="sub">Te enviaremos un enlace para crear una nueva contraseña.</p>
        <form method="post">
          <?= campo_csrf() ?>
          <label class="etiqueta" for="email">Correo</label>
          <input class="campo" id="email" type="email" name="email" required autofocus>
          <button type="submit" class="btn btn--primario btn--bloque">Enviar enlace</button>
        </form>
        <p class="pie"><a href="login.php">Volver a iniciar sesión</a></p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
