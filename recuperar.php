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
<title>Recuperar contraseña</title>
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
  .aviso { background: #eaf3de; color: #3b6d11; border: 1px solid #c0dd97; border-radius: 6px; padding: 11px 14px; font-size: 14px; }
  .pie { font-size: 13px; text-align: center; margin-top: 16px; color: #6a6a64; }
  .pie a { color: #075e54; }
</style>
</head>
<body>
  <div class="caja">
    <h1>Recuperar contraseña</h1>
    <?php if ($enviado): ?>
      <div class="aviso">Si ese correo está registrado, te enviamos un enlace para crear una nueva contraseña. Revisa tu bandeja.</div>
      <p class="pie"><a href="login.php">Volver a iniciar sesión</a></p>
    <?php else: ?>
      <p class="sub">Te enviaremos un enlace para crear una nueva contraseña.</p>
      <form method="post">
        <?= campo_csrf() ?>
        <label>Correo</label>
        <input type="email" name="email" required autofocus>
        <button type="submit">Enviar enlace</button>
      </form>
      <p class="pie"><a href="login.php">Volver a iniciar sesión</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
