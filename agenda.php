<?php
// Agenda en calendario (mes / semana / dia) de un negocio (?t=slug). Muestra las
// citas como eventos. Reusa FullCalendar por CDN (igual que Font Awesome/qrcode).
// El endpoint ?eventos=1 devuelve las citas como eventos JSON para el calendario.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/layout.php';
require_once __DIR__ . '/config/db.php';
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

// --- Endpoint JSON: citas como eventos del calendario ---
if (isset($_GET['eventos'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $st = $pdo->prepare(
        "SELECT id, nombre, servicio, profesional, fecha, hora, duracion, estado, contacto
         FROM citas WHERE id_negocio = ? AND estado <> 'cancelada' AND fecha IS NOT NULL
         ORDER BY fecha, hora"
    );
    $st->execute([$idNegocio]);

    // Colores por estado (usando la paleta de identidad).
    $colores = [
        'pendiente'  => '#138496', // teal (acción)
        'confirmada' => '#2E7D5B', // verde
    ];

    $eventos = [];
    foreach ($st as $c) {
        $fecha = (string)$c['fecha'];
        // Normalizar la hora a HH:MM (toleramos "12:30", "12:30 hrs", etc.).
        $horaRaw = preg_replace('/[^0-9:]/', '', (string)($c['hora'] ?? ''));
        $tieneHora = (bool)preg_match('/^\d{1,2}:\d{2}$/', $horaRaw);

        $prof     = trim((string)($c['profesional'] ?? ''));
        $titulo   = trim((string)$c['nombre']) . ' · ' . trim((string)$c['servicio']);
        if ($prof !== '') $titulo .= ' (' . $prof . ')';

        $estado = (string)$c['estado'];
        $color  = $colores[$estado] ?? '#57707A';

        $ev = [
            'id'              => (int)$c['id'],
            'title'           => $titulo,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'extendedProps'   => [
                'folio'       => (int)$c['id'],
                'cliente'     => (string)$c['nombre'],
                'servicio'    => (string)$c['servicio'],
                'profesional' => $prof,
                'estado'      => $estado,
                'contacto'    => (string)($c['contacto'] ?? ''),
                'hora'        => $tieneHora ? $horaRaw : '',
            ],
        ];

        if ($tieneHora) {
            $dur = (int)($c['duracion'] ?? 0);
            if ($dur <= 0) $dur = 30;
            $ini = strtotime("$fecha $horaRaw");
            $ev['start'] = date('Y-m-d\TH:i:s', $ini);
            $ev['end']   = date('Y-m-d\TH:i:s', $ini + $dur * 60);
        } else {
            $ev['start']   = $fecha;
            $ev['allDay']  = true;
        }
        $eventos[] = $ev;
    }

    echo json_encode($eventos, JSON_UNESCAPED_UNICODE);
    exit;
}

$slugSafe = urlencode($negocio['slug']);
$urlEventos = 'agenda.php?t=' . $slugSafe . '&eventos=1';

$css = <<<CSS
  #cal-wrap { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); padding: 16px; }
  #calendario { max-width: 100%; }
  /* Botones de FullCalendar con la paleta de la marca */
  .fc .fc-button-primary { background: var(--accion); border-color: var(--accion); color: #fff; font-weight: 600; }
  .fc .fc-button-primary:hover { background: #0f6f7d; border-color: #0f6f7d; }
  .fc .fc-button-primary:not(:disabled).fc-button-active,
  .fc .fc-button-primary:not(:disabled):active { background: var(--marca); border-color: var(--marca); }
  .fc .fc-button-primary:disabled { background: var(--texto-2); border-color: var(--texto-2); }
  .fc .fc-toolbar-title { font-family: var(--fuente-titulo); font-size: 18px; color: var(--tinta); text-transform: capitalize; }
  .fc .fc-col-header-cell-cushion, .fc .fc-daygrid-day-number { color: var(--tinta); text-decoration: none; }
  .fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid { border-color: var(--borde); }
  .fc .fc-day-today { background: var(--badge-bg) !important; }
  .fc-event { cursor: pointer; padding: 1px 3px; font-size: 12px; }
  .agenda-info { font-size: 13px; color: var(--texto-2); margin: 0 0 14px; }
CSS;

layout_inicio('Agenda', 'negocio', 'agenda', ['negocio' => $negocio, 'css' => $css]);
?>
  <h1 class="contenido__h1">Agenda</h1>
  <p class="agenda-info">Tus citas en calendario. Cambia entre <strong>Mes</strong>, <strong>Semana</strong> y <strong>Día</strong> con los botones de la derecha. Las canceladas no se muestran.</p>
  <div id="cal-wrap"><div id="calendario"></div></div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales/es.global.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('calendario');
    var cal = new FullCalendar.Calendar(el, {
      locale: 'es',
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay'
      },
      buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', day: 'Día' },
      nowIndicator: true,
      slotMinTime: '07:00:00',
      slotMaxTime: '21:00:00',
      expandRows: true,
      height: 'auto',
      events: '<?= $urlEventos ?>',
      eventDidMount: function (info) {
        var p = info.event.extendedProps;
        var t = 'Folio #' + p.folio + '\n' + p.cliente + '\n' + p.servicio;
        if (p.profesional) t += '\nAtiende: ' + p.profesional;
        if (p.hora) t += '\nHora: ' + p.hora;
        t += '\nEstado: ' + p.estado;
        info.el.setAttribute('title', t);
      }
    });
    cal.render();
  });
</script>
<?php
layout_fin();
