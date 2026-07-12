<?php
// Módulo Usuarios (superadmin): alta arriba, luego tabla con buscador.

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/csrf.php';

requiere_superadmin();

$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    if (($_POST['accion'] ?? '') === 'crear_usuario') {
        $rol       = $_POST['rol'] ?? 'admin';
        $idNegocio = $rol === 'admin' ? (int)($_POST['id_negocio'] ?? 0) : null;
        $r = crear_usuario($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['nombre_usuario'] ?? '', $rol, $idNegocio ?: null);
        if ($r['exito']) $mensaje = 'Usuario creado correctamente.';
        else             $error   = $r['mensaje'];
    }
}

$usuarios = listar_usuarios();
$negocios = listar_negocios();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$css = '
  .barra-buscar { position: relative; max-width: 340px; margin: 4px 0 14px; }
  .barra-buscar i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--texto-2); font-size: 13px; }
  .barra-buscar .campo { padding-left: 34px; }
  .tabla td { vertical-align: middle; }
  .sin-resultados { display: none; padding: 22px; text-align: center; color: var(--texto-2); }
';
layout_inicio('Usuarios', 'superadmin', 'usuarios', ['css' => $css]);
?>
  <h1 class="contenido__h1">Usuarios</h1>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= h($mensaje) ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= h($error) ?></span></div><?php endif; ?>

  <div class="panel" style="margin-bottom:22px;">
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

  <div class="barra-buscar">
    <i class="fas fa-search"></i>
    <input id="buscar" class="campo" type="search" placeholder="Buscar por correo, nombre o negocio...">
  </div>
  <div class="tabla-scroll"><table class="tabla" id="tabla-usuarios">
    <thead><tr><th>Correo</th><th>Nombre</th><th>Rol</th><th>Negocio</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
      <tr data-buscar="<?= h(mb_strtolower($u['email'] . ' ' . $u['nombre'] . ' ' . ($u['negocio_nombre'] ?? '') . ' ' . $u['rol'], 'UTF-8')) ?>">
        <td><?= h($u['email']) ?></td>
        <td><?= h($u['nombre']) ?></td>
        <td><span class="pill <?= $u['rol'] === 'superadmin' ? 'pill--violeta' : '' ?>"><?= h($u['rol']) ?></span></td>
        <td><?= h($u['negocio_nombre'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="sin-resultados" id="sin-resultados">No hay usuarios que coincidan con la búsqueda.</div>

  <script>
    var rolSel = document.getElementById('rol-sel');
    var negSel = document.getElementById('neg-sel');
    function syncRol() { negSel.style.display = rolSel.value === 'admin' ? '' : 'none'; }
    rolSel.addEventListener('change', syncRol); syncRol();

    var q = document.getElementById('buscar');
    if (q) {
      var filas = Array.prototype.slice.call(document.querySelectorAll('#tabla-usuarios tbody tr[data-buscar]'));
      var sinRes = document.getElementById('sin-resultados');
      q.addEventListener('input', function () {
        var t = this.value.toLowerCase().trim();
        var visibles = 0;
        filas.forEach(function (f) {
          var ok = f.getAttribute('data-buscar').indexOf(t) !== -1;
          f.style.display = ok ? '' : 'none';
          if (ok) visibles++;
        });
        sinRes.style.display = visibles === 0 ? 'block' : 'none';
      });
    }
  </script>
<?php
layout_fin();
