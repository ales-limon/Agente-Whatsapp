<?php
// Configuracion de un negocio (?t=slug). Edita sus datos, horario, servicios y
// numero de WhatsApp. Guarda en la BD.

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
$mensaje   = '';
$error     = '';

$diasOrden = ['lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles', 'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $datos = [
        'negocio'             => trim($_POST['negocio'] ?? ''),
        'descripcion'         => trim($_POST['descripcion'] ?? ''),
        'ubicacion'           => trim($_POST['ubicacion'] ?? ''),
        'telefono'            => trim($_POST['telefono'] ?? ''),
        'numero_avisos'       => trim($_POST['numero_avisos'] ?? ''),
        'politicas'           => trim($_POST['politicas'] ?? ''),
        'instrucciones_extra' => trim($_POST['instrucciones_extra'] ?? ''),
        'intervalo_minutos'   => max(5, (int)($_POST['intervalo_minutos'] ?? 30)),
        'recordatorio_horas_antes' => max(0, (int)($_POST['recordatorio_horas_antes'] ?? 0)),
        'traslado_minutos'    => max(0, (int)($_POST['traslado_minutos'] ?? 0)),
        'a_domicilio'         => !empty($_POST['a_domicilio']) ? 1 : 0,
        'horario_estructurado' => [],
        'servicios'           => [],
    ];
    foreach (array_keys($diasOrden) as $d) {
        $datos['horario_estructurado'][$d] = !empty($_POST["abierto_$d"])
            ? ['abre' => trim($_POST["abre_$d"] ?? ''), 'cierra' => trim($_POST["cierra_$d"] ?? '')]
            : null;
    }
    $nombres    = $_POST['servicio_nombre']   ?? [];
    $precios    = $_POST['servicio_precio']   ?? [];
    $duraciones = $_POST['servicio_duracion'] ?? [];
    foreach ($nombres as $i => $n) {
        $n = trim($n);
        if ($n === '') continue;
        $datos['servicios'][] = ['nombre' => $n, 'precio' => trim($precios[$i] ?? '0'), 'duracion' => max(5, (int)($duraciones[$i] ?? 30))];
    }

    $datos['recursos'] = [];
    foreach (($_POST['profesional_nombre'] ?? []) as $pn) {
        $pn = trim($pn);
        if ($pn !== '') $datos['recursos'][] = $pn;
    }

    guardar_configuracion($idNegocio, $datos);

    // Zonas (modo a domicilio): cada zona con sus días de atención.
    $zonasPost = [];
    foreach (($_POST['zona_nombre'] ?? []) as $i => $zn) {
        $zn = trim($zn);
        if ($zn === '') continue;
        $dias = array_values(array_intersect(array_keys($diasOrden), (array)(($_POST['zona_dias'][$i] ?? []))));
        $zonasPost[] = ['nombre' => $zn, 'dias' => $dias];
    }
    guardar_zonas($idNegocio, $zonasPost);

    // Dirección (slug) del chat web: editable, con validación de unicidad.
    $nuevoSlug = trim($_POST['slug'] ?? '');
    if ($nuevoSlug !== '' && slugify($nuevoSlug) !== $negocio['slug']) {
        $rs = actualizar_slug($idNegocio, $nuevoSlug);
        if ($rs['exito']) {
            header('Location: configuracion.php?t=' . urlencode($rs['slug']) . '&guardado=1');
            exit;
        }
        $error = $rs['mensaje']; // dirección en uso: lo demás sí se guardó
    }

    $mensaje = 'Configuración guardada correctamente.';
    $negocio = negocio_por_id($idNegocio);
}

if (isset($_GET['guardado'])) $mensaje = 'Configuración guardada correctamente.';

$c         = cargar_conocimiento($idNegocio);
$servicios = $c['servicios'] ?? [];
$recursos  = $c['recursos'] ?? [];
$zonas     = listar_zonas($idNegocio);
$aDomicilio = (int)($c['a_domicilio'] ?? 0) === 1;
$horario   = $c['horario_estructurado'] ?? [];
$urlChat   = base_url() . '/chat-publico.php?t=' . urlencode($negocio['slug']);
function val($a, $k, $d = '') { return htmlspecialchars((string)($a[$k] ?? $d), ENT_QUOTES, 'UTF-8'); }

$css = '
  .config-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 720px; margin-bottom: 16px; }
  .config-header h1 { margin: 0; }
  .tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--borde); margin-bottom: 22px; max-width: 720px; flex-wrap: wrap; }
  .tab { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 14px; font-family: var(--fuente-cuerpo); font-size: 14px; font-weight: 500; color: var(--texto-2); cursor: pointer; }
  .tab:hover { color: var(--tinta); }
  .tab.activo { color: var(--marca); border-bottom-color: var(--accion); font-weight: 600; }
  .tab-panel { display: none; max-width: 720px; }
  .tab-panel.activo { display: block; }
  .seccion { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 18px 20px; }
  .seccion h2 { font-family: var(--fuente-titulo); font-size: 16px; font-weight: 700; margin: 0 0 14px; }
  label { display: block; font-size: 13px; color: var(--texto-2); margin: 0 0 5px; font-weight: 500; }
  input[type=text], input[type=tel], input[type=number], textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--borde); border-radius: var(--radio);
    font-size: 14px; font-family: var(--fuente-cuerpo); color: var(--tinta); background: var(--superficie); }
  input[type=time] { padding: 9px 10px; border: 1.5px solid var(--borde); border-radius: var(--radio); font-size: 14px; font-family: var(--fuente-cuerpo); color: var(--tinta); }
  input:focus, textarea:focus { outline: none; border-color: var(--marca); box-shadow: 0 0 0 3px rgba(19,132,150,.15); }
  textarea { resize: vertical; min-height: 56px; }
  .grupo { margin-bottom: 14px; }
  .fila-dia { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--borde); }
  .fila-dia:last-child { border-bottom: 0; }
  .fila-dia .nombre { width: 100px; font-size: 14px; }
  .fila-dia .horas { display: flex; align-items: center; gap: 6px; }
  table.serv { width: 100%; border-collapse: collapse; }
  table.serv th { text-align: left; font-size: 12px; color: var(--texto-2); font-weight: 600; padding: 0 8px 6px; }
  table.serv td { padding: 4px 8px 4px 0; vertical-align: middle; }
  table.serv .col-dur { width: 130px; }
  table.serv .col-pre { width: 120px; }
  table.serv .col-x { width: 36px; text-align: center; }
  .btn-x { border: 0; background: none; color: var(--error-texto); cursor: pointer; font-size: 18px; }
  @media (max-width: 640px) {
    table.serv thead { display: none; }
    table.serv, table.serv tbody, table.serv tr, table.serv td { display: block; width: 100%; }
    table.serv tr { border: 1px solid var(--borde); border-radius: var(--radio); padding: 12px; margin-bottom: 12px; position: relative; }
    table.serv td { padding: 6px 0; }
    table.serv .col-pre, table.serv .col-dur { width: 100%; }
    table.serv td::before { content: attr(data-label); display: block; font-size: 12px; color: var(--texto-2); font-weight: 600; margin-bottom: 4px; }
    table.serv .col-x { position: absolute; top: 6px; right: 6px; width: auto; padding: 0; }
    table.serv .col-x::before { content: none; }
  }
  .hint { font-size: 12px; color: var(--texto-2); margin-top: 5px; }
  .zona-fila { border: 1px solid var(--borde); border-radius: var(--radio); padding: 12px 14px; margin-bottom: 12px; }
  .zona-top { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
  .zona-top input { flex: 1; min-width: 0; }
  .zona-dias { display: flex; flex-wrap: wrap; gap: 8px 14px; }
  .zona-dias label { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: var(--tinta); margin: 0; font-weight: 400; }
  .zona-dias input { width: auto; }
';
layout_inicio('Configuración', 'negocio', 'config', ['negocio' => $negocio, 'css' => $css]);
?>
  <div class="config-header">
    <h1 class="contenido__h1">Configuración</h1>
    <button type="submit" form="form-config" class="btn btn--primario">Guardar configuración</button>
  </div>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($mensaje) ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="alerta alerta--error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

  <div class="tabs">
    <button type="button" class="tab activo" data-tab="datos">Datos</button>
    <button type="button" class="tab" data-tab="horario">Horario</button>
    <button type="button" class="tab" data-tab="servicios">Servicios</button>
    <button type="button" class="tab" data-tab="personal">Personal</button>
    <?php if ($aDomicilio): ?><button type="button" class="tab" data-tab="zonas">Zonas</button><?php endif; ?>
    <button type="button" class="tab" data-tab="reglas">Reglas</button>
    <button type="button" class="tab" data-tab="chatweb">Chat web</button>
  </div>

  <form id="form-config" method="post">
    <?= campo_csrf() ?>

    <div class="tab-panel activo" data-panel="datos">
      <div class="seccion">
        <h2>Datos del negocio</h2>
        <div class="grupo"><label>Nombre del negocio</label><input type="text" name="negocio" value="<?= val($c, 'negocio') ?>"></div>
        <div class="grupo"><label>Descripción</label><textarea name="descripcion"><?= val($c, 'descripcion') ?></textarea></div>
        <div class="grupo"><label>Ubicación</label><input type="text" name="ubicacion" value="<?= val($c, 'ubicacion') ?>"></div>
        <div class="grupo"><label>Teléfono</label><input type="tel" name="telefono" value="<?= val($c, 'telefono') ?>"></div>
        <div class="grupo">
          <label>Número de WhatsApp del bot</label>
          <input type="text" value="<?= val($negocio, 'numero_whatsapp') ?: 'Sin asignar — en modo prueba' ?>" disabled style="background:#EEF3F4; color:var(--texto-2);">
          <div class="hint">WhatsApp se activa cuando tengas tu <strong>número oficial dado de alta en Meta</strong>. Ese alta la hace cada negocio (te guiamos paso a paso al contratar un plan). Mientras tanto, prueba y comparte tu <strong>chat web</strong> (pestaña "Chat web"), que funciona sin WhatsApp.</div>
        </div>
        <div class="grupo">
          <label>Número para recibir avisos de citas (tu WhatsApp)</label>
          <input type="text" name="numero_avisos" value="<?= val($c, 'numero_avisos') ?>" placeholder="+5215512345678">
          <div class="hint">Cuando se agende una cita, te llega un aviso por WhatsApp aquí. Usa formato internacional, ej. +52...</div>
        </div>
        <div class="grupo">
          <label>Recordatorio automático al cliente (horas antes)</label>
          <input type="number" name="recordatorio_horas_antes" min="0" step="1" value="<?= (int)($c['recordatorio_horas_antes'] ?? 0) ?>" style="width:120px;">
          <div class="hint">Cuántas horas antes de su cita se le recuerda al cliente por WhatsApp. 0 = desactivado. Ej: 24 = un día antes. Solo aplica a clientes que agendaron por WhatsApp.</div>
        </div>
        <div class="grupo">
          <label style="display:flex; align-items:center; gap:8px; color:var(--tinta);">
            <input type="checkbox" name="a_domicilio" value="1" <?= $aDomicilio ? 'checked' : '' ?>> El negocio atiende a domicilio
          </label>
          <div class="hint">Actívalo si vas a casa del cliente. Habilita <strong>Zonas</strong> (qué días atiendes cada zona) y el <strong>Directorio de clientes</strong>. El agente identifica a tus clientes por su número y solo agenda a quienes ya conoces.</div>
        </div>
        <div class="grupo">
          <label>Tiempo de traslado entre citas (minutos)</label>
          <input type="number" name="traslado_minutos" min="0" step="5" value="<?= (int)($c['traslado_minutos'] ?? 0) ?>" style="width:120px;">
          <div class="hint">Colchón que se reserva entre una cita y otra para el traslado. 0 = sin colchón. Ej: 45 = deja 45 min entre citas.</div>
        </div>
      </div>
    </div>

    <div class="tab-panel" data-panel="horario">
      <div class="seccion">
        <h2>Horario de atención</h2>
        <?php foreach ($diasOrden as $clave => $etiqueta):
            $h = $horario[$clave] ?? null;
            $abierto = is_array($h) && !empty($h['abre']) && !empty($h['cierra']);
            $abre = $abierto ? htmlspecialchars($h['abre']) : '10:00';
            $cierra = $abierto ? htmlspecialchars($h['cierra']) : '19:00';
        ?>
          <div class="fila-dia">
            <span class="nombre"><?= $etiqueta ?></span>
            <label style="margin:0; display:flex; align-items:center; gap:6px; color:var(--tinta);">
              <input type="checkbox" name="abierto_<?= $clave ?>" class="chk-dia" data-dia="<?= $clave ?>" <?= $abierto ? 'checked' : '' ?>> Abierto
            </label>
            <span class="horas" data-horas="<?= $clave ?>" style="<?= $abierto ? '' : 'display:none;' ?>">
              <input type="time" name="abre_<?= $clave ?>" value="<?= $abre ?>"> a
              <input type="time" name="cierra_<?= $clave ?>" value="<?= $cierra ?>">
            </span>
          </div>
        <?php endforeach; ?>
        <div class="grupo" style="margin-top:16px;">
          <label>Duración base de los espacios (minutos)</label>
          <input type="number" name="intervalo_minutos" min="5" step="5" value="<?= (int)($c['intervalo_minutos'] ?? 30) ?>" style="width:120px;">
          <div class="hint">Cada cuánto empiezan los espacios. Ej: 30 = horarios a las 10:00, 10:30, 11:00...</div>
        </div>
      </div>
    </div>

    <div class="tab-panel" data-panel="servicios">
      <div class="seccion">
        <h2>Servicios</h2>
        <table class="serv">
          <thead><tr><th>Servicio</th><th class="col-pre">Precio</th><th class="col-dur">Duración (min)</th><th class="col-x"></th></tr></thead>
          <tbody id="serv-body">
            <?php if ($servicios): foreach ($servicios as $s): ?>
              <tr>
                <td data-label="Servicio"><input type="text" name="servicio_nombre[]" value="<?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES) ?>"></td>
                <td class="col-pre" data-label="Precio"><input type="text" name="servicio_precio[]" value="<?= htmlspecialchars((string)($s['precio'] ?? ''), ENT_QUOTES) ?>"></td>
                <td class="col-dur" data-label="Duración (min)"><input type="number" name="servicio_duracion[]" min="5" step="5" value="<?= (int)($s['duracion'] ?? 30) ?>"></td>
                <td class="col-x"><button type="button" class="btn-x" onclick="this.closest('tr').remove()">&times;</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <button type="button" class="btn btn--secundario" id="add-serv" style="margin-top:12px;"><i class="fas fa-plus"></i> Agregar servicio</button>
      </div>
    </div>

    <div class="tab-panel" data-panel="personal">
      <div class="seccion">
        <h2>Personal que atiende</h2>
        <p style="font-size:13px;color:var(--texto-2);margin:0 0 14px;">Las personas que dan el servicio (barberos, estilistas, especialistas...). Si registras más de una, el cliente puede pedir cita con alguien en específico y cada quien lleva su propia agenda. Si lo dejas vacío, el asistente agenda como un solo lugar.</p>
        <table class="serv">
          <thead><tr><th>Nombre</th><th class="col-x"></th></tr></thead>
          <tbody id="pers-body">
            <?php if ($recursos): foreach ($recursos as $r): ?>
              <tr>
                <td data-label="Nombre"><input type="text" name="profesional_nombre[]" value="<?= htmlspecialchars((string)$r, ENT_QUOTES) ?>"></td>
                <td class="col-x"><button type="button" class="btn-x" onclick="this.closest('tr').remove()">&times;</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <button type="button" class="btn btn--secundario" id="add-pers" style="margin-top:12px;"><i class="fas fa-plus"></i> Agregar persona</button>
      </div>
    </div>

    <div class="tab-panel" data-panel="zonas">
      <div class="seccion">
        <h2>Zonas de atención</h2>
        <p style="font-size:13px;color:var(--texto-2);margin:0 0 14px;">Crea tus zonas y marca <strong>qué días</strong> atiendes cada una. El agente ofrecerá a cada cliente solo los días de <em>su</em> zona. (El cliente se asigna a una zona en el <a href="clientes.php?t=<?= urlencode($negocio['slug']) ?>">Directorio de clientes</a>.)</p>
        <div id="zonas-cont">
          <?php foreach ($zonas as $i => $z): ?>
            <div class="zona-fila" data-i="<?= (int)$i ?>">
              <div class="zona-top">
                <input type="text" name="zona_nombre[<?= (int)$i ?>]" value="<?= htmlspecialchars((string)$z['nombre'], ENT_QUOTES) ?>" placeholder="Nombre de la zona (ej. Norte)">
                <button type="button" class="btn-x" onclick="this.closest('.zona-fila').remove()">&times;</button>
              </div>
              <div class="zona-dias">
                <?php foreach ($diasOrden as $dk => $dl): ?>
                  <label><input type="checkbox" name="zona_dias[<?= (int)$i ?>][]" value="<?= $dk ?>" <?= in_array($dk, $z['dias'] ?? [], true) ? 'checked' : '' ?>> <?= $dl ?></label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn--secundario" id="add-zona" style="margin-top:12px;"><i class="fas fa-plus"></i> Agregar zona</button>
      </div>
    </div>

    <div class="tab-panel" data-panel="reglas">
      <div class="seccion">
        <h2>Reglas adicionales del asistente (opcional)</h2>
        <div class="grupo">
          <label>Instrucciones extra según tu giro</label>
          <textarea name="instrucciones_extra"><?= val($c, 'instrucciones_extra') ?></textarea>
        </div>
        <div class="grupo">
          <label>Políticas (cancelaciones, anticipos, etc.)</label>
          <textarea name="politicas"><?= val($c, 'politicas') ?></textarea>
        </div>
      </div>
    </div>
  </form>

  <div class="tab-panel" data-panel="chatweb">
    <div class="seccion">
      <h2>Chat web del negocio</h2>
      <p class="hint" style="margin:-6px 0 14px;">Comparte este enlace o el código QR para que tus clientes chateen con el asistente desde el navegador, sin WhatsApp.</p>
      <div class="grupo">
        <label>Dirección del chat web (lo que va en el enlace)</label>
        <input type="text" name="slug" form="form-config" value="<?= val($negocio, 'slug') ?>">
        <div class="hint">Si la cambias, los QR o enlaces que ya hayas compartido dejarán de funcionar. Guarda con el botón de arriba.</div>
      </div>
      <div class="grupo">
        <label>Enlace del chat web</label>
        <input type="text" readonly value="<?= htmlspecialchars($urlChat, ENT_QUOTES, 'UTF-8') ?>" onclick="this.select()">
      </div>
      <div id="qr" style="display:inline-block; padding:12px; background:#fff; border:1px solid var(--borde); border-radius:var(--radio);"></div>
    </div>
  </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  new QRCode(document.getElementById('qr'), { text: <?= json_encode($urlChat) ?>, width: 176, height: 176, colorDark: '#0A2B3A', colorLight: '#ffffff' });

  var tabs = document.querySelectorAll('.tab');
  var panels = document.querySelectorAll('.tab-panel');
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-tab');
      tabs.forEach(function (x) { x.classList.toggle('activo', x === t); });
      panels.forEach(function (p) { p.classList.toggle('activo', p.getAttribute('data-panel') === name); });
    });
  });

  document.querySelectorAll('.chk-dia').forEach(function (chk) {
    chk.addEventListener('change', function () {
      document.querySelector('[data-horas="' + this.dataset.dia + '"]').style.display = this.checked ? '' : 'none';
    });
  });
  document.getElementById('add-serv').addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td data-label="Servicio"><input type="text" name="servicio_nombre[]" placeholder="Nombre del servicio"></td>' +
      '<td class="col-pre" data-label="Precio"><input type="text" name="servicio_precio[]" placeholder="0"></td>' +
      '<td class="col-dur" data-label="Duración (min)"><input type="number" name="servicio_duracion[]" min="5" step="5" value="30"></td>' +
      '<td class="col-x"><button type="button" class="btn-x">&times;</button></td>';
    tr.querySelector('.btn-x').addEventListener('click', function () { tr.remove(); });
    document.getElementById('serv-body').appendChild(tr);
  });
  document.getElementById('add-pers').addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td data-label="Nombre"><input type="text" name="profesional_nombre[]" placeholder="Nombre de la persona"></td>' +
      '<td class="col-x"><button type="button" class="btn-x">&times;</button></td>';
    tr.querySelector('.btn-x').addEventListener('click', function () { tr.remove(); });
    document.getElementById('pers-body').appendChild(tr);
  });
  var zonaIdx = <?= count($zonas) ?>;
  var DIAS = <?= json_encode($diasOrden, JSON_UNESCAPED_UNICODE) ?>;
  var addZona = document.getElementById('add-zona');
  if (addZona) addZona.addEventListener('click', function () {
    var i = zonaIdx++;
    var dias = '';
    for (var k in DIAS) { dias += '<label><input type="checkbox" name="zona_dias[' + i + '][]" value="' + k + '"> ' + DIAS[k] + '</label>'; }
    var div = document.createElement('div');
    div.className = 'zona-fila';
    div.innerHTML =
      '<div class="zona-top"><input type="text" name="zona_nombre[' + i + ']" placeholder="Nombre de la zona (ej. Norte)">' +
      '<button type="button" class="btn-x">&times;</button></div>' +
      '<div class="zona-dias">' + dias + '</div>';
    div.querySelector('.btn-x').addEventListener('click', function () { div.remove(); });
    document.getElementById('zonas-cont').appendChild(div);
  });
</script>
<?php
layout_fin();
