<?php
// Configuracion de un negocio (?t=slug). Edita sus datos, horario, servicios y
// numero de WhatsApp. Guarda en la BD.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
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

    guardar_configuracion($idNegocio, $datos);
    $mensaje = 'Configuración guardada correctamente.';
    $negocio = negocio_por_id($idNegocio);
}

$c         = cargar_conocimiento($idNegocio);
$servicios = $c['servicios'] ?? [];
$horario   = $c['horario_estructurado'] ?? [];
$urlChat   = base_url() . '/chat-publico.php?t=' . urlencode($negocio['slug']);
function val($a, $k, $d = '') { return htmlspecialchars((string)($a[$k] ?? $d), ENT_QUOTES, 'UTF-8'); }

$css = '
  .form-config { max-width: 680px; }
  .seccion { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 18px 20px; margin-bottom: 18px; }
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
  .hint { font-size: 12px; color: var(--texto-2); margin-top: 5px; }
';
layout_inicio('Configuración', 'negocio', 'config', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Configuración</h1>

  <?php if ($mensaje): ?><div class="alerta alerta--ok"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($mensaje) ?></span></div><?php endif; ?>

  <form method="post" class="form-config">
    <?= campo_csrf() ?>
    <div class="seccion">
      <h2>Datos del negocio</h2>
      <div class="grupo"><label>Nombre del negocio</label><input type="text" name="negocio" value="<?= val($c, 'negocio') ?>"></div>
      <div class="grupo"><label>Descripción</label><textarea name="descripcion"><?= val($c, 'descripcion') ?></textarea></div>
      <div class="grupo"><label>Ubicación</label><input type="text" name="ubicacion" value="<?= val($c, 'ubicacion') ?>"></div>
      <div class="grupo"><label>Teléfono</label><input type="tel" name="telefono" value="<?= val($c, 'telefono') ?>"></div>
      <div class="grupo">
        <label>Número de WhatsApp del bot (lo asigna el administrador)</label>
        <input type="text" value="<?= val($negocio, 'numero_whatsapp') ?: 'Sin asignar' ?>" disabled style="background:#EEF3F4; color:var(--texto-2);">
        <div class="hint">El número por el que tus clientes le escriben al bot. Lo configura el administrador del sistema.</div>
      </div>
      <div class="grupo">
        <label>Número para recibir avisos de citas (tu WhatsApp)</label>
        <input type="text" name="numero_avisos" value="<?= val($c, 'numero_avisos') ?>" placeholder="+5213334588268">
        <div class="hint">Cuando se agende una cita, te llega un aviso por WhatsApp aquí. Usa formato internacional, ej. +52...</div>
      </div>
    </div>

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

    <div class="seccion">
      <h2>Servicios</h2>
      <table class="serv">
        <thead><tr><th>Servicio</th><th class="col-pre">Precio</th><th class="col-dur">Duración (min)</th><th class="col-x"></th></tr></thead>
        <tbody id="serv-body">
          <?php if ($servicios): foreach ($servicios as $s): ?>
            <tr>
              <td><input type="text" name="servicio_nombre[]" value="<?= htmlspecialchars((string)($s['nombre'] ?? ''), ENT_QUOTES) ?>"></td>
              <td class="col-pre"><input type="text" name="servicio_precio[]" value="<?= htmlspecialchars((string)($s['precio'] ?? ''), ENT_QUOTES) ?>"></td>
              <td class="col-dur"><input type="number" name="servicio_duracion[]" min="5" step="5" value="<?= (int)($s['duracion'] ?? 30) ?>"></td>
              <td class="col-x"><button type="button" class="btn-x" onclick="this.closest('tr').remove()">&times;</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <button type="button" class="btn btn--secundario" id="add-serv" style="margin-top:12px;"><i class="fas fa-plus"></i> Agregar servicio</button>
    </div>

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

    <button type="submit" class="btn btn--primario">Guardar configuración</button>
  </form>

  <div class="seccion" style="max-width:680px;">
    <h2>Chat web del negocio</h2>
    <p class="hint" style="margin:-6px 0 14px;">Comparte este enlace o el código QR para que tus clientes chateen con el asistente desde el navegador, sin WhatsApp.</p>
    <div class="grupo">
      <label>Enlace del chat web</label>
      <input type="text" readonly value="<?= htmlspecialchars($urlChat, ENT_QUOTES, 'UTF-8') ?>" onclick="this.select()">
    </div>
    <div id="qr" style="display:inline-block; padding:12px; background:#fff; border:1px solid var(--borde); border-radius:var(--radio);"></div>
  </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  new QRCode(document.getElementById('qr'), { text: <?= json_encode($urlChat) ?>, width: 176, height: 176, colorDark: '#0A2B3A', colorLight: '#ffffff' });

  document.querySelectorAll('.chk-dia').forEach(function (chk) {
    chk.addEventListener('change', function () {
      document.querySelector('[data-horas="' + this.dataset.dia + '"]').style.display = this.checked ? '' : 'none';
    });
  });
  document.getElementById('add-serv').addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" name="servicio_nombre[]" placeholder="Nombre del servicio"></td>' +
      '<td class="col-pre"><input type="text" name="servicio_precio[]" placeholder="0"></td>' +
      '<td class="col-dur"><input type="number" name="servicio_duracion[]" min="5" step="5" value="30"></td>' +
      '<td class="col-x"><button type="button" class="btn-x">&times;</button></td>';
    tr.querySelector('.btn-x').addEventListener('click', function () { tr.remove(); });
    document.getElementById('serv-body').appendChild(tr);
  });
</script>
<?php
layout_fin();
