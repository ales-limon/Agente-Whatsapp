<?php
// Superadmin: lista negocios, crea negocios y gestiona usuarios. Solo superadmin.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/uso.php';
require_once __DIR__ . '/csrf.php';

requiere_superadmin();

$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_negocio') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre !== '') {
            $id   = crear_negocio($nombre, trim($_POST['slug'] ?? ''));
            $slug = negocio_por_id($id)['slug'];
            header('Location: configuracion.php?t=' . urlencode($slug));
            exit;
        }
        $error = 'El nombre del negocio es obligatorio.';
    } elseif ($accion === 'crear_usuario') {
        $rol       = $_POST['rol'] ?? 'admin';
        $idNegocio = $rol === 'admin' ? (int)($_POST['id_negocio'] ?? 0) : null;
        $r = crear_usuario($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['nombre_usuario'] ?? '', $rol, $idNegocio ?: null);
        if ($r['exito']) $mensaje = 'Usuario creado correctamente.';
        else             $error = $r['mensaje'];
    } elseif ($accion === 'asignar_numero') {
        $r = asignar_numero((int)($_POST['id_negocio'] ?? 0), $_POST['numero'] ?? '');
        if ($r['exito']) $mensaje = 'Número asignado correctamente.';
        else             $error = $r['mensaje'];
    } elseif ($accion === 'fijar_limite') {
        fijar_limite_mensajes((int)($_POST['id_negocio'] ?? 0), (int)($_POST['limite'] ?? 0));
        $mensaje = 'Límite mensual actualizado.';
    }
}

$negocios = listar_negocios();
$usuarios = listar_usuarios();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Negocios — Agente de WhatsApp</title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  .barra { background: var(--marca); padding: 0 24px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
  .barra .marca__nombre { color: #fff; }
  .barra .marca__icono { background: rgba(255,255,255,.12); }
  .barra__right { font-size: 13px; color: #B6CDD4; }
  .barra__right a { color: #fff; text-decoration: none; margin-left: 14px; }
  .barra__right a:hover { text-decoration: underline; }
  .contenedor { max-width: 880px; margin: 0 auto; padding: 28px 24px; }
  h2 { font-family: var(--fuente-titulo); font-size: 17px; font-weight: 700; margin: 28px 0 14px; }
  .tarjeta { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 16px 18px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
  .tarjeta .nombre { font-size: 16px; font-weight: 600; }
  .tarjeta .meta { font-size: 13px; color: var(--texto-2); margin-top: 3px; }
  .tarjeta .links a { font-size: 13px; color: var(--marca); text-decoration: none; margin-left: 12px; font-weight: 500; }
  .tarjeta .links a:hover { text-decoration: underline; }
  .vacio { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 22px; text-align: center; color: var(--texto-2); margin-bottom: 12px; }
  .panel { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 18px 20px; }
  .panel h3 { font-family: var(--fuente-titulo); font-size: 15px; font-weight: 700; margin: 0 0 12px; }
  .panel input, .panel select { padding: 10px 12px; border: 1.5px solid var(--borde); border-radius: var(--radio); font-size: 14px; font-family: var(--fuente-cuerpo); color: var(--tinta); background: var(--superficie); margin: 0 6px 8px 0; }
  .input-mini { padding: 7px 10px; border: 1.5px solid var(--borde); border-radius: var(--radio-sm); font-size: 13px; font-family: var(--fuente-cuerpo); color: var(--tinta); background: var(--superficie); }
  .btn-mini { padding: 7px 12px; border: 1.5px solid var(--borde); background: var(--superficie); color: var(--tinta); border-radius: var(--radio-sm); font-size: 13px; cursor: pointer; }
  .btn-mini:hover { background: #F4F8F9; }
  table.us { width: 100%; border-collapse: collapse; background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); overflow: hidden; margin-bottom: 12px; }
  table.us th, table.us td { text-align: left; padding: 10px 14px; font-size: 13px; border-bottom: 1px solid var(--borde); }
  table.us th { background: #F4F8F9; color: var(--texto-2); font-weight: 600; font-size: 12px; }
  table.us tr:last-child td { border-bottom: 0; }
  .pill { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 999px; background: var(--badge-bg); color: var(--badge-texto); }
  .pill.sa { background: #ECE9FB; color: #534AB7; }
</style>
</head>
<body>
  <header class="barra">
    <div class="marca">
      <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
      <span class="marca__nombre">Agente de WhatsApp</span>
    </div>
    <div class="barra__right">
      <?= h(usuario_actual()['email']) ?>
      <a href="logout.php">Salir</a>
    </div>
  </header>

  <div class="contenedor">
    <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= h($mensaje) ?></span></div><?php endif; ?>
    <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= h($error) ?></span></div><?php endif; ?>

    <h2 style="margin-top:8px;">Negocios</h2>
    <?php if (!$negocios): ?>
      <div class="vacio">Aún no hay negocios. Crea el primero abajo.</div>
    <?php else: foreach ($negocios as $n): $slug = h($n['slug']);
        $lim = (int)($n['limite_mensajes_mes'] ?? 0);
        $u   = uso_mes((int)$n['id']); ?>
      <div class="tarjeta">
        <div>
          <div class="nombre"><?= h($n['nombre']) ?></div>
          <div class="meta">slug: <?= $slug ?> · uso del mes: <?= (int)$u['mensajes'] ?> msj<?= $lim > 0 ? ' / ' . $lim : ' (ilimitado)' ?> · <?= number_format((int)$u['tokens_entrada'] + (int)$u['tokens_salida']) ?> tokens</div>
          <form method="post" style="margin-top:8px; display:flex; gap:6px; align-items:center;">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="asignar_numero">
            <input type="hidden" name="id_negocio" value="<?= (int)$n['id'] ?>">
            <input class="input-mini" type="text" name="numero" value="<?= h($n['numero_whatsapp']) ?>" placeholder="+5213...">
            <button class="btn-mini" type="submit">Asignar número</button>
          </form>
          <form method="post" style="margin-top:6px; display:flex; gap:6px; align-items:center;">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="fijar_limite">
            <input type="hidden" name="id_negocio" value="<?= (int)$n['id'] ?>">
            <input class="input-mini" style="width:110px;" type="number" name="limite" min="0" value="<?= $lim ?>">
            <button class="btn-mini" type="submit">Límite/mes (0 = ilimitado)</button>
          </form>
        </div>
        <div class="links">
          <a href="panel.php?t=<?= $slug ?>">Citas</a>
          <a href="configuracion.php?t=<?= $slug ?>">Configurar</a>
          <a href="chat.php?t=<?= $slug ?>">Probar</a>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <div class="panel">
      <h3>Nuevo negocio</h3>
      <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear_negocio">
        <input type="text" name="nombre" placeholder="Nombre del negocio" required>
        <input type="text" name="slug" placeholder="slug (opcional)">
        <button class="btn btn--primario" type="submit">Crear y configurar</button>
      </form>
    </div>

    <h2>Usuarios</h2>
    <table class="us">
      <thead><tr><th>Correo</th><th>Nombre</th><th>Rol</th><th>Negocio</th></tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td><?= h($u['email']) ?></td>
          <td><?= h($u['nombre']) ?></td>
          <td><span class="pill <?= $u['rol'] === 'superadmin' ? 'sa' : '' ?>"><?= h($u['rol']) ?></span></td>
          <td><?= h($u['negocio_nombre'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="panel">
      <h3>Nuevo usuario (dueño de un negocio)</h3>
      <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear_usuario">
        <input type="text" name="nombre_usuario" placeholder="Nombre" required>
        <input type="email" name="email" placeholder="Correo" required>
        <input type="password" name="password" placeholder="Contraseña (8+)" required minlength="8">
        <select name="rol" id="rol-sel">
          <option value="admin">Admin de negocio</option>
          <option value="superadmin">Superadmin</option>
        </select>
        <select name="id_negocio" id="neg-sel">
          <?php foreach ($negocios as $n): ?>
            <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--primario" type="submit">Crear usuario</button>
      </form>
    </div>
  </div>

<script>
  // Ocultar el selector de negocio cuando el rol es superadmin
  var rolSel = document.getElementById('rol-sel');
  var negSel = document.getElementById('neg-sel');
  function syncRol() { negSel.style.display = rolSel.value === 'admin' ? '' : 'none'; }
  rolSel.addEventListener('change', syncRol); syncRol();
</script>
</body>
</html>
