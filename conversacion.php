<?php
// Vista de una conversación (?t=slug&c=contacto): el dueño ve el hilo completo y
// puede RETOMARLA (responder como humano). Al responder, el bot se pausa para ese
// chat. En el chat web el cliente ve la respuesta en vivo (polling); en WhatsApp
// se envía por Twilio (si está configurado).

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/src/escalacion.php';
require_once __DIR__ . '/src/notificaciones.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/csrf.php';
cargar_entorno();
aplicar_headers_seguridad();

$slug    = $_GET['t'] ?? '';
$negocio = $slug !== '' ? negocio_por_slug($slug) : primer_negocio();
if (!$negocio) { echo 'No hay negocios.'; exit; }
$idNegocio = (int)$negocio['id'];
requiere_acceso_negocio($idNegocio);
$slugSafe = htmlspecialchars($negocio['slug'], ENT_QUOTES, 'UTF-8');
$pdo = conexion();

$contacto = trim($_GET['c'] ?? '');
if ($contacto === '') { header('Location: panel.php?t=' . urlencode($negocio['slug'])); exit; }

// --- Polling: mensajes nuevos desde un id (JSON) ---
if (isset($_GET['nuevos'])) {
    $desde = (int)$_GET['nuevos'];
    $st = $pdo->prepare("SELECT id, rol, contenido FROM mensajes WHERE id_negocio = ? AND contacto = ? AND id > ? ORDER BY id");
    $st->execute([$idNegocio, $contacto, $desde]);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['mensajes' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Reactivar el bot (form normal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reactivar') {
    requiere_csrf();
    desactivar_handoff($idNegocio, $contacto);
    header('Location: conversacion.php?t=' . urlencode($negocio['slug']) . '&c=' . urlencode($contacto));
    exit;
}

// --- Responder como humano (AJAX JSON) ---
$raw  = file_get_contents('php://input');
$json = $raw !== '' ? json_decode($raw, true) : null;
if ($json !== null && isset($json['texto'])) {
    requiere_csrf();
    $texto = trim((string)$json['texto']);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if ($texto === '') { echo json_encode(['error' => 'vacio']); exit; }

    guardar_mensaje($idNegocio, $contacto, 'assistant', $texto);
    $nuevoId = (int)$pdo->lastInsertId();
    activar_handoff($idNegocio, $contacto, 'Atendido por una persona');

    // Entregar al cliente: web = lo ve por polling; WhatsApp = se manda por Twilio.
    if (strpos($contacto, 'web:') !== 0) {
        $c = cargar_conocimiento($idNegocio);
        enviar_whatsapp($contacto, $texto, (string)($c['numero_whatsapp'] ?? ''));
    }
    echo json_encode(['ok' => true, 'id' => $nuevoId], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare("SELECT id, rol, contenido FROM mensajes WHERE id_negocio = ? AND contacto = ? ORDER BY id");
$st->execute([$idNegocio, $contacto]);
$mensajes  = $st->fetchAll();
$ultimoId  = $mensajes ? (int)end($mensajes)['id'] : 0;
$enHandoff = handoff_activo($idNegocio, $contacto);
$tokenCsrf = generar_token_csrf();
$esWeb     = strpos($contacto, 'web:') === 0;
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$css = '
  .volver { font-size: 13px; color: var(--texto-2); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px; }
  .volver:hover { color: var(--tinta); }
  .conv-cab { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; max-width: 760px; }
  .conv-cab .quien { font-family: var(--fuente-titulo); font-weight: 700; font-size: 18px; }
  .conv-cab .canal { font-size: 12px; color: var(--texto-2); }
  .estado-humano { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: var(--aviso-bg); color: var(--aviso-texto); }
  #hilo { max-width: 760px; height: 58vh; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; background: #EAF1F2; border: 1px solid var(--borde); border-radius: var(--radio); }
  .b { max-width: 78%; padding: 9px 13px; border-radius: 12px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
  .b--in { align-self: flex-start; background: var(--superficie); border: 1px solid var(--borde); }
  .b--out { align-self: flex-end; background: var(--badge-bg); color: var(--tinta); }
  .responder { max-width: 760px; display: flex; gap: 8px; margin-top: 12px; }
  #texto { flex: 1; min-width: 0; border: 1.5px solid var(--borde); border-radius: var(--radio); padding: 11px 14px; font-size: 15px; font-family: var(--fuente-cuerpo); color: var(--tinta); outline: none; }
  #texto:focus { border-color: var(--marca); }
  .nota { max-width: 760px; font-size: 12px; color: var(--texto-2); margin-top: 8px; }
';
layout_inicio('Conversación', 'negocio', 'citas', ['negocio' => $negocio, 'css' => $css]);
?>
  <a class="volver" href="panel.php?t=<?= $slugSafe ?>"><i class="fas fa-arrow-left"></i> Volver a Citas</a>

  <div class="conv-cab">
    <div>
      <div class="quien"><?= h($contacto) ?></div>
      <div class="canal"><?= $esWeb ? 'Chat web' : 'WhatsApp' ?></div>
    </div>
    <div>
      <?php if ($enHandoff): ?>
        <span class="estado-humano">En atención humana</span>
        <form method="post" style="display:inline; margin-left:8px;">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="reactivar">
          <button class="btn-mini" type="submit">Reactivar bot</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div id="hilo">
    <?php foreach ($mensajes as $m): $clase = (($m['rol'] ?? '') === 'assistant') ? 'b--out' : 'b--in'; ?>
      <div class="b <?= $clase ?>"><?= h($m['contenido']) ?></div>
    <?php endforeach; ?>
  </div>

  <div class="responder">
    <input id="texto" type="text" placeholder="Escribe tu respuesta como persona del negocio..." autocomplete="off">
    <button id="enviar" class="btn btn--primario" type="button">Enviar</button>
  </div>
  <div class="nota"><i class="fas fa-circle-info"></i> Al responder, el bot se pausa para este chat<?= $esWeb ? ' y el cliente verá tu mensaje en su chat web.' : '. En WhatsApp se envía por Twilio (si está configurado).' ?></div>

<script>
  const hilo = document.getElementById('hilo');
  const input = document.getElementById('texto');
  const btn = document.getElementById('enviar');
  const BASE = 'conversacion.php?t=<?= rawurlencode($negocio['slug']) ?>&c=<?= rawurlencode($contacto) ?>';
  const CSRF = '<?= $tokenCsrf ?>';
  let ultimoId = <?= (int)$ultimoId ?>;

  hilo.scrollTop = hilo.scrollHeight;

  function burbuja(texto, rol) {
    const div = document.createElement('div');
    div.className = 'b ' + (rol === 'assistant' ? 'b--out' : 'b--in');
    div.textContent = texto;
    hilo.appendChild(div);
    hilo.scrollTop = hilo.scrollHeight;
  }

  async function enviar() {
    const texto = input.value.trim();
    if (!texto) return;
    input.value = '';
    btn.disabled = true;
    try {
      const r = await fetch(BASE, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ texto }) });
      const d = await r.json();
      if (d.ok) { burbuja(texto, 'assistant'); ultimoId = Math.max(ultimoId, d.id || 0); }
    } catch (e) {}
    btn.disabled = false;
    input.focus();
  }

  btn.addEventListener('click', enviar);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') enviar(); });

  async function poll() {
    try {
      const r = await fetch(BASE + '&nuevos=' + ultimoId);
      const d = await r.json();
      (d.mensajes || []).forEach(function (m) {
        ultimoId = Math.max(ultimoId, parseInt(m.id, 10));
        burbuja(m.contenido, m.rol);
      });
    } catch (e) {}
  }
  setInterval(poll, 4000);
</script>
<?php
layout_fin();
