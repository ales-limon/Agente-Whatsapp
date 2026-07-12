<?php
// Ficha de un cliente (?t=slug&id=N): editar sus datos y ver su historial de pagos
// (citas ligadas por id_cliente). Solo para negocios A DOMICILIO.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/domicilio.php';
require_once __DIR__ . '/src/caja.php';
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

$idCliente = (int)($_GET['id'] ?? 0);
$cliente   = $idCliente ? obtener_cliente($idCliente, $idNegocio) : null;
$mensaje = '';
$error   = '';

if ($cliente && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'actualizar') {
        $r = actualizar_cliente(
            $idCliente, $idNegocio,
            $_POST['nombre'] ?? '', $_POST['numero'] ?? '', $_POST['zona'] ?? '',
            $_POST['colonia'] ?? '', $_POST['cp'] ?? '',
            $_POST['direccion'] ?? '', $_POST['notas'] ?? ''
        );
        if ($r['exito']) { $mensaje = 'Cambios guardados.'; $cliente = obtener_cliente($idCliente, $idNegocio); }
        else             { $error   = $r['mensaje']; }
    } elseif ($accion === 'borrar_cliente') {
        borrar_cliente($idCliente, $idNegocio);
        header('Location: clientes.php?t=' . urlencode($negocio['slug']));
        exit;
    } elseif ($accion === 'aprobar') {
        aprobar_cliente($idCliente, $idNegocio);
        $mensaje = 'Cliente aprobado. Ya puede agendar a domicilio.';
        $cliente = obtener_cliente($idCliente, $idNegocio);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- Layout / cliente no encontrado ---
$css = '
  .ficha-vol { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--texto-2); text-decoration: none; margin-bottom: 10px; }
  .ficha-vol:hover { color: var(--marca); }
  .ficha-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 22px; align-items: start; }
  @media (max-width: 860px) { .ficha-grid { grid-template-columns: 1fr; } }
  .tarjeta { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 18px 20px; }
  .tarjeta h2 { font-family: var(--fuente-titulo); font-size: 15px; margin: 0 0 14px; }
  .campo { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; position: relative; }
  .campo label { font-size: 12px; color: var(--texto-2); font-weight: 600; }
  .campo input, .campo select { padding: 9px 11px; border: 1.5px solid var(--borde); border-radius: var(--radio); font-size: 14px; font-family: var(--fuente-cuerpo); color: var(--tinta); background: var(--superficie); width: 100%; box-sizing: border-box; }
  .campo-fila { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .acciones { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
  .resumen-pagos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
  .rp-card { background: var(--badge-bg); border: 1px solid var(--borde); border-radius: var(--radio); padding: 12px; text-align: center; }
  .rp-card small { color: var(--texto-2); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
  .rp-card .rp-val { font-family: var(--fuente-titulo); font-size: 20px; font-weight: 700; color: var(--tinta); margin-top: 3px; }
  .tabla-pagos { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .tabla-pagos th { text-align: left; font-size: 11.5px; color: var(--texto-2); font-weight: 600; padding: 8px 8px; border-bottom: 1px solid var(--borde); }
  .tabla-pagos td { padding: 9px 8px; border-bottom: 1px solid var(--borde); vertical-align: middle; }
  .tabla-pagos tr:last-child td { border-bottom: 0; }
  .pill-pagado { display: inline-flex; align-items: center; gap: 5px; background: #E5F3EC; color: #1E6B4B; font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 999px; }
  .pill-pend { display: inline-flex; align-items: center; gap: 5px; background: var(--badge-bg); color: var(--texto-2); font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 999px; }
  .col-sug { position: absolute; top: 100%; left: 0; right: 0; z-index: 20; background: var(--superficie); border: 1px solid var(--borde); border-top: 0; border-radius: 0 0 var(--radio) var(--radio); max-height: 240px; overflow-y: auto; box-shadow: 0 8px 24px rgba(10,27,34,.14); }
  .col-sug:empty { display: none; }
  .col-op { padding: 8px 11px; font-size: 13px; color: var(--tinta); cursor: pointer; border-bottom: 1px solid var(--borde); }
  .col-op:last-child { border-bottom: 0; }
  .col-op:hover { background: var(--badge-bg); }
  .col-op small { color: var(--texto-2); }
  .col-vacio { padding: 9px 11px; font-size: 12px; color: var(--texto-2); }
  .aviso-aprobar { display: flex; align-items: center; gap: 16px; justify-content: space-between; flex-wrap: wrap; background: #FDF3D6; border: 1px solid #EAD79B; color: #6E560F; border-radius: var(--radio); padding: 12px 16px; margin: 4px 0 16px; font-size: 13.5px; }
  .aviso-aprobar .btn { white-space: nowrap; }
';

layout_inicio('Cliente', 'negocio', 'clientes', ['negocio' => $negocio, 'css' => $css]);

if (!$cliente) {
    echo '<a class="ficha-vol" href="clientes.php?t=' . h($negocio['slug']) . '"><i class="fas fa-arrow-left"></i> Volver al directorio</a>';
    echo '<div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span>Cliente no encontrado.</span></div>';
    layout_fin();
    exit;
}

$zonas = listar_zonas($idNegocio);
$coloniasZonas = [];
foreach ($zonas as $z) {
    foreach ($z['colonias'] as $col) {
        $coloniasZonas[] = [
            'cp' => (string)($col['cp'] ?? ''), 'colonia' => (string)($col['colonia'] ?? ''),
            'municipio' => (string)($col['municipio'] ?? ''), 'zona' => $z['nombre'],
        ];
    }
}
$pagos    = pagos_de_cliente($idCliente, $idNegocio);
$colTexto = trim((string)$cliente['colonia']) !== ''
    ? $cliente['colonia'] . ((string)$cliente['cp'] !== '' ? ' (CP ' . $cliente['cp'] . ')' : '')
    : '';
?>
  <a class="ficha-vol" href="clientes.php?t=<?= h($negocio['slug']) ?>"><i class="fas fa-arrow-left"></i> Volver al directorio</a>
  <h1 class="contenido__h1"><?= h($cliente['nombre']) ?></h1>

  <?php if ((int)($cliente['aprobado'] ?? 1) !== 1): ?>
    <div class="aviso-aprobar">
      <div><i class="fas fa-clock"></i> Este cliente se registró desde WhatsApp y está <strong>por aprobar</strong>. No podrá agendar hasta que lo apruebes. Revisa sus datos (zona, colonia, dirección) y aprueba.</div>
      <form method="post" style="margin:0;">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="aprobar">
        <button class="btn btn--primario" type="submit"><i class="fas fa-check"></i> Aprobar cliente</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= h($mensaje) ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= h($error) ?></span></div><?php endif; ?>

  <div class="ficha-grid">
    <!-- Formulario editable -->
    <div class="tarjeta">
      <h2><i class="fas fa-user-edit"></i> Datos del cliente</h2>
      <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="actualizar">
        <div class="campo-fila">
          <div class="campo"><label>Nombre</label><input type="text" name="nombre" required value="<?= h($cliente['nombre']) ?>"></div>
          <div class="campo"><label>WhatsApp</label><input type="text" name="numero" required value="<?= h($cliente['numero']) ?>"></div>
        </div>
        <div class="campo">
          <label>Colonia (buscar por nombre o CP)</label>
          <input type="text" id="col-buscar" autocomplete="off" placeholder="Ej. Providencia o 44630" value="<?= h($colTexto) ?>">
          <input type="hidden" name="colonia" id="col-nombre" value="<?= h($cliente['colonia']) ?>">
          <input type="hidden" name="cp" id="col-cp" value="<?= h($cliente['cp']) ?>">
          <div id="col-sug" class="col-sug"></div>
        </div>
        <div class="campo">
          <label>Zona</label>
          <select name="zona" id="sel-zona">
            <option value="">— Sin zona —</option>
            <?php foreach ($zonas as $z): ?>
              <option value="<?= h($z['nombre']) ?>" <?= $cliente['zona'] === $z['nombre'] ? 'selected' : '' ?>><?= h($z['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo"><label>Calle y número</label><input type="text" name="direccion" placeholder="Calle, número, referencias" value="<?= h($cliente['direccion']) ?>"></div>
        <div class="campo"><label>Notas (opcional)</label><input type="text" name="notas" value="<?= h($cliente['notas']) ?>"></div>
        <div class="acciones">
          <button class="btn btn--primario" type="submit"><i class="fas fa-save"></i> Guardar cambios</button>
        </div>
      </form>
      <form method="post" style="margin-top:14px; border-top:1px solid var(--borde); padding-top:12px;" onsubmit="return confirm('¿Eliminar a este cliente del directorio? Sus pagos históricos se conservan pero quedarán sin cliente.');">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="borrar_cliente">
        <button type="submit" style="border:0;background:none;color:var(--error-texto);cursor:pointer;font-size:13px;"><i class="fas fa-trash"></i> Eliminar cliente</button>
      </form>
    </div>

    <!-- Historial de pagos -->
    <div class="tarjeta">
      <h2><i class="fas fa-receipt"></i> Historial de pagos</h2>
      <div class="resumen-pagos">
        <div class="rp-card"><small>Total cobrado</small><div class="rp-val"><?= caja_money($pagos['total']) ?></div></div>
        <div class="rp-card"><small>Visitas cobradas</small><div class="rp-val"><?= (int)$pagos['num_cobradas'] ?></div></div>
        <div class="rp-card"><small>Última</small><div class="rp-val" style="font-size:14px;"><?= $pagos['ultima'] ? h(date('d/m/y', strtotime($pagos['ultima']))) : '—' ?></div></div>
      </div>

      <?php if (!$pagos['citas']): ?>
        <div class="vacio" style="font-size:13px;">Este cliente aún no tiene citas registradas.</div>
      <?php else: ?>
        <div class="tabla-scroll"><table class="tabla-pagos">
          <thead><tr><th>Día</th><th>Servicio</th><th>Estado / cobro</th></tr></thead>
          <tbody>
          <?php foreach ($pagos['citas'] as $c): ?>
            <tr>
              <td><?= h($c['dia_texto']) ?: h($c['fecha']) ?><?= $c['hora'] ? '<br><small style="color:var(--texto-2)">' . h($c['hora']) . '</small>' : '' ?></td>
              <td><?= h($c['servicio']) ?: '<span style="color:var(--texto-2)">—</span>' ?></td>
              <td>
                <?php if ((int)$c['pagado'] === 1): ?>
                  <span class="pill-pagado"><i class="fas fa-check-circle"></i> <?= caja_money((float)$c['monto_cobrado']) ?> · <?= h($c['metodo_pago']) ?></span>
                <?php elseif ($c['estado'] === 'cancelada'): ?>
                  <span class="pill-pend"><i class="fas fa-ban"></i> Cancelada</span>
                <?php else: ?>
                  <span class="pill-pend"><i class="fas fa-clock"></i> Sin cobrar</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p style="font-size:12px; color:var(--texto-2); margin:12px 0 0;">Los cobros se registran en <a href="caja.php?t=<?= h($negocio['slug']) ?>">Caja</a>.</p>
      <?php endif; ?>
    </div>
  </div>

<script>
  // Buscador de colonias: solo entre las que ya están asignadas a una zona.
  var COLS_ZONA = <?= json_encode($coloniasZonas, JSON_UNESCAPED_UNICODE) ?>;
  (function () {
    var inp = document.getElementById('col-buscar');
    var sug = document.getElementById('col-sug');
    if (!inp) return;
    inp.addEventListener('input', function () {
      document.getElementById('col-nombre').value = '';
      document.getElementById('col-cp').value = '';
      var qq = this.value.trim();
      if (qq.length < 2) { sug.innerHTML = ''; return; }
      var qn = qq.toLowerCase();
      var esNum = /^\d+$/.test(qq);
      var res = COLS_ZONA.filter(function (c) {
        return esNum ? String(c.cp).indexOf(qq) === 0 : String(c.colonia).toLowerCase().indexOf(qn) !== -1;
      }).slice(0, 12);
      if (!res.length) {
        sug.innerHTML = '<div class="col-vacio">' + (COLS_ZONA.length
          ? 'Sin coincidencias en tus zonas.'
          : 'Aún no hay colonias en tus zonas. Agrégalas en Configuración → Zonas.') + '</div>';
        return;
      }
      sug.innerHTML = res.map(function (c) {
        return '<div class="col-op" data-cp="' + c.cp + '" data-col="' + String(c.colonia).replace(/"/g, '&quot;') + '" data-zona="' + String(c.zona).replace(/"/g, '&quot;') + '">' +
          c.colonia + ' <small>— ' + c.municipio + ', CP ' + c.cp + ' · ' + c.zona + '</small></div>';
      }).join('');
    });
    sug.addEventListener('click', function (e) {
      var op = e.target.closest('.col-op'); if (!op) return;
      var cp = op.getAttribute('data-cp'), col = op.getAttribute('data-col'), zona = op.getAttribute('data-zona');
      document.getElementById('col-nombre').value = col;
      document.getElementById('col-cp').value = cp;
      inp.value = col + ' (CP ' + cp + ')';
      sug.innerHTML = '';
      var sel = document.getElementById('sel-zona');
      if (sel && zona) { for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value === zona) { sel.selectedIndex = i; break; } } }
    });
    document.addEventListener('click', function (e) { if (!inp.contains(e.target) && !sug.contains(e.target)) sug.innerHTML = ''; });
  })();
</script>
<?php
layout_fin();
