<?php
// Directorio de clientes conocidos (para negocios A DOMICILIO). El dueño da de alta
// a sus clientes con su zona y dirección; el agente los identifica por su número.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/domicilio.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/csrf.php';
cargar_entorno();
aplicar_headers_seguridad();

$slug    = $_GET['t'] ?? '';
$negocio = $slug !== '' ? negocio_por_slug($slug) : primer_negocio();
if (!$negocio) {
    echo 'No hay negocios. Crea uno en <a href="superadmin.php">superadmin</a>.';
    exit;
}
$idNegocio = (int)$negocio['id'];
requiere_acceso_negocio($idNegocio);
$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear_cliente') {
        $r = crear_cliente(
            $idNegocio,
            $_POST['nombre'] ?? '', $_POST['numero'] ?? '', $_POST['zona'] ?? '',
            $_POST['colonia'] ?? '', $_POST['cp'] ?? '',
            $_POST['direccion'] ?? '', $_POST['notas'] ?? ''
        );
        if ($r['exito']) $mensaje = 'Cliente agregado.';
        else             $error   = $r['mensaje'];
    } elseif ($accion === 'borrar_cliente') {
        borrar_cliente((int)($_POST['id'] ?? 0), $idNegocio);
        $mensaje = 'Cliente eliminado.';
    }
}

$zonas    = listar_zonas($idNegocio);
$clientes = listar_clientes($idNegocio);
// Mapa CP -> nombre de zona, para autodetectar la zona al elegir una colonia.
$zonaPorCp = [];
foreach ($zonas as $z) {
    foreach ($z['colonias'] as $col) {
        if (!empty($col['cp'])) $zonaPorCp[(string)$col['cp']] = $z['nombre'];
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$css = '
  .barra-buscar { position: relative; max-width: 340px; margin: 4px 0 16px; }
  .barra-buscar i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--texto-2); font-size: 13px; }
  .barra-buscar input { padding-left: 34px; }
  .alta { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 16px 18px; margin-bottom: 22px; }
  .alta h2 { font-family: var(--fuente-titulo); font-size: 15px; margin: 0 0 12px; }
  .alta .fila { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .alta .campo-mini { display: flex; flex-direction: column; gap: 4px; }
  .alta label { font-size: 12px; color: var(--texto-2); font-weight: 600; }
  .alta input, .alta select { padding: 9px 11px; border: 1.5px solid var(--borde); border-radius: var(--radio); font-size: 14px; font-family: var(--fuente-cuerpo); color: var(--tinta); background: var(--superficie); }
  .tabla-cli { width: 100%; border-collapse: collapse; background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); overflow: hidden; }
  .tabla-cli th { text-align: left; font-size: 12px; color: var(--texto-2); font-weight: 600; padding: 10px 12px; border-bottom: 1px solid var(--borde); }
  .tabla-cli td { padding: 10px 12px; border-bottom: 1px solid var(--borde); font-size: 14px; vertical-align: middle; }
  .tabla-cli tr:last-child td { border-bottom: 0; }
  .pill-zona { background: var(--badge-bg); color: var(--marca); font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
  .cli-info { font-size: 13px; color: var(--texto-2); margin: 0 0 14px; }
  .col-sug { position: absolute; top: 100%; left: 0; right: 0; z-index: 20; background: var(--superficie); border: 1px solid var(--borde); border-top: 0; border-radius: 0 0 var(--radio) var(--radio); max-height: 240px; overflow-y: auto; box-shadow: 0 8px 24px rgba(10,27,34,.14); }
  .col-sug:empty { display: none; }
  .col-op { padding: 8px 11px; font-size: 13px; color: var(--tinta); cursor: pointer; border-bottom: 1px solid var(--borde); }
  .col-op:last-child { border-bottom: 0; }
  .col-op:hover { background: var(--badge-bg); }
  .col-op small { color: var(--texto-2); }
';

layout_inicio('Clientes', 'negocio', 'clientes', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Directorio de clientes</h1>
  <p class="cli-info">Da de alta a tus clientes conocidos con su <strong>zona</strong> y <strong>dirección</strong>. El agente los reconoce por su WhatsApp y solo agenda a domicilio a quienes ya están aquí. Los números desconocidos se te escalan para que tú los apruebes.</p>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= h($mensaje) ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= h($error) ?></span></div><?php endif; ?>

  <div class="alta">
    <h2>Agregar cliente</h2>
    <form method="post" class="fila">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="crear_cliente">
      <div class="campo-mini"><label>Nombre</label><input type="text" name="nombre" required style="width:170px;"></div>
      <div class="campo-mini"><label>WhatsApp</label><input type="text" name="numero" required placeholder="+5215512345678" style="width:160px;"></div>
      <div class="campo-mini" style="position:relative;"><label>Colonia (buscar por nombre o CP)</label>
        <input type="text" id="col-buscar" autocomplete="off" placeholder="Ej. Providencia o 44630" style="width:210px;">
        <input type="hidden" name="colonia" id="col-nombre">
        <input type="hidden" name="cp" id="col-cp">
        <div id="col-sug" class="col-sug"></div>
      </div>
      <div class="campo-mini"><label>Zona</label>
        <select name="zona" id="sel-zona" style="width:140px;">
          <option value="">— Sin zona —</option>
          <?php foreach ($zonas as $z): ?><option value="<?= h($z['nombre']) ?>"><?= h($z['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo-mini"><label>Calle y número</label><input type="text" name="direccion" placeholder="Calle, número, referencias" style="width:210px;"></div>
      <div class="campo-mini"><label>Notas (opcional)</label><input type="text" name="notas" style="width:150px;"></div>
      <button class="btn btn--primario" type="submit">Agregar</button>
    </form>
    <?php if (!$zonas): ?><div class="hint" style="margin-top:10px; font-size:12px; color:var(--texto-2);">Aún no tienes zonas. Créalas en <a href="configuracion.php?t=<?= h($negocio['slug']) ?>">Configuración → Zonas</a> para poder asignarlas.</div><?php endif; ?>
  </div>

  <?php if ($clientes): ?>
    <div class="barra-buscar"><i class="fas fa-search"></i><input type="text" id="buscar" placeholder="Buscar cliente..." autocomplete="off"></div>
    <table class="tabla-cli" id="tabla-cli">
      <thead>
        <tr><th>Nombre</th><th>WhatsApp</th><th>Zona</th><th>Dirección</th><th>Notas</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($clientes as $cl): ?>
        <tr data-buscar="<?= h(mb_strtolower($cl['nombre'] . ' ' . $cl['numero'] . ' ' . ($cl['zona'] ?? '') . ' ' . ($cl['direccion'] ?? ''), 'UTF-8')) ?>">
          <td><?= h($cl['nombre']) ?></td>
          <td><?= h($cl['numero']) ?></td>
          <td><?= $cl['zona'] ? '<span class="pill-zona">' . h($cl['zona']) . '</span>' : '<span style="color:var(--texto-2)">—</span>' ?></td>
          <td><?= h($cl['direccion']) ?: '<span style="color:var(--texto-2)">—</span>' ?></td>
          <td><?= h($cl['notas']) ?: '<span style="color:var(--texto-2)">—</span>' ?></td>
          <td style="text-align:right;">
            <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar a este cliente?');">
              <?= campo_csrf() ?>
              <input type="hidden" name="accion" value="borrar_cliente">
              <input type="hidden" name="id" value="<?= (int)$cl['id'] ?>">
              <button class="btn-x" type="submit" style="border:0;background:none;color:var(--error-texto);cursor:pointer;font-size:16px;">&times;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div id="sin-res" style="display:none; color:var(--texto-2); padding:12px 0;">No hay clientes que coincidan.</div>
  <?php else: ?>
    <div class="vacio">Aún no tienes clientes en el directorio.</div>
  <?php endif; ?>

<script>
  var q = document.getElementById('buscar');
  if (q) {
    var filas = Array.prototype.slice.call(document.querySelectorAll('#tabla-cli tbody tr'));
    var sinRes = document.getElementById('sin-res');
    q.addEventListener('input', function () {
      var t = this.value.toLowerCase().trim();
      var vis = 0;
      filas.forEach(function (f) {
        var ok = f.getAttribute('data-buscar').indexOf(t) !== -1;
        f.style.display = ok ? '' : 'none';
        if (ok) vis++;
      });
      sinRes.style.display = vis === 0 ? 'block' : 'none';
    });
  }

  // Buscador de colonias (SEPOMEX) con autocompletado + auto-selección de zona.
  var ZONA_POR_CP = <?= json_encode($zonaPorCp, JSON_UNESCAPED_UNICODE) ?>;
  (function () {
    var inp = document.getElementById('col-buscar');
    var sug = document.getElementById('col-sug');
    if (!inp) return;
    var timer;
    inp.addEventListener('input', function () {
      clearTimeout(timer);
      document.getElementById('col-nombre').value = '';
      document.getElementById('col-cp').value = '';
      var qq = this.value.trim();
      if (qq.length < 3) { sug.innerHTML = ''; return; }
      timer = setTimeout(function () {
        fetch('buscar_colonia.php?q=' + encodeURIComponent(qq))
          .then(function (r) { return r.json(); })
          .then(function (list) {
            sug.innerHTML = (list || []).map(function (c) {
              return '<div class="col-op" data-cp="' + c.cp + '" data-col="' + String(c.colonia).replace(/"/g, '&quot;') + '">' +
                c.colonia + ' <small>— ' + c.municipio + ', CP ' + c.cp + '</small></div>';
            }).join('');
          }).catch(function () { sug.innerHTML = ''; });
      }, 250);
    });
    sug.addEventListener('click', function (e) {
      var op = e.target.closest('.col-op'); if (!op) return;
      var cp = op.getAttribute('data-cp'), col = op.getAttribute('data-col');
      document.getElementById('col-nombre').value = col;
      document.getElementById('col-cp').value = cp;
      inp.value = col + ' (CP ' + cp + ')';
      sug.innerHTML = '';
      var z = ZONA_POR_CP[cp];
      if (z) { var sel = document.getElementById('sel-zona'); if (sel) { for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value === z) { sel.selectedIndex = i; break; } } } }
    });
    document.addEventListener('click', function (e) { if (!inp.contains(e.target) && !sug.contains(e.target)) sug.innerHTML = ''; });
  })();
</script>
<?php
layout_fin();
