<?php
// Caja de un negocio (?t=slug): marcar citas como cobradas y ver el corte
// (hoy / semana / mes) con desglose por metodo de pago.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/src/caja.php';
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
$pdo = conexion();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $accion = $_POST['accion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($id && $accion === 'cobrar') {
        $metodo = (string)($_POST['metodo'] ?? 'efectivo');
        // Monto por defecto: el precio del servicio de la cita.
        $st = $pdo->prepare("SELECT servicio FROM citas WHERE id = ? AND id_negocio = ?");
        $st->execute([$id, $idNegocio]);
        $cita = $st->fetch();
        if ($cita) {
            $monto = precio_de_servicio((string)$cita['servicio'], $idNegocio);
            marcar_cita_pagada($id, $idNegocio, $metodo, $monto);
        }
    } elseif ($id && $accion === 'descobrar') {
        marcar_cita_no_pagada($id, $idNegocio);
    }
    header('Location: caja.php?t=' . urlencode($negocio['slug']));
    exit;
}

// Totales por periodo
$totales = [];
foreach (['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes'] as $p => $lbl) {
    [$d, $h] = rango_periodo($p);
    $totales[$p] = ['lbl' => $lbl] + resumen_caja($idNegocio, $d, $h);
}
$pend = pendientes_cobro($idNegocio);

// Citas recientes (para cobrar): no canceladas, ultimas por fecha.
$st = $pdo->prepare(
    "SELECT * FROM citas WHERE id_negocio = ? AND estado <> 'cancelada' AND fecha IS NOT NULL
     ORDER BY fecha DESC, hora DESC LIMIT 60"
);
$st->execute([$idNegocio]);
$citas = $st->fetchAll();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$css = <<<CSS
  .caja-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .caja-card { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 16px 18px; }
  .caja-card small { color: var(--texto-2); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
  .caja-card .monto { font-family: var(--fuente-titulo); font-size: 26px; font-weight: 700; color: var(--tinta); margin: 4px 0 6px; }
  .caja-card .desglose { font-size: 12.5px; color: var(--texto-2); }
  .caja-card--pend { background: var(--badge-bg); border-color: var(--accion); }
  .tabla-caja { width: 100%; border-collapse: collapse; background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); overflow: hidden; }
  .tabla-caja th { text-align: left; font-size: 12px; color: var(--texto-2); font-weight: 600; padding: 10px 12px; border-bottom: 1px solid var(--borde); }
  .tabla-caja td { padding: 10px 12px; border-bottom: 1px solid var(--borde); font-size: 14px; vertical-align: middle; }
  .tabla-caja tr:last-child td { border-bottom: 0; }
  .pill-pagado { display: inline-flex; align-items: center; gap: 6px; background: #E5F3EC; color: #1E6B4B; font-size: 12.5px; font-weight: 600; padding: 4px 9px; border-radius: 999px; }
  .btn-cobro { border: 1px solid var(--borde); background: var(--superficie); color: var(--tinta); font-size: 12.5px; font-weight: 600; padding: 6px 10px; border-radius: var(--radio-sm, 8px); cursor: pointer; }
  .btn-cobro:hover { border-color: var(--accion); color: var(--accion); }
  .btn-cobro--efectivo:hover { background: #EAF6EF; }
  .btn-deshacer { border: 0; background: none; color: var(--texto-2); font-size: 12px; cursor: pointer; text-decoration: underline; }
  .cobro-acc { display: inline-flex; gap: 6px; }
  .caja-info { font-size: 13px; color: var(--texto-2); margin: 0 0 18px; }
CSS;

layout_inicio('Caja', 'negocio', 'caja', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Caja</h1>
  <p class="caja-info">Marca cada cita como cobrada (efectivo o transferencia) y consulta tu corte. Tip: desde tu WhatsApp puedes preguntarle al asistente <strong>"corte de hoy"</strong> o <strong>"cuánto llevo esta semana"</strong>.</p>

  <div class="caja-cards">
    <?php foreach ($totales as $t): ?>
      <div class="caja-card">
        <small><?= h($t['lbl']) ?></small>
        <div class="monto"><?= caja_money($t['total']) ?></div>
        <div class="desglose"><?= (int)$t['num'] ?> cobrada(s) · Efectivo <?= caja_money($t['efectivo']) ?> · Transf. <?= caja_money($t['transferencia']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($pend['num'] > 0): ?>
      <div class="caja-card caja-card--pend">
        <small>Pendiente de cobro</small>
        <div class="monto"><?= caja_money($pend['total']) ?></div>
        <div class="desglose"><?= (int)$pend['num'] ?> cita(s) pasada(s) sin cobrar</div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$citas): ?>
    <div class="vacio">Aún no hay citas para cobrar.</div>
  <?php else: ?>
    <table class="tabla-caja">
      <thead>
        <tr><th>Día</th><th>Cliente</th><th>Servicio</th><th>Precio</th><th>Cobro</th></tr>
      </thead>
      <tbody>
      <?php foreach ($citas as $c): $precio = precio_de_servicio((string)$c['servicio'], $idNegocio); ?>
        <tr>
          <td><?= h($c['dia_texto']) ?><?= $c['hora'] ? ' · ' . h($c['hora']) : '' ?></td>
          <td><?= h($c['nombre']) ?></td>
          <td><?= h($c['servicio']) ?></td>
          <td><?= caja_money($precio) ?></td>
          <td>
            <?php if ((int)$c['pagado'] === 1): ?>
              <span class="pill-pagado"><i class="fas fa-check-circle"></i> <?= caja_money((float)$c['monto_cobrado']) ?> · <?= h($c['metodo_pago']) ?></span>
              <form method="post" style="display:inline;">
                <?= campo_csrf() ?>
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-deshacer" name="accion" value="descobrar">deshacer</button>
              </form>
            <?php else: ?>
              <span class="cobro-acc">
                <form method="post" style="display:inline;">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="metodo" value="efectivo">
                  <button class="btn-cobro btn-cobro--efectivo" name="accion" value="cobrar"><i class="fas fa-money-bill-wave"></i> Efectivo</button>
                </form>
                <form method="post" style="display:inline;">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="metodo" value="transferencia">
                  <button class="btn-cobro" name="accion" value="cobrar"><i class="fas fa-building-columns"></i> Transferencia</button>
                </form>
              </span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php
layout_fin();
