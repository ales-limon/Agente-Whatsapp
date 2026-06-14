<?php
// Panel de un negocio: ver/confirmar/cancelar sus citas. El negocio se elige con ?t=slug.
// NOTA DE SEGURIDAD: sin login. Uso local. Proteger con autenticacion antes de exponer.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/config/db.php';
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
$slugSafe  = htmlspecialchars($negocio['slug'], ENT_QUOTES, 'UTF-8');
$pdo = conexion();

// Cambiar estado de una cita (re-verificando que pertenece a este negocio)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $id     = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    $estado = $accion === 'confirmar' ? 'confirmada' : ($accion === 'cancelar' ? 'cancelada' : '');
    if ($id && $estado) {
        $st = $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ? AND id_negocio = ?");
        $st->execute([$estado, $id, $idNegocio]);
    }
    header('Location: panel.php?t=' . urlencode($negocio['slug']));
    exit;
}

$st = $pdo->prepare("SELECT * FROM citas WHERE id_negocio = ? ORDER BY id DESC");
$st->execute([$idNegocio]);
$citas = $st->fetchAll();

$st = $pdo->prepare("SELECT COUNT(*) FROM citas WHERE id_negocio = ? AND estado = 'pendiente'");
$st->execute([$idNegocio]);
$pendientes = (int)$st->fetchColumn();

// Conversaciones (ultimas por contacto, de la tabla mensajes)
$st = $pdo->prepare("SELECT contacto, COUNT(*) total, MAX(id) ult FROM mensajes WHERE id_negocio = ? GROUP BY contacto ORDER BY ult DESC LIMIT 20");
$st->execute([$idNegocio]);
$conversaciones = [];
foreach ($st as $row) {
    $u = $pdo->prepare("SELECT contenido FROM mensajes WHERE id = ?");
    $u->execute([$row['ult']]);
    $conversaciones[] = ['contacto' => $row['contacto'], 'total' => (int)$row['total'], 'ultimo' => (string)$u->fetchColumn()];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge_estado(string $estado): string {
    $map = [
        'pendiente'  => ['Pendiente', '#854f0b', '#faeeda'],
        'confirmada' => ['Confirmada', '#3b6d11', '#eaf3de'],
        'cancelada'  => ['Cancelada', '#791f1f', '#fcebeb'],
    ];
    [$txt, $color, $bg] = $map[$estado] ?? ['—', '#444', '#eee'];
    return "<span style=\"background:$bg;color:$color;font-size:12px;padding:3px 10px;border-radius:6px;\">" . h($txt) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Citas — <?= h($negocio['nombre']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f3; color: #1c1c1a; }
  header { background: #075e54; color: #fff; padding: 16px 24px; }
  header h1 { margin: 0; font-size: 18px; font-weight: 500; }
  header nav { margin-top: 6px; font-size: 13px; }
  header nav a { color: #cfeae3; text-decoration: none; margin-right: 14px; }
  header nav a:hover { text-decoration: underline; }
  .contenedor { max-width: 980px; margin: 0 auto; padding: 24px; }
  .tarjetas { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
  .tarjeta { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 14px 18px; min-width: 150px; }
  .tarjeta .num { font-size: 26px; font-weight: 500; }
  .tarjeta .lbl { font-size: 13px; color: #6a6a64; }
  h2 { font-size: 16px; font-weight: 500; margin: 0 0 12px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; overflow: hidden; }
  th, td { text-align: left; padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #f0f0ec; }
  th { background: #fafaf8; font-weight: 500; color: #6a6a64; font-size: 12px; }
  tr:last-child td { border-bottom: 0; }
  .vacio { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 28px; text-align: center; color: #888; }
  .acciones button { border: 1px solid #d6d6d0; background: #fff; border-radius: 6px; padding: 5px 10px; font-size: 12px; cursor: pointer; margin-right: 4px; }
  .acciones button:hover { background: #f3f3ef; }
  .conv { background: #fff; border: 1px solid #e7e7e2; border-radius: 10px; padding: 4px 0; margin-top: 24px; }
  .conv .fila { padding: 10px 16px; border-bottom: 1px solid #f0f0ec; display: flex; justify-content: space-between; gap: 16px; }
  .conv .fila:last-child { border-bottom: 0; }
  .conv .contacto { font-weight: 500; font-size: 14px; }
  .conv .prev { color: #6a6a64; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 520px; }
  .conv .cnt { font-size: 12px; color: #999; white-space: nowrap; }
</style>
</head>
<body>
  <header>
    <h1>Citas — <?= h($negocio['nombre']) ?></h1>
    <nav>
      <?php if (es_superadmin()): ?><a href="superadmin.php">&larr; Negocios</a><?php endif; ?>
      <a href="configuracion.php?t=<?= $slugSafe ?>">Configuración</a>
      <a href="panel.php?t=<?= $slugSafe ?>">Citas</a>
      <a href="chat.php?t=<?= $slugSafe ?>">Probar chat</a>
      <a href="logout.php">Salir</a>
    </nav>
  </header>

  <div class="contenedor">
    <div class="tarjetas">
      <div class="tarjeta"><div class="num"><?= count($citas) ?></div><div class="lbl">Citas totales</div></div>
      <div class="tarjeta"><div class="num" style="color:#854f0b;"><?= $pendientes ?></div><div class="lbl">Por confirmar</div></div>
      <div class="tarjeta"><div class="num"><?= count($conversaciones) ?></div><div class="lbl">Conversaciones</div></div>
    </div>

    <h2>Citas</h2>
    <?php if (!$citas): ?>
      <div class="vacio">Aun no hay citas. Cuando el asistente agende una, aparecera aqui.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Folio</th><th>Cliente</th><th>Servicio</th><th>Día</th><th>Hora</th><th>Contacto</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($citas as $c): ?>
          <tr>
            <td>#<?= h($c['id']) ?></td>
            <td><?= h($c['nombre']) ?></td>
            <td><?= h($c['servicio']) ?></td>
            <td><?= h($c['dia_texto']) ?></td>
            <td><?= h($c['hora']) ?></td>
            <td><?= h($c['contacto']) ?></td>
            <td><?= badge_estado($c['estado']) ?></td>
            <td class="acciones">
              <?php if ($c['estado'] === 'pendiente'): ?>
                <form method="post" style="display:inline;">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= h($c['id']) ?>">
                  <button name="accion" value="confirmar">Confirmar</button>
                  <button name="accion" value="cancelar">Cancelar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($conversaciones): ?>
      <h2 style="margin-top:28px;">Conversaciones recientes</h2>
      <div class="conv">
        <?php foreach ($conversaciones as $cv): ?>
          <div class="fila">
            <div>
              <div class="contacto"><?= h($cv['contacto']) ?></div>
              <div class="prev"><?= h($cv['ultimo']) ?></div>
            </div>
            <div class="cnt"><?= (int)$cv['total'] ?> mensajes</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
