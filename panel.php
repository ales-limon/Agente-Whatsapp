<?php
// Panel de un negocio: ver/confirmar/cancelar sus citas. El negocio se elige con ?t=slug.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/src/escalacion.php';
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
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'reactivar_bot') {
        desactivar_handoff($idNegocio, trim((string)($_POST['contacto'] ?? '')));
    } else {
        $id     = (int)($_POST['id'] ?? 0);
        $estado = $accion === 'confirmar' ? 'confirmada' : ($accion === 'cancelar' ? 'cancelada' : '');
        if ($id && $estado) {
            $st = $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ? AND id_negocio = ?");
            $st->execute([$estado, $id, $idNegocio]);
        }
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

$escalados = contactos_escalados($idNegocio);

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

$css = '
  .tarjetas { display: flex; gap: 12px; margin-bottom: 26px; flex-wrap: wrap; }
  .stat { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 16px 18px; min-width: 150px; }
  .stat .num { font-family: var(--fuente-titulo); font-size: 28px; font-weight: 700; color: var(--tinta); }
  .stat .lbl { font-size: 13px; color: var(--texto-2); margin-top: 2px; }
  .acciones .btn { padding: 6px 12px; font-size: 12px; margin-right: 6px; }
  .conv { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 4px 0; margin-top: 24px; }
  .conv .fila { padding: 11px 16px; border-bottom: 1px solid var(--borde); display: flex; justify-content: space-between; gap: 16px; }
  .conv .fila:last-child { border-bottom: 0; }
  .conv .contacto { font-weight: 500; font-size: 14px; }
  .conv .prev { color: var(--texto-2); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 520px; }
  .conv .cnt { font-size: 12px; color: var(--texto-2); white-space: nowrap; }
  .conv .der { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; white-space: nowrap; }
  .conv .estado-humano { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 999px; background: var(--aviso-bg); color: var(--aviso-texto); }
';
layout_inicio('Citas', 'negocio', 'citas', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Citas</h1>

  <div class="tarjetas">
    <div class="stat"><div class="num"><?= count($citas) ?></div><div class="lbl">Citas totales</div></div>
    <div class="stat"><div class="num" style="color:var(--aviso-texto);"><?= $pendientes ?></div><div class="lbl">Por confirmar</div></div>
    <div class="stat"><div class="num"><?= count($conversaciones) ?></div><div class="lbl">Conversaciones</div></div>
  </div>

  <h2 class="seccion-t" style="margin-top:0;">Citas</h2>
  <?php if (!$citas): ?>
    <div class="vacio">Aun no hay citas. Cuando el asistente agende una, aparecera aqui.</div>
  <?php else: ?>
    <table class="tabla">
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
                <button class="btn btn--primario" name="accion" value="confirmar">Confirmar</button>
                <button class="btn btn--secundario" name="accion" value="cancelar">Cancelar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($conversaciones): ?>
    <h2 class="seccion-t">Conversaciones recientes</h2>
    <div class="conv">
      <?php foreach ($conversaciones as $cv): ?>
        <div class="fila">
          <div>
            <div class="contacto"><?= h($cv['contacto']) ?></div>
            <div class="prev"><?= h($cv['ultimo']) ?></div>
          </div>
          <div class="der">
            <?php if (isset($escalados[$cv['contacto']])): ?>
              <span class="estado-humano">En atención humana</span>
              <form method="post" style="margin:0;">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="reactivar_bot">
                <input type="hidden" name="contacto" value="<?= h($cv['contacto']) ?>">
                <button class="btn-mini" type="submit">Reactivar bot</button>
              </form>
            <?php else: ?>
              <span class="cnt"><?= (int)$cv['total'] ?> mensajes</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php
layout_fin();
