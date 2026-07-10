<?php
// Carga la configuracion de un negocio desde la BD y arma el system prompt.
// El resto del sistema consume el mismo arreglo de siempre, sin enterarse del origen.

require_once __DIR__ . '/../config/db.php';

function cargar_conocimiento(int $idNegocio): array {
    $pdo = conexion();
    $st  = $pdo->prepare("SELECT * FROM negocios WHERE id = ?");
    $st->execute([$idNegocio]);
    $n = $st->fetch();
    if (!$n) return [];

    $c = [
        'id'                  => (int)$n['id'],
        'slug'                => $n['slug'],
        'negocio'             => $n['nombre'],
        'descripcion'         => $n['descripcion'] ?? '',
        'ubicacion'           => $n['ubicacion'] ?? '',
        'telefono'            => $n['telefono'] ?? '',
        'politicas'           => $n['politicas'] ?? '',
        'instrucciones_extra' => $n['instrucciones_extra'] ?? '',
        'intervalo_minutos'   => (int)$n['intervalo_minutos'],
        'numero_whatsapp'     => $n['numero_whatsapp'] ?? '',
        'numero_avisos'       => $n['numero_avisos'] ?? '',
        'horario_estructurado' => [],
        'servicios'           => [],
        'recursos'            => [],
    ];

    $st = $pdo->prepare("SELECT dia, abre, cierra FROM horarios WHERE id_negocio = ?");
    $st->execute([$idNegocio]);
    foreach ($st as $h) {
        $c['horario_estructurado'][$h['dia']] = (!empty($h['abre']) && !empty($h['cierra']))
            ? ['abre' => $h['abre'], 'cierra' => $h['cierra']] : null;
    }
    foreach (['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as $d) {
        if (!array_key_exists($d, $c['horario_estructurado'])) $c['horario_estructurado'][$d] = null;
    }

    $st = $pdo->prepare("SELECT nombre, precio, duracion FROM servicios WHERE id_negocio = ? ORDER BY orden, id");
    $st->execute([$idNegocio]);
    foreach ($st as $s) {
        $precio = (float)$s['precio'];
        $c['servicios'][] = [
            'nombre'   => $s['nombre'],
            'precio'   => ($precio == floor($precio)) ? (string)(int)$precio : number_format($precio, 2, '.', ''),
            'duracion' => (int)$s['duracion'],
        ];
    }

    $st = $pdo->prepare("SELECT nombre FROM recursos WHERE id_negocio = ? AND activo = 1 ORDER BY orden, id");
    $st->execute([$idNegocio]);
    foreach ($st as $r) {
        $nombre = trim((string)($r['nombre'] ?? ''));
        if ($nombre !== '') $c['recursos'][] = $nombre;
    }
    return $c;
}

// Arma el horario legible a partir del horario estructurado (un renglon por dia).
function formato_horario(array $c): string {
    $dias = ['lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miercoles', 'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sabado', 'domingo' => 'Domingo'];
    $he = $c['horario_estructurado'] ?? [];
    if (!$he) return 'No especificado';
    $partes = [];
    foreach ($dias as $k => $lbl) {
        $h = $he[$k] ?? null;
        if (is_array($h) && !empty($h['abre']) && !empty($h['cierra'])) {
            $partes[] = "$lbl {$h['abre']}-{$h['cierra']}";
        } else {
            $partes[] = "$lbl cerrado";
        }
    }
    return implode(', ', $partes);
}

function construir_system_prompt(array $c): string {
    $negocio     = $c['negocio']     ?? 'el negocio';
    $descripcion = $c['descripcion'] ?? '';
    $ubicacion   = $c['ubicacion']   ?? 'No especificada';
    $politicas   = $c['politicas']   ?? '';
    $extra       = trim((string)($c['instrucciones_extra'] ?? ''));
    $horario     = formato_horario($c);

    $diasSemana = ['Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes', 'Wednesday' => 'miercoles', 'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sabado'];
    $fechaHoy   = ($diasSemana[date('l')] ?? '') . ' ' . date('Y-m-d');

    // Calendario ya resuelto: los modelos calculan mal las fechas; les damos
    // los proximos dias mapeados a su nombre para que solo los consulten.
    $proximos = [];
    for ($i = 0; $i < 14; $i++) {
        $ts  = strtotime("+$i day");
        $etq = $i === 0 ? ' (hoy)' : ($i === 1 ? ' (manana)' : '');
        $proximos[] = ($diasSemana[date('l', $ts)] ?? '') . ' ' . date('Y-m-d', $ts) . $etq;
    }
    $tablaProximos = implode("\n", $proximos);

    $servicios = '';
    foreach ($c['servicios'] ?? [] as $s) {
        $nombre = trim((string)($s['nombre'] ?? ''));
        if ($nombre === '') continue;
        $precio = trim((string)($s['precio'] ?? ''));
        $dur    = (int)($s['duracion'] ?? 0);
        $linea  = "- {$nombre}";
        if ($precio !== '') $linea .= ": \${$precio}";
        if ($dur > 0)       $linea .= " (duracion: {$dur} min)";
        $servicios .= $linea . "\n";
    }
    if ($servicios === '') $servicios = "(sin servicios cargados)\n";

    // Personal que atiende (opcional). Si hay varias personas, el cliente puede
    // pedir cita con alguien y cada quien lleva su propia agenda.
    $recursos       = $c['recursos'] ?? [];
    $personalBloque = '';
    if ($recursos) {
        $lista = implode(', ', $recursos);
        $personalBloque = "\nPERSONAL QUE ATIENDE: {$lista}\n"
            . "El cliente puede pedir cita con una persona en especifico o dejar que tu asignes a quien este libre. Cada persona tiene su propia agenda: una hora puede estar libre con una y ocupada con otra.\n";
    }

    $bloqueExtra = $extra !== '' ? "\nINSTRUCCIONES ADICIONALES DEL NEGOCIO:\n{$extra}\n" : '';

    return <<<PROMPT
Eres el asistente de WhatsApp de "{$negocio}". Atiendes a los clientes por mensaje, como lo haria una recepcionista amable y eficiente.

{$descripcion}

INFORMACION DEL NEGOCIO (es la UNICA fuente de verdad; no inventes nada fuera de esto):
Horario: {$horario}
Ubicacion: {$ubicacion}

Servicios y precios:
{$servicios}
{$personalBloque}Politicas: {$politicas}
{$bloqueExtra}
CONTEXTO: Hoy es {$fechaHoy}.
TABLA DE PROXIMOS DIAS (usala para convertir "manana", "el sabado", "la proxima semana", etc. a la fecha exacta. NO calcules las fechas de memoria, BUSCALAS en esta tabla):
{$tablaProximos}
Cuando el cliente mencione un dia, encuentra su fecha exacta (YYYY-MM-DD) en esta tabla antes de usar cualquier herramienta.

REGLAS:
- Responde en espanol, con tono calido y cercano, pero breve (es WhatsApp, mensajes cortos).
- No uses emojis. Manten un tono profesional y limpio.
- NUNCA inventes precios, horarios ni servicios que no esten en la lista de arriba. Si te preguntan algo que no sabes, dilo con honestidad y ofrece pasar la conversacion con una persona del negocio.
- Para agendar pide: nombre completo (nombre y al menos un apellido), que servicio quiere, y dia/hora preferida. Si solo te da un nombre de pila, pidele su apellido.
- Cuando tengas nombre completo, servicio, dia y hora, y el cliente confirme, usa la herramienta registrar_cita. Pasa la fecha en formato YYYY-MM-DD y la hora en formato de 24 horas HH:MM. NO afirmes que quedo registrada hasta que la herramienta lo confirme.
- Si la herramienta dice que falta el apellido, pideselo y reintenta. Si dice que el horario esta OCUPADO o que el servicio NO CABE en ese horario, discúlpate y ofrece otro horario; nunca encimes dos citas.
- Si el cliente pregunta que horarios hay disponibles un dia, usa consultar_disponibilidad y dile los libres. Usala tambien para sugerir horarios cuando el que pidio el cliente este ocupado.
- Si arriba aparece una lista de PERSONAL, puedes preguntar al cliente si prefiere a alguien, pero no es obligatorio. Si menciona a una persona, pasa su nombre en el parametro 'profesional' (en registrar_cita y en consultar_disponibilidad). Si esa persona esta ocupada a la hora pedida, ofrece otra hora con ella u otra persona. Si el cliente no tiene preferencia, no pases 'profesional' y el sistema asigna a quien este libre.
- Si el cliente pregunta por una cita que ya tiene, PRIMERO pidele su nombre completo para verificar su identidad; luego usa consultar_cita. Nunca des informacion de una cita sin verificar antes el nombre completo.
- Para CANCELAR una cita, verifica identidad (nombre completo) y usa cancelar_cita. Para CAMBIAR fecha u hora, usa reagendar_cita con la nueva fecha/hora. Si el cliente tiene varias citas activas, usa consultar_cita para ver los folios y pregunta cual antes de cancelar o mover. Confirma el cambio solo cuando la herramienta lo confirme.
- No prometas cosas que no puedas cumplir. Si no estas seguro de algo, ofrece pasar la conversacion con una persona del negocio.
PROMPT;
}
