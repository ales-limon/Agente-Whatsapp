<?php
// Chat web PÚBLICO del negocio (?t=slug). Sin login: cualquier cliente puede chatear
// con el asistente desde el navegador (por QR o enlace), sin WhatsApp ni Twilio.
// Reusa el mismo agente; guarda la conversación real (aparece en el panel, cuenta
// para el medidor y puede escalarse). Cada visitante tiene su propia conversación.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/config/seguridad.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/claude.php';
require_once __DIR__ . '/src/conversaciones.php';
require_once __DIR__ . '/src/uso.php';
require_once __DIR__ . '/src/escalacion.php';
require_once __DIR__ . '/csrf.php';

cargar_entorno();
aplicar_headers_seguridad();
iniciar_sesion_segura();

$slug    = $_GET['t'] ?? '';
$negocio = $slug !== '' ? negocio_por_slug($slug) : null;
if (!$negocio) {
    http_response_code(404);
    echo 'Negocio no encontrado.';
    exit;
}
$idNegocio = (int)$negocio['id'];

// Identidad anónima del visitante (una conversación por sesión).
$claveVis = 'chatpub_vis_' . $idNegocio;
if (empty($_SESSION[$claveVis])) {
    $_SESSION[$claveVis] = 'web:' . bin2hex(random_bytes(6));
}
$contacto = $_SESSION[$claveVis];

// --- Polling: mensajes nuevos desde un id (para ver respuestas de una persona) ---
if (isset($_GET['nuevos'])) {
    $desde = (int)$_GET['nuevos'];
    $st = conexion()->prepare("SELECT id, rol, contenido FROM mensajes WHERE id_negocio = ? AND contacto = ? AND id > ? ORDER BY id");
    $st->execute([$idNegocio, $contacto, $desde]);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['mensajes' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: recibe un mensaje del visitante y responde ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $entrada = json_decode(file_get_contents('php://input'), true) ?: [];
    $mensaje = trim($entrada['mensaje'] ?? '');
    if ($mensaje === '') { echo json_encode(['error' => 'vacio']); exit; }

    // Tope de uso del plan: no gastamos IA si se alcanzó el límite del mes.
    if (!dentro_de_limite($negocio)) {
        guardar_mensaje($idNegocio, $contacto, 'user', $mensaje);
        echo json_encode(['respuesta' => mensaje_limite($negocio), 'ultimoId' => (int)conexion()->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Escalado a humano: el bot queda en pausa. No respondemos (una persona contesta
    // desde el panel y el cliente lo verá por el polling).
    if (handoff_activo($idNegocio, $contacto)) {
        guardar_mensaje($idNegocio, $contacto, 'user', $mensaje);
        echo json_encode(['respuesta' => '', 'ultimoId' => (int)conexion()->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $historial    = cargar_historial($idNegocio, $contacto);
    $c            = cargar_conocimiento($idNegocio);
    $systemPrompt = construir_system_prompt($c) . bloque_contexto_domicilio($idNegocio, $c, $contacto);

    $uso       = ['entrada' => 0, 'salida' => 0];
    $respuesta = responder_con_claude($systemPrompt, $historial, $mensaje, $contacto, $idNegocio, $uso);

    guardar_mensaje($idNegocio, $contacto, 'user', $mensaje);
    guardar_mensaje($idNegocio, $contacto, 'assistant', $respuesta);
    $ultId = (int)conexion()->lastInsertId();
    registrar_uso($idNegocio, (int)$uso['entrada'], (int)$uso['salida']);

    echo json_encode(['respuesta' => $respuesta, 'ultimoId' => $ultId], JSON_UNESCAPED_UNICODE);
    exit;
}

$negocioNombre = htmlspecialchars($negocio['nombre'], ENT_QUOTES, 'UTF-8');
$tokenCsrf     = generar_token_csrf();
$waNumero      = preg_replace('/[^0-9]/', '', (string)($negocio['numero_whatsapp'] ?? ''));
$waLink        = $waNumero !== '' ? 'https://wa.me/' . $waNumero : '';
$historial     = cargar_historial($idNegocio, $contacto);
$stMax = conexion()->prepare("SELECT COALESCE(MAX(id),0) FROM mensajes WHERE id_negocio = ? AND contacto = ?");
$stMax->execute([$idNegocio, $contacto]);
$ultimoId      = (int)$stMax->fetchColumn();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A2B3A">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= $negocioNombre ?>">
<title>Chat — <?= $negocioNombre ?></title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  body { margin: 0; background: #EAF1F2; min-height: 100vh; min-height: 100dvh; display: flex; align-items: stretch; justify-content: center; overflow-x: hidden; }
  .chat { width: 100%; max-width: 520px; height: 100vh; height: 100dvh; display: flex; flex-direction: column; background: #EAF1F2; }
  .chat-top { background: var(--marca); color: #fff; padding: calc(12px + env(safe-area-inset-top)) 16px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .chat-top .info { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .chat-top .avatar { width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,.14); display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
  .chat-top strong { display: block; font-size: 15px; font-weight: 600; }
  .chat-top span { font-size: 12px; opacity: .8; }
  .wa-btn { display: inline-flex; align-items: center; gap: 7px; background: #25D366; color: #06281D; font-weight: 600; font-size: 13px; padding: 8px 12px; border-radius: var(--radio-sm); text-decoration: none; white-space: nowrap; }
  #mensajes { flex: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 8px; }
  .burbuja { max-width: 80%; padding: 9px 13px; border-radius: 12px; font-size: 15px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
  .cliente { align-self: flex-end; background: var(--badge-bg); color: var(--tinta); border-bottom-right-radius: 4px; }
  .asistente { align-self: flex-start; background: var(--superficie); border: 1px solid var(--borde); border-bottom-left-radius: 4px; }
  #escribiendo { color: var(--texto-2); font-size: 13px; padding: 4px 18px; display: none; }
  .chat-footer { background: var(--superficie); border-top: 1px solid var(--borde); padding: 12px; padding-bottom: calc(12px + env(safe-area-inset-bottom)); display: flex; gap: 8px; align-items: center; }
  #texto { flex: 1; min-width: 0; border: 1.5px solid var(--borde); border-radius: 22px; padding: 11px 16px; font-size: 16px; outline: none; font-family: var(--fuente-cuerpo); color: var(--tinta); }
  #texto:focus { border-color: var(--marca); }
  #enviar { background: var(--accion); color: #fff; border: 0; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; font-size: 16px; flex-shrink: 0; }
  #enviar:disabled { opacity: .5; cursor: default; }
</style>
</head>
<body>
  <div class="chat">
    <div class="chat-top">
      <div class="info">
        <span class="avatar"><i class="fas fa-comment-dots"></i></span>
        <div>
          <strong><?= $negocioNombre ?></strong>
          <span>Asistente en línea</span>
        </div>
      </div>
      <?php if ($waLink !== ''): ?>
        <a class="wa-btn" href="<?= h($waLink) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Seguir en WhatsApp</a>
      <?php endif; ?>
    </div>

    <div id="mensajes">
      <?php if (!$historial): ?>
        <div class="burbuja asistente">Hola, soy el asistente de <?= $negocioNombre ?>. Pregúntame por servicios, precios, horarios o agenda una cita.</div>
      <?php else: foreach ($historial as $m): $clase = (($m['role'] ?? '') === 'assistant') ? 'asistente' : 'cliente'; ?>
        <div class="burbuja <?= $clase ?>"><?= h($m['content'] ?? '') ?></div>
      <?php endforeach; endif; ?>
    </div>
    <div id="escribiendo">escribiendo...</div>

    <div class="chat-footer">
      <input id="texto" type="text" placeholder="Escribe un mensaje" autocomplete="off" autofocus>
      <button id="enviar" type="button" aria-label="Enviar"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div>

<script>
  const cont = document.getElementById('mensajes');
  const escribiendo = document.getElementById('escribiendo');
  const input = document.getElementById('texto');
  const btn = document.getElementById('enviar');
  const ENDPOINT = location.pathname + location.search;
  const CSRF = '<?= $tokenCsrf ?>';
  let ultimoId = <?= (int)$ultimoId ?>;
  let enviando = false;

  cont.scrollTop = cont.scrollHeight;

  function agregar(texto, clase) {
    const div = document.createElement('div');
    div.className = 'burbuja ' + clase;
    div.textContent = texto;
    cont.appendChild(div);
    cont.scrollTop = cont.scrollHeight;
  }

  async function enviar() {
    if (enviando) return;
    const mensaje = input.value.trim();
    if (!mensaje) return;
    enviando = true;
    agregar(mensaje, 'cliente');
    input.value = '';
    btn.disabled = true;
    escribiendo.style.display = 'block';
    cont.scrollTop = cont.scrollHeight;
    try {
      const r = await fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ mensaje }) });
      const data = await r.json();
      if (typeof data.ultimoId !== 'undefined') ultimoId = Math.max(ultimoId, data.ultimoId);
      if (data.respuesta) agregar(data.respuesta, 'asistente');
      else if (data.error) agregar(data.error, 'asistente');
    } catch (e) {
      agregar('No se pudo enviar tu mensaje. Revisa tu conexión e intenta de nuevo.', 'asistente');
    } finally {
      escribiendo.style.display = 'none';
      btn.disabled = false;
      enviando = false;
      input.focus();
    }
  }

  btn.addEventListener('click', enviar);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') enviar(); });

  // Polling: muestra respuestas de una persona del negocio en vivo.
  async function poll() {
    if (enviando) return; // no competir con un envío en curso (evita duplicados)
    try {
      const r = await fetch(ENDPOINT + '&nuevos=' + ultimoId);
      const d = await r.json();
      (d.mensajes || []).forEach(function (m) {
        ultimoId = Math.max(ultimoId, parseInt(m.id, 10));
        agregar(m.contenido, m.rol === 'assistant' ? 'asistente' : 'cliente');
      });
    } catch (e) {}
  }
  setInterval(poll, 4000);
</script>
</body>
</html>
