<?php
// Módulo Negocios (superadmin): alta arriba, luego tabla con buscador.
// Edición de número y límite por fila (acción combinada).

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/uso.php';
require_once __DIR__ . '/src/layout.php';
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
    } elseif ($accion === 'guardar_negocio') {
        $idn  = (int)($_POST['id_negocio'] ?? 0);
        $rNum = asignar_numero($idn, $_POST['numero'] ?? '');
        fijar_limite_mensajes($idn, (int)($_POST['limite'] ?? 0));
        if ($rNum['exito']) $mensaje = 'Negocio actualizado.';
        else                $error   = $rNum['mensaje'];
    }
}

$negocios = listar_negocios();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$css = '
  .barra-buscar { position: relative; max-width: 340px; margin: 4px 0 14px; }
  .barra-buscar i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--texto-2); font-size: 13px; }
  .barra-buscar .campo { padding-left: 34px; }
  .tabla td { vertical-align: middle; }
  .neg-nom { font-weight: 600; font-size: 14px; }
  .neg-slug { font-size: 12px; color: var(--texto-2); }
  .ajustes { display: flex; gap: 6px; align-items: center; }
  .acc a { color: var(--marca); text-decoration: none; font-weight: 500; white-space: nowrap; }
  .acc a:hover { text-decoration: underline; }
  .acc a + a { margin-left: 12px; }
  .sin-resultados { display: none; padding: 22px; text-align: center; color: var(--texto-2); }
';
layout_inicio('Negocios', 'superadmin', 'negocios', ['css' => $css]);
?>
  <h1 class="contenido__h1">Negocios</h1>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= h($mensaje) ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= h($error) ?></span></div><?php endif; ?>

  <div class="panel" style="margin-bottom:22px;">
    <h3>Nuevo negocio</h3>
    <form method="post">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="crear_negocio">
      <input type="text" name="nombre" placeholder="Nombre del negocio" required>
      <input type="text" name="slug" placeholder="slug (opcional)">
      <button class="btn btn--primario" type="submit">Crear y configurar</button>
    </form>
  </div>

  <?php if (!$negocios): ?>
    <div class="vacio">Aún no hay negocios. Crea el primero arriba.</div>
  <?php else: ?>
    <div class="barra-buscar">
      <i class="fas fa-search"></i>
      <input id="buscar" class="campo" type="search" placeholder="Buscar negocio por nombre o slug...">
    </div>
    <table class="tabla" id="tabla-negocios">
      <thead>
        <tr><th>Negocio</th><th>Uso del mes</th><th>Número y límite</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($negocios as $n): $slug = h($n['slug']);
          $lim = (int)($n['limite_mensajes_mes'] ?? 0);
          $u   = uso_mes((int)$n['id']); ?>
        <tr data-buscar="<?= h(mb_strtolower($n['nombre'] . ' ' . $n['slug'], 'UTF-8')) ?>">
          <td>
            <div class="neg-nom"><?= h($n['nombre']) ?></div>
            <div class="neg-slug"><?= $slug ?></div>
          </td>
          <td>
            <?= (int)$u['mensajes'] ?> msj<?= $lim > 0 ? ' / ' . $lim : '' ?><br>
            <span class="neg-slug"><?= number_format((int)$u['tokens_entrada'] + (int)$u['tokens_salida']) ?> tokens<?= $lim > 0 ? '' : ' · ilimitado' ?></span>
          </td>
          <td>
            <form method="post" class="ajustes">
              <?= campo_csrf() ?>
              <input type="hidden" name="accion" value="guardar_negocio">
              <input type="hidden" name="id_negocio" value="<?= (int)$n['id'] ?>">
              <input class="input-mini" type="text" name="numero" value="<?= h($n['numero_whatsapp']) ?>" placeholder="+5213..." style="width:140px;">
              <input class="input-mini" type="number" name="limite" min="0" value="<?= $lim ?>" style="width:66px;" title="Límite de mensajes al mes (0 = ilimitado)">
              <button class="btn-mini" type="submit">Guardar</button>
            </form>
          </td>
          <td class="acc">
            <a href="panel.php?t=<?= $slug ?>">Citas</a>
            <a href="configuracion.php?t=<?= $slug ?>">Configurar</a>
            <a href="chat.php?t=<?= $slug ?>">Probar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sin-resultados" id="sin-resultados">No hay negocios que coincidan con la búsqueda.</div>
  <?php endif; ?>

  <script>
    var q = document.getElementById('buscar');
    if (q) {
      var filas = Array.prototype.slice.call(document.querySelectorAll('#tabla-negocios tbody tr[data-buscar]'));
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
