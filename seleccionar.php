<?php
// Menú "¿a dónde quieres ir?" tras iniciar sesión.
// Aparece cuando el usuario tiene más de un destino: superadmin (panel + negocios)
// o un admin con varios negocios. Si solo hay un destino, ruteamos directo.

require_once __DIR__ . '/src/auth.php';

requiere_autenticacion();

// El superadmin va a su panel (administra todo desde ahí). Este menú "elige tu negocio"
// es SOLO para clientes que tienen varios negocios PROPIOS.
if (es_superadmin()) { header('Location: superadmin.php'); exit; }

$negs = negocios_del_usuario();

// Sin nada que elegir: ruteo directo (no mostramos un menú de una sola opción).
if (count($negs) === 1) { header('Location: panel.php?t=' . urlencode($negs[0]['slug'])); exit; }
if (count($negs) === 0) { header('Location: login.php'); exit; }

$u = usuario_actual();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>¿A dónde quieres ir? — Agente de WhatsApp</title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  .pantalla { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .caja { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio-lg);
          box-shadow: var(--sombra); padding: 30px 30px 24px; width: 100%; max-width: 460px; }
  .caja .marca { margin-bottom: 20px; }
  .caja h1 { font-size: 22px; margin: 0 0 4px; }
  .caja .sub { font-size: 14px; color: var(--texto-2); margin: 0 0 22px; }
  .opcion { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid var(--borde);
            border-radius: var(--radio); text-decoration: none; color: var(--tinta); margin-bottom: 10px;
            transition: border-color .15s ease, background-color .15s ease; }
  .opcion:hover { border-color: var(--marca); background: #F4F8F9; text-decoration: none; }
  .opcion__ico { width: 42px; height: 42px; border-radius: 11px; background: var(--badge-bg); color: var(--marca);
                 display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
  .opcion__txt { display: flex; flex-direction: column; }
  .opcion__t { font-weight: 600; font-size: 15px; }
  .opcion__s { font-size: 13px; color: var(--texto-2); }
  .opcion__arrow { margin-left: auto; color: var(--texto-2); }
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
      <h1>Hola, <?= h($u['nombre']) ?></h1>
      <p class="sub">Elige el negocio al que quieres entrar.</p>

      <?php foreach ($negs as $n): ?>
        <a class="opcion" href="panel.php?t=<?= urlencode($n['slug']) ?>">
          <span class="opcion__ico"><i class="fas fa-store"></i></span>
          <span class="opcion__txt">
            <span class="opcion__t"><?= h($n['nombre']) ?></span>
            <span class="opcion__s">Citas y conversaciones del negocio</span>
          </span>
          <span class="opcion__arrow"><i class="fas fa-chevron-right"></i></span>
        </a>
      <?php endforeach; ?>

      <p class="pie"><a href="logout.php">Cerrar sesión</a></p>
    </div>
  </div>
</body>
</html>
