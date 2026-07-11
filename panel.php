<?php
// Panel de un negocio: ver/confirmar/cancelar sus citas. El negocio se elige con ?t=slug.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/src/escalacion.php';
require_once __DIR__ . '/src/uso.php';
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
  .conv { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 4px 0; }
  .conv .fila { padding: 11px 16px; border-bottom: 1px solid var(--borde); display: flex; justify-content: space-between; gap: 16px; }
  .conv .fila:last-child { border-bottom: 0; }
  .conv .contacto { font-weight: 500; font-size: 14px; }
  .conv .prev { color: var(--texto-2); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 520px; }
  .conv .cnt { font-size: 12px; color: var(--texto-2); white-space: nowrap; }
  .conv .der { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; white-space: nowrap; }
  .conv .estado-humano { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 999px; background: var(--aviso-bg); color: var(--aviso-texto); }
  .atencion { background: var(--aviso-bg); border: 1px solid var(--aviso-borde); border-radius: var(--radio); padding: 14px 18px; margin-bottom: 24px; }
  .atencion__t { font-family: var(--fuente-titulo); font-weight: 700; font-size: 15px; color: var(--aviso-texto); margin-bottom: 8px; }
  .atencion__fila { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 9px 0; border-top: 1px solid var(--aviso-borde); }
  .atencion__num { font-weight: 600; font-size: 14px; color: var(--aviso-texto); }
  .atencion__motivo { font-size: 13px; color: var(--aviso-texto); opacity: .85; margin-top: 2px; }
  .tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--borde); margin: 26px 0 18px; flex-wrap: wrap; }
  .tab { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 14px; font-family: var(--fuente-cuerpo); font-size: 14px; font-weight: 500; color: var(--texto-2); cursor: pointer; }
  .tab:hover { color: var(--tinta); }
  .tab.activo { color: var(--marca); border-bottom-color: var(--accion); font-weight: 600; }
  .tab-panel { display: none; }
  .tab-panel.activo { display: block; }
  .estado-prueba { background: var(--badge-bg); border: 1px solid var(--accion); border-radius: var(--radio); padding: 16px 18px; margin-bottom: 24px; }
  .estado-prueba__t { font-family: var(--fuente-titulo); font-weight: 700; font-size: 15px; color: var(--marca); margin-bottom: 6px; }
  .estado-prueba p { margin: 6px 0 0; font-size: 13.5px; color: var(--tinta); line-height: 1.5; }
  .estado-prueba a { color: var(--marca); font-weight: 600; }
  .estado-prueba .barra { height: 7px; background: #fff; border-radius: 999px; overflow: hidden; margin: 10px 0 2px; max-width: 320px; }
  .estado-prueba .barra > span { display: block; height: 100%; background: var(--accion); }
';
layout_inicio('Citas', 'negocio', 'citas', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Citas</h1>

  <?php
    $waActivo = trim((string)($negocio['numero_whatsapp'] ?? '')) !== '';
    $uso      = uso_mes($idNegocio);
    $usados   = (int)$uso['mensajes'];
    $limite   = (int)($negocio['limite_mensajes_mes'] ?? 0);
  ?>
  <?php if (!$waActivo): ?>
    <div class="estado-prueba">
      <div class="estado-prueba__t"><i class="fas fa-flask"></i> Estás en modo prueba — WhatsApp aún no está activo</div>
      <p>Prueba tu asistente ahora mismo por el <strong>chat web</strong> (pestaña <a href="chat.php?t=<?= $slugSafe ?>">Probar chat</a>) o comparte tu enlace del chat con un cliente.</p>
      <?php if ($limite > 0): ?>
        <div class="barra"><span style="width:<?= min(100, (int)round($usados / max(1, $limite) * 100)) ?>%;"></span></div>
        <p>Llevas <strong><?= $usados ?> de <?= $limite ?></strong> mensajes de prueba este mes.</p>
      <?php endif; ?>
      <p><strong>Para activar WhatsApp</strong> necesitas tu número oficial dado de alta en <strong>Meta</strong> (te guiamos en el proceso) y elegir un plan. Cuando quieras contratar, contáctanos.</p>
    </div>
  <?php endif; ?>

  <?php if ($escalados): ?>
    <div class="atencion">
      <div class="atencion__t"><i class="fas fa-circle-exclamation"></i> Necesitan atención (<?= count($escalados) ?>)</div>
      <?php foreach ($escalados as $contacto => $e): ?>
        <div class="atencion__fila">
          <div>
            <a class="atencion__num" style="text-decoration:none;" href="conversacion.php?t=<?= $slugSafe ?>&c=<?= urlencode($contacto) ?>"><?= h($contacto) ?></a>
            <?php if (!empty($e['motivo'])): ?><div class="atencion__motivo"><?= h($e['motivo']) ?></div><?php endif; ?>
          </div>
          <form method="post" style="margin:0;">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="reactivar_bot">
            <input type="hidden" name="contacto" value="<?= h($contacto) ?>">
            <button class="btn-mini" type="submit">Reactivar bot</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="tarjetas">
    <div class="stat"><div class="num"><?= count($citas) ?></div><div class="lbl">Citas totales</div></div>
    <div class="stat"><div class="num" style="color:var(--aviso-texto);"><?= $pendientes ?></div><div class="lbl">Por confirmar</div></div>
    <div class="stat"><div class="num"><?= count($conversaciones) ?></div><div class="lbl">Conversaciones</div></div>
  </div>

  <div class="tabs">
    <button type="button" class="tab activo" data-tab="citas">Citas (<?= count($citas) ?>)</button>
    <button type="button" class="tab" data-tab="conversaciones">Conversaciones (<?= count($conversaciones) ?>)</button>
  </div>

  <div class="tab-panel activo" data-panel="citas">
    <?php if (!$citas): ?>
      <div class="vacio">Aun no hay citas. Cuando el asistente agende una, aparecera aqui.</div>
    <?php else: ?>
      <table class="tabla">
        <thead>
          <tr><th>Folio</th><th>Cliente</th><th>Servicio</th><th>Atiende</th><th>Día</th><th>Hora</th><th>Contacto</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($citas as $c): ?>
          <tr>
            <td>#<?= h($c['id']) ?></td>
            <td><?= h($c['nombre']) ?></td>
            <td><?= h($c['servicio']) ?></td>
            <td><?= h($c['profesional'] ?? '') ?: '<span style="color:var(--texto-2)">—</span>' ?></td>
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
  </div>

  <div class="tab-panel" data-panel="conversaciones">
    <?php if (!$conversaciones): ?>
      <div class="vacio">Aún no hay conversaciones. Cuando un cliente escriba, aparecerá aquí.</div>
    <?php else: ?>
      <div class="conv">
        <?php foreach ($conversaciones as $cv): ?>
          <div class="fila">
            <div>
              <a class="contacto" style="text-decoration:none;" href="conversacion.php?t=<?= $slugSafe ?>&c=<?= urlencode($cv['contacto']) ?>"><?= h($cv['contacto']) ?></a>
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
  </div>

<script>
  var tabs = document.querySelectorAll('.tab');
  var panels = document.querySelectorAll('.tab-panel');
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-tab');
      tabs.forEach(function (x) { x.classList.toggle('activo', x === t); });
      panels.forEach(function (p) { p.classList.toggle('activo', p.getAttribute('data-panel') === name); });
    });
  });
</script>
<?php
layout_fin();
