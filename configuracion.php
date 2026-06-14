<?php
// Configuracion de un negocio (?t=slug). Edita sus datos, horario, servicios y
// numero de WhatsApp. Guarda en la BD.
// NOTA DE SEGURIDAD: sin login. Uso local. Proteger antes de exponer.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
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
$slugSafe  = htmlspecialchars($negocio['slug'], ENT_QUOTES, 'UTF-8');
function val($a, $k, $d = '') { return htmlspecialchars((string)($a[$k] ?? $d), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuración — <?= val($c, 'negocio') ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a; }
  header { background: #075e54; color: #fff; padding: 16px 24px; }
  header h1 { margin: 0; font-size: 18px; font-weight: 500; }
  header nav { margin-top: 6px; font-size: 13px; }
  header nav a { color: #cfeae3; text-decoration: none; margin-right: 14px; }
  header nav a:hover { text-decoration: underline; }
  .contenedor { max-width: 760px; margin: 0 auto; padding: 24px; }
  .aviso { background: #eaf3de; color: #3b6d11; border: 1px solid #c0dd97; border-radius: 8px; padding: 10px 14px; margin-bottom: 18px; font-size: 14px; }
  .seccion { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
  .seccion h2 { font-size: 15px; font-weight: 500; margin: 0 0 14px; }
  label { display: block; font-size: 13px; color: #6a6a64; margin: 0 0 4px; }
  input[type=text], input[type=tel], input[type=number], textarea { width: 100%; padding: 8px 10px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; font-family: inherit; }
  input[type=time] { padding: 7px 8px; border: 1px solid #d6d6d0; border-radius: 6px; font-size: 14px; }
  textarea { resize: vertical; min-height: 56px; }
  .campo { margin-bottom: 14px; }
  .fila-dia { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f0f0ec; }
  .fila-dia:last-child { border-bottom: 0; }
  .fila-dia .nombre { width: 100px; font-size: 14px; }
  .fila-dia .horas { display: flex; align-items: center; gap: 6px; }
  table.serv { width: 100%; border-collapse: collapse; }
  table.serv th { text-align: left; font-size: 12px; color: #6a6a64; font-weight: 500; padding: 0 8px 6px; }
  table.serv td { padding: 4px 8px 4px 0; vertical-align: middle; }
  table.serv .col-dur { width: 130px; }
  table.serv .col-pre { width: 120px; }
  table.serv .col-x { width: 36px; text-align: center; }
  .btn { border: 1px solid #d6d6d0; background: #fff; border-radius: 6px; padding: 7px 12px; font-size: 13px; cursor: pointer; }
  .btn:hover { background: #f3f3ef; }
  .btn-x { border: 0; background: none; color: #a32d2d; cursor: pointer; font-size: 16px; }
  .guardar { background: #075e54; color: #fff; border: 0; border-radius: 8px; padding: 12px 22px; font-size: 15px; cursor: pointer; }
  .guardar:hover { background: #064a42; }
  .hint { font-size: 12px; color: #999; margin-top: 4px; }
</style>
</head>
<body>
  <header>
    <h1>Configuración — <?= val($c, 'negocio') ?></h1>
    <nav>
      <?php if (es_superadmin()): ?><a href="superadmin.php">&larr; Negocios</a><?php endif; ?>
      <a href="configuracion.php?t=<?= $slugSafe ?>">Configuración</a>
      <a href="panel.php?t=<?= $slugSafe ?>">Citas</a>
      <a href="chat.php?t=<?= $slugSafe ?>">Probar chat</a>
      <a href="logout.php">Salir</a>
    </nav>
  </header>

  <div class="contenedor">
    <?php if ($mensaje): ?><div class="aviso"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <form method="post">
      <?= campo_csrf() ?>
      <div class="seccion">
        <h2>Datos del negocio</h2>
        <div class="campo"><label>Nombre del negocio</label><input type="text" name="negocio" value="<?= val($c, 'negocio') ?>"></div>
        <div class="campo"><label>Descripción</label><textarea name="descripcion"><?= val($c, 'descripcion') ?></textarea></div>
        <div class="campo"><label>Ubicación</label><input type="text" name="ubicacion" value="<?= val($c, 'ubicacion') ?>"></div>
        <div class="campo"><label>Teléfono</label><input type="tel" name="telefono" value="<?= val($c, 'telefono') ?>"></div>
        <div class="campo">
          <label>Número de WhatsApp del bot (lo asigna el administrador)</label>
          <input type="text" value="<?= val($negocio, 'numero_whatsapp') ?: 'Sin asignar' ?>" disabled style="background:#f1efe8; color:#6a6a64;">
          <div class="hint">El número por el que tus clientes le escriben al bot. Lo configura el administrador del sistema.</div>
        </div>
        <div class="campo">
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
            <label style="margin:0; display:flex; align-items:center; gap:6px; color:#1c1c1a;">
              <input type="checkbox" name="abierto_<?= $clave ?>" class="chk-dia" data-dia="<?= $clave ?>" <?= $abierto ? 'checked' : '' ?>> Abierto
            </label>
            <span class="horas" data-horas="<?= $clave ?>" style="<?= $abierto ? '' : 'display:none;' ?>">
              <input type="time" name="abre_<?= $clave ?>" value="<?= $abre ?>"> a
              <input type="time" name="cierra_<?= $clave ?>" value="<?= $cierra ?>">
            </span>
          </div>
        <?php endforeach; ?>
        <div class="campo" style="margin-top:16px;">
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
        <button type="button" class="btn" id="add-serv" style="margin-top:10px;">+ Agregar servicio</button>
      </div>

      <div class="seccion">
        <h2>Reglas adicionales del asistente (opcional)</h2>
        <div class="campo">
          <label>Instrucciones extra según tu giro</label>
          <textarea name="instrucciones_extra"><?= val($c, 'instrucciones_extra') ?></textarea>
        </div>
        <div class="campo">
          <label>Políticas (cancelaciones, anticipos, etc.)</label>
          <textarea name="politicas"><?= val($c, 'politicas') ?></textarea>
        </div>
      </div>

      <button type="submit" class="guardar">Guardar configuración</button>
    </form>
  </div>

<script>
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
</body>
</html>
