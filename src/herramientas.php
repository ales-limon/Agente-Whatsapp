<?php
// Herramientas que la IA puede usar (tool-use de Claude): registrar, consultar y
// disponibilidad. Todo filtra por id_negocio y respeta la duracion de cada servicio.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/conocimiento.php';
require_once __DIR__ . '/notificaciones.php';

function herramientas_disponibles(): array {
    return [
        [
            'name'        => 'registrar_cita',
            'description' => 'Registra una cita NUEVA. Usala SOLO cuando tengas confirmados: nombre completo del cliente (nombre y al menos un apellido), servicio, dia y hora. No anuncies al cliente que la cita quedo registrada hasta haber llamado a esta herramienta y recibido la confirmacion.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nombre'   => ['type' => 'string', 'description' => 'Nombre completo del cliente (nombre y al menos un apellido)'],
                    'servicio' => ['type' => 'string', 'description' => 'Servicio solicitado (tal cual aparece en la lista de servicios)'],
                    'fecha'    => ['type' => 'string', 'description' => 'Fecha de la cita en formato YYYY-MM-DD'],
                    'hora'     => ['type' => 'string', 'description' => 'Hora de inicio en formato de 24 horas HH:MM'],
                    'dia'      => ['type' => 'string', 'description' => 'Dia de la cita tal como lo dijo el cliente, para mostrar (ej: martes 16 de junio)'],
                ],
                'required' => ['nombre', 'servicio', 'fecha', 'hora'],
            ],
        ],
        [
            'name'        => 'consultar_cita',
            'description' => 'Busca la cita existente de un cliente para recordarsela. Usala cuando el cliente pregunte por una cita que ya tiene. ANTES de llamarla, pide al cliente su nombre completo para verificar su identidad.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nombre' => ['type' => 'string', 'description' => 'Nombre completo con el que el cliente dice haber agendado'],
                ],
                'required' => ['nombre'],
            ],
        ],
        [
            'name'        => 'consultar_disponibilidad',
            'description' => 'Devuelve los horarios LIBRES de un dia concreto, considerando el horario del negocio, la duracion del servicio y las citas ya agendadas. Usala cuando el cliente pregunte que horarios hay disponibles, o para sugerirle horarios al agendar.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'fecha'    => ['type' => 'string', 'description' => 'Fecha a consultar en formato YYYY-MM-DD'],
                    'servicio' => ['type' => 'string', 'description' => 'Servicio que quiere el cliente (opcional, mejora el calculo segun su duracion)'],
                ],
                'required' => ['fecha'],
            ],
        ],
    ];
}

function ejecutar_herramienta(string $nombre, array $input, ?string $contacto, int $idNegocio): string {
    switch ($nombre) {
        case 'registrar_cita':           return registrar_cita($input, $contacto, $idNegocio);
        case 'consultar_cita':           return consultar_cita($input, $contacto, $idNegocio);
        case 'consultar_disponibilidad': return consultar_disponibilidad($input, $contacto, $idNegocio);
        default:                         return 'Herramienta no reconocida.';
    }
}

// ---------- utilidades ----------

function normalizar_nombre(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    return preg_replace('/\s+/', ' ', $s);
}

function normalizar_hora(string $h): string {
    $h = mb_strtolower(trim($h), 'UTF-8');
    return str_replace([' ', 'hrs', 'hras', 'hr'], '', $h);
}

function hhmm_a_min(string $s): int {
    $p = array_pad(explode(':', trim($s)), 2, '0');
    return ((int)$p[0]) * 60 + (int)$p[1];
}

function min_a_hhmm(int $m): string {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function nombre_dia(string $fecha): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) return null;
    $n = [0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado'];
    return $n[(int)$dt->format('w')];
}

function horario_del_dia(string $fecha, array $c): ?array {
    $dia = nombre_dia($fecha);
    if ($dia === null) return null;
    $h = $c['horario_estructurado'][$dia] ?? null;
    if (!is_array($h) || empty($h['abre']) || empty($h['cierra'])) return null;
    return [hhmm_a_min($h['abre']), hhmm_a_min($h['cierra'])];
}

function duracion_servicio(string $servicio, array $c): int {
    $buscado = normalizar_nombre($servicio);
    foreach ($c['servicios'] ?? [] as $s) {
        if (normalizar_nombre((string)($s['nombre'] ?? '')) === $buscado) {
            $d = (int)($s['duracion'] ?? 0);
            return $d > 0 ? $d : 30;
        }
    }
    foreach ($c['servicios'] ?? [] as $s) {
        $n = normalizar_nombre((string)($s['nombre'] ?? ''));
        if ($n !== '' && $buscado !== '' && (strpos($n, $buscado) !== false || strpos($buscado, $n) !== false)) {
            $d = (int)($s['duracion'] ?? 0);
            return $d > 0 ? $d : 30;
        }
    }
    $base = (int)($c['intervalo_minutos'] ?? 30);
    return $base > 0 ? $base : 30;
}

// Rangos [inicio, fin] (minutos) ocupados por citas no canceladas ese dia y negocio.
function rangos_ocupados(string $fecha, array $c, int $idNegocio): array {
    $st = conexion()->prepare("SELECT servicio, hora, duracion FROM citas WHERE id_negocio = ? AND fecha = ? AND estado <> 'cancelada'");
    $st->execute([$idNegocio, $fecha]);
    $rangos = [];
    foreach ($st as $cita) {
        $ini = hhmm_a_min(normalizar_hora((string)($cita['hora'] ?? '')));
        $dur = (int)($cita['duracion'] ?? 0);
        if ($dur <= 0) $dur = duracion_servicio((string)($cita['servicio'] ?? ''), $c);
        $rangos[] = [$ini, $ini + $dur];
    }
    return $rangos;
}

function se_traslapa(int $ini, int $fin, array $rangos): bool {
    foreach ($rangos as [$a, $b]) {
        if ($ini < $b && $a < $fin) return true;
    }
    return false;
}

// ---------- herramientas ----------

function registrar_cita(array $datos, ?string $contacto, int $idNegocio): string {
    $nombre = trim((string)($datos['nombre'] ?? ''));
    if (count(preg_split('/\s+/', $nombre, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
        return 'NO REGISTRADA: el cliente dio solo un nombre. Pidele su nombre completo (nombre y al menos un apellido) y vuelve a intentar.';
    }

    $c        = cargar_conocimiento($idNegocio);
    $servicio = trim((string)($datos['servicio'] ?? ''));
    $fecha    = trim((string)($datos['fecha'] ?? ''));
    $horaTxt  = trim((string)($datos['hora'] ?? ''));
    $hora     = normalizar_hora($horaTxt);
    if ($fecha === '' || $hora === '') {
        return 'NO REGISTRADA: falta la fecha o la hora. Pideselas al cliente.';
    }

    $hor = horario_del_dia($fecha, $c);
    if ($hor === null) {
        return 'NO REGISTRADA: la fecha ' . $fecha . ' cae en ' . (nombre_dia($fecha) ?? 'dia desconocido') . ', y el negocio NO abre ese dia. OJO: quiza calculaste mal la fecha; verificala en la tabla de proximos dias. Confirma con el cliente el dia correcto y ofrece un dia abierto.';
    }
    [$abre, $cierra] = $hor;

    $dur = duracion_servicio($servicio, $c);
    $ini = hhmm_a_min($hora);
    $fin = $ini + $dur;

    if ($ini < $abre || $fin > $cierra) {
        return 'NO REGISTRADA: el servicio "' . $servicio . '" dura ' . $dur . ' min y no cabe a esa hora (el negocio cierra a las ' . min_a_hhmm($cierra) . '). Ofrece una hora mas temprana u otro dia. Puedes usar consultar_disponibilidad.';
    }

    if (se_traslapa($ini, $fin, rangos_ocupados($fecha, $c, $idNegocio))) {
        return 'HORARIO OCUPADO: ese horario se encima con otra cita. Discúlpate y ofrece otro horario; usa consultar_disponibilidad para ver los libres. NO se registro nada.';
    }

    $dia = trim((string)($datos['dia'] ?? ''));
    if ($dia === '') $dia = $fecha;

    $st = conexion()->prepare("INSERT INTO citas (id_negocio, nombre, servicio, fecha, dia_texto, hora, duracion, contacto, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    $st->execute([$idNegocio, $nombre, $servicio, $fecha, $dia, $horaTxt, $dur, $contacto ?? 'desconocido']);
    $folio = (int)conexion()->lastInsertId();

    // Avisar al dueño por WhatsApp (si tiene número de avisos configurado).
    avisar_cita_agendada($c, ['nombre' => $nombre, 'servicio' => $servicio, 'dia' => $dia, 'hora' => $horaTxt]);

    return 'Cita registrada con folio #' . $folio . ' (' . $dur . ' min). Confirma al cliente que una persona del negocio se la confirmara por este medio.';
}

function consultar_cita(array $datos, ?string $contacto, int $idNegocio): string {
    $buscado = normalizar_nombre((string)($datos['nombre'] ?? ''));
    if (count(preg_split('/\s+/', $buscado, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
        return 'El cliente dio solo un nombre. Pidele su nombre completo (nombre y al menos un apellido) para verificar su identidad antes de darle cualquier informacion de su cita.';
    }

    $st = conexion()->prepare("SELECT id, servicio, dia_texto, hora, estado, nombre FROM citas WHERE id_negocio = ? AND estado <> 'cancelada'");
    $st->execute([$idNegocio]);

    $encontradas = [];
    foreach ($st as $c) {
        $almacenado = normalizar_nombre((string)($c['nombre'] ?? ''));
        if ($almacenado === $buscado || strpos($almacenado, $buscado) !== false || strpos($buscado, $almacenado) !== false) {
            $encontradas[] = $c;
        }
    }

    if (!$encontradas) {
        return 'No se encontro ninguna cita a nombre de "' . (string)($datos['nombre'] ?? '') . '". NO inventes datos. Pide al cliente que verifique el nombre completo con el que agendo, o que se comunique directamente al negocio.';
    }

    $texto = 'Identidad verificada. Citas encontradas (relatalas al cliente de forma natural y cordial):';
    foreach ($encontradas as $c) {
        $texto .= "\n- Folio #{$c['id']}: {$c['servicio']} el {$c['dia_texto']} a las {$c['hora']} (estado: {$c['estado']})";
    }
    return $texto;
}

function consultar_disponibilidad(array $datos, ?string $contacto, int $idNegocio): string {
    $fecha = trim((string)($datos['fecha'] ?? ''));
    $dia   = nombre_dia($fecha);
    if ($dia === null) {
        return 'Fecha no valida. Pide al cliente el dia concreto.';
    }

    $c   = cargar_conocimiento($idNegocio);
    $hor = horario_del_dia($fecha, $c);
    if ($hor === null) {
        return 'El negocio NO abre el ' . $dia . ' (' . $fecha . '). Ofrece al cliente otro dia.';
    }
    [$abre, $cierra] = $hor;

    $intervalo = (int)($c['intervalo_minutos'] ?? 30);
    if ($intervalo < 5) $intervalo = 30;
    $servicio = trim((string)($datos['servicio'] ?? ''));
    $dur      = $servicio !== '' ? duracion_servicio($servicio, $c) : $intervalo;

    $rangos   = rangos_ocupados($fecha, $c, $idNegocio);
    $hoy      = date('Y-m-d');
    $ahoraMin = ((int)date('G')) * 60 + (int)date('i');

    $libres = [];
    for ($m = $abre; $m + $dur <= $cierra; $m += $intervalo) {
        if ($fecha === $hoy && $m <= $ahoraMin) continue;
        if (se_traslapa($m, $m + $dur, $rangos)) continue;
        $libres[] = min_a_hhmm($m);
    }

    if (!$libres) {
        return 'El ' . $dia . ' ' . $fecha . ' no hay horarios libres' . ($servicio !== '' ? ' para ' . $servicio : '') . '. Ofrece al cliente otro dia.';
    }
    return 'Horarios LIBRES el ' . $dia . ' ' . $fecha . ($servicio !== '' ? ' para ' . $servicio . ' (' . $dur . ' min)' : '') . ': ' . implode(', ', $libres) . '. Ofrece estos horarios al cliente.';
}
