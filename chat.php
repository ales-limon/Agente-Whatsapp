<?php
// Pagina de chat para probar el agente de un negocio desde el navegador.
// El negocio se elige con ?t=slug. La conversacion de prueba vive en la sesion
// (no ensucia la tabla de mensajes), pero las citas SI se guardan en la BD.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/claude.php';
require_once __DIR__ . '/csrf.php';

cargar_entorno();
aplicar_headers_seguridad();
iniciar_sesion_segura();

$slug    = $_GET['t'] ?? '';
$negocio = $slug !== '' ? negocio_por_slug($slug) : primer_negocio();
if (!$negocio) {
    http_response_code(404);
    echo 'No hay negocios. Crea uno en <a href="superadmin.php">superadmin</a>.';
    exit;
}
$idNegocio = (int)$negocio['id'];
requiere_acceso_negocio($idNegocio);
$claveSesion = 'chat_web_' . $idNegocio;

// --- AJAX: recibe un mensaje y devuelve la respuesta de la IA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $entrada = json_decode(file_get_contents('php://input'), true) ?: [];

    if (($entrada['accion'] ?? '') === 'reset') {
        $_SESSION[$claveSesion] = [];
        echo json_encode(['ok' => true]);
        exit;
    }

    $mensaje = trim($entrada['mensaje'] ?? '');
    if ($mensaje === '') {
        echo json_encode(['error' => 'mensaje vacio']);
        exit;
    }

    $historial    = $_SESSION[$claveSesion] ?? [];
    $c            = cargar_conocimiento($idNegocio);
    $systemPrompt = construir_system_prompt($c);
    $respuesta    = responder_con_claude($systemPrompt, $historial, $mensaje, 'Chat de prueba', $idNegocio);

    $historial[] = ['role' => 'user', 'content' => $mensaje];
    $historial[] = ['role' => 'assistant', 'content' => $respuesta];
    $_SESSION[$claveSesion] = array_slice($historial, -20);

    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

$negocioNombre = htmlspecialchars($negocio['nombre'], ENT_QUOTES, 'UTF-8');
$slugSafe      = htmlspecialchars($negocio['slug'], ENT_QUOTES, 'UTF-8');
$tokenCsrf     = generar_token_csrf();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chat de prueba — <?= $negocioNombre ?></title>
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: var(--fuente-cuerpo); color: var(--tinta);
         background: #EAF1F2; height: 100vh; display: flex; flex-direction: column; }
  .barra { background: var(--marca); color: #fff; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; }
  .barra .info { display: flex; align-items: center; gap: 10px; }
  .barra .avatar { width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,.14); display: inline-flex; align-items: center; justify-content: center; font-size: 16px; }
  .barra .info strong { display: block; font-size: 15px; font-weight: 600; }
  .barra .info span { font-size: 12px; opacity: .8; }
  .barra .acc a, .barra .acc button { background: rgba(255,255,255,.14); color: #fff; border: 0;
                  padding: 8px 12px; border-radius: var(--radio-sm); cursor: pointer; font-size: 13px; text-decoration: none; margin-left: 6px; font-family: inherit; }
  .barra .acc a:hover, .barra .acc button:hover { background: rgba(255,255,255,.26); }
  #mensajes { flex: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 8px; }
  .burbuja { max-width: 78%; padding: 9px 13px; border-radius: 12px; font-size: 15px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
  .cliente { align-self: flex-end; background: var(--badge-bg); color: var(--tinta); border-bottom-right-radius: 4px; }
  .asistente { align-self: flex-start; background: var(--superficie); border: 1px solid var(--borde); border-bottom-left-radius: 4px; }
  #escribiendo { align-self: flex-start; color: var(--texto-2); font-size: 13px; padding: 4px 12px; display: none; }
  footer { background: var(--superficie); border-top: 1px solid var(--borde); padding: 12px; display: flex; gap: 8px; }
  #texto { flex: 1; border: 1.5px solid var(--borde); border-radius: 22px; padding: 11px 16px; font-size: 15px; outline: none; font-family: inherit; color: var(--tinta); }
  #texto:focus { border-color: var(--marca); }
  #enviar { background: var(--accion); color: #fff; border: 0; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; font-size: 16px; }
  #enviar:disabled { opacity: .5; cursor: default; }
</style>
</head>
<body>
  <header class="barra">
    <div class="info">
      <span class="avatar"><i class="fas fa-comment-dots"></i></span>
      <div>
        <strong><?= $negocioNombre ?></strong>
        <span>Asistente de WhatsApp (prueba)</span>
      </div>
    </div>
    <div class="acc">
      <a href="panel.php?t=<?= $slugSafe ?>">Citas</a>
      <a href="configuracion.php?t=<?= $slugSafe ?>">Config</a>
      <a href="logout.php">Salir</a>
      <button id="reiniciar" type="button">Reiniciar</button>
    </div>
  </header>

  <div id="mensajes">
    <div class="burbuja asistente">Hola, soy el asistente de <?= $negocioNombre ?>. Preguntame por servicios, precios, horarios o agenda una cita.</div>
  </div>
  <div id="escribiendo">escribiendo...</div>

  <footer>
    <input id="texto" type="text" placeholder="Escribe un mensaje" autocomplete="off" autofocus>
    <button id="enviar" type="button" aria-label="Enviar"><i class="fas fa-paper-plane"></i></button>
  </footer>

<script>
  const cont = document.getElementById('mensajes');
  const escribiendo = document.getElementById('escribiendo');
  const input = document.getElementById('texto');
  const btn = document.getElementById('enviar');
  const ENDPOINT = 'chat.php' + location.search;
  const CSRF = '<?= $tokenCsrf ?>';

  function agregar(texto, clase) {
    const div = document.createElement('div');
    div.className = 'burbuja ' + clase;
    div.textContent = texto;
    cont.appendChild(div);
    cont.scrollTop = cont.scrollHeight;
  }

  async function enviar() {
    const mensaje = input.value.trim();
    if (!mensaje) return;
    agregar(mensaje, 'cliente');
    input.value = '';
    btn.disabled = true;
    escribiendo.style.display = 'block';
    cont.scrollTop = cont.scrollHeight;
    try {
      const r = await fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ mensaje }) });
      const data = await r.json();
      agregar(data.respuesta || data.error || 'Sin respuesta.', 'asistente');
    } catch (e) {
      agregar('Error de conexion. Revisa que Laragon este corriendo.', 'asistente');
    } finally {
      escribiendo.style.display = 'none';
      btn.disabled = false;
      input.focus();
    }
  }

  btn.addEventListener('click', enviar);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') enviar(); });

  document.getElementById('reiniciar').addEventListener('click', async () => {
    await fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ accion: 'reset' }) });
    cont.innerHTML = '<div class="burbuja asistente">Conversacion reiniciada. En que te puedo ayudar?</div>';
    input.focus();
  });
</script>
</body>
</html>
