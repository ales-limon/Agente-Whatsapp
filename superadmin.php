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
<title>Negocios — Agente WhatsApp</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a; }
  header { background: #075e54; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 18px; font-weight: 500; }
  header .right { font-size: 13px; }
  header .right a { color: #cfeae3; text-decoration: none; margin-left: 14px; }
  header .right a:hover { text-decoration: underline; }
  .contenedor { max-width: 860px; margin: 0 auto; padding: 24px; }
  .aviso { background: #eaf3de; color: #3b6d11; border: 1px solid #c0dd97; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; }
  .error { background: #fcebeb; color: #791f1f; border: 1px solid #f0a8a8; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; }
  h2 { font-size: 16px; font-weight: 500; margin: 26px 0 12px; }
  .tarjeta { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 16px 18px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
  .tarjeta .nombre { font-size: 16px; font-weight: 500; }
  .tarjeta .meta { font-size: 13px; color: #6a6a64; margin-top: 2px; }
  .tarjeta .links a { font-size: 13px; color: #075e54; text-decoration: none; margin-left: 12px; }
  .tarjeta .links a:hover { text-decoration: underline; }
  .vacio { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 22px; text-align: center; color: #888; margin-bottom: 12px; }
  .panel { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 18px 20px; }
  .panel h3 { font-size: 14px; font-weight: 500; margin: 0 0 12px; }
  .panel input, .panel select { padding: 9px 11px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; margin: 0 6px 8px 0; }
  .panel button { background: #075e54; color: #fff; border: 0; border-radius: 6px; padding: 9px 16px; font-size: 14px; cursor: pointer; }
  .panel button:hover { background: #064a42; }
  table.us { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; overflow: hidden; margin-bottom: 12px; }
  table.us th, table.us td { text-align: left; padding: 9px 14px; font-size: 13px; border-bottom: 1px solid #f0f0ec; }
  table.us th { background: #fafaf8; color: #6a6a64; font-weight: 500; font-size: 12px; }
  table.us tr:last-child td { border-bottom: 0; }
  .pill { font-size: 11px; padding: 2px 8px; border-radius: 5px; background: #e1f5ee; color: #0f6e56; }
  .pill.sa { background: #eee8fb; color: #534ab7; }
</style>
</head>
<body>
  <header>
    <h1>Negocios</h1>
    <div class="right">
      <?= h(usuario_actual()['email']) ?>
      <a href="logout.php">Salir</a>
    </div>
  </header>

  <div class="contenedor">
    <?php if ($mensaje): ?><div class="aviso"><?= h($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

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
            <input type="text" name="numero" value="<?= h($n['numero_whatsapp']) ?>" placeholder="+5213..." style="padding:6px 9px; border:1px solid #d6d6d0; border-radius:6px; font-size:13px;">
            <button type="submit" style="padding:6px 12px; border:1px solid #d6d6d0; background:#fff; border-radius:6px; font-size:13px; cursor:pointer;">Asignar número</button>
          </form>
          <form method="post" style="margin-top:6px; display:flex; gap:6px; align-items:center;">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="fijar_limite">
            <input type="hidden" name="id_negocio" value="<?= (int)$n['id'] ?>">
            <input type="number" name="limite" min="0" value="<?= $lim ?>" style="width:110px; padding:6px 9px; border:1px solid #d6d6d0; border-radius:6px; font-size:13px;">
            <button type="submit" style="padding:6px 12px; border:1px solid #d6d6d0; background:#fff; border-radius:6px; font-size:13px; cursor:pointer;">Límite/mes (0 = ilimitado)</button>
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
        <button type="submit">Crear y configurar</button>
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
        <button type="submit">Crear usuario</button>
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
