<?php
// Herramientas que la IA puede usar (tool-use de Claude): registrar, consultar y
// disponibilidad. Todo filtra por id_negocio y respeta la duracion de cada servicio.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/conocimiento.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/escalacion.php';

function herramientas_disponibles(): array {
    return [
        [
            'name'        => 'registrar_cita',
            'description' => 'Registra una cita NUEVA. Usala SOLO cuando tengas confirmados: nombre completo del cliente (nombre y al menos un apellido), servicio, dia y hora. No anuncies al cliente que la cita quedo registrada hasta haber llamado a esta herramienta y recibido la confirmacion.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nombre'      => ['type' => 'string', 'description' => 'Nombre completo del cliente (nombre y al menos un apellido)'],
                    'servicio'    => ['type' => 'string', 'description' => 'Servicio solicitado (tal cual aparece en la lista de servicios)'],
                    'fecha'       => ['type' => 'string', 'description' => 'Fecha de la cita en formato YYYY-MM-DD'],
                    'hora'        => ['type' => 'string', 'description' => 'Hora de inicio en formato de 24 horas HH:MM'],
                    'dia'         => ['type' => 'string', 'description' => 'Dia de la cita tal como lo dijo el cliente, para mostrar (ej: martes 16 de junio)'],
                    'profesional' => ['type' => 'string', 'description' => 'OPCIONAL. Nombre de la persona que atendera (tal cual aparece en la lista de personal). Solo si el negocio tiene personal y el cliente prefiere a alguien. Si lo dejas vacio, el sistema asigna a quien este libre.'],
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
                    'fecha'       => ['type' => 'string', 'description' => 'Fecha a consultar en formato YYYY-MM-DD'],
                    'servicio'    => ['type' => 'string', 'description' => 'Servicio que quiere el cliente (opcional, mejora el calculo segun su duracion)'],
                    'profesional' => ['type' => 'string', 'description' => 'OPCIONAL. Si el cliente quiere ver la disponibilidad de una persona en especifico, su nombre (tal cual aparece en la lista de personal).'],
                ],
                'required' => ['fecha'],
            ],
        ],
        [
            'name'        => 'escalar_a_humano',
            'description' => 'Pasa la conversacion a una persona del negocio. Usala cuando el cliente pida explicitamente hablar con una persona/humano, o cuando no puedas resolver lo que necesita con la informacion y herramientas disponibles. Al llamarla, el negocio recibe un aviso y tu DEJAS de atender a este cliente hasta que una persona lo retome. Despues de llamarla, avisa al cliente con calidez que en breve lo contactara una persona del negocio.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'motivo' => ['type' => 'string', 'description' => 'Breve resumen de lo que necesita el cliente, para dar contexto a la persona del negocio'],
                ],
                'required' => [],
            ],
        ],
    ];
}

function ejecutar_herramienta(string $nombre, array $input, ?string $contacto, int $idNegocio): string {
    switch ($nombre) {
        case 'registrar_cita':           return registrar_cita($input, $contacto, $idNegocio);
        case 'consultar_cita':           return consultar_cita($input, $contacto, $idNegocio);
        case 'consultar_disponibilidad': return consultar_disponibilidad($input, $contacto, $idNegocio);
        case 'escalar_a_humano':         return escalar_a_humano($input, $contacto, $idNegocio);
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

// Rangos [inicio, fin, profesional] (minutos) ocupados por citas no canceladas
// ese dia y negocio. El 3er elemento es el nombre de quien atiende ('' si ninguno).
function rangos_ocupados(string $fecha, array $c, int $idNegocio): array {
    $st = conexion()->prepare("SELECT servicio, hora, duracion, profesional FROM citas WHERE id_negocio = ? AND fecha = ? AND estado <> 'cancelada'");
    $st->execute([$idNegocio, $fecha]);
    $rangos = [];
    foreach ($st as $cita) {
        $ini = hhmm_a_min(normalizar_hora((string)($cita['hora'] ?? '')));
        $dur = (int)($cita['duracion'] ?? 0);
        if ($dur <= 0) $dur = duracion_servicio((string)($cita['servicio'] ?? ''), $c);
        $rangos[] = [$ini, $ini + $dur, (string)($cita['profesional'] ?? '')];
    }
    return $rangos;
}

// Filtra los rangos a los de una persona concreta. Si $prof es null, devuelve todos
// (modo un-solo-lugar). Devuelve pares [ini, fin] listos para se_traslapa().
function rangos_de_profesional(array $rangos, ?string $prof): array {
    if ($prof === null) return array_map(fn($r) => [$r[0], $r[1]], $rangos);
    $p = normalizar_nombre($prof);
    $out = [];
    foreach ($rangos as $r) {
        if (normalizar_nombre((string)($r[2] ?? '')) === $p) $out[] = [$r[0], $r[1]];
    }
    return $out;
}

// Resuelve el nombre que dio el cliente contra la lista oficial de personal.
// Devuelve el nombre canonico (tal cual esta en la config) o null si no coincide.
function resolver_profesional(string $entrada, array $recursos): ?string {
    $b = normalizar_nombre($entrada);
    if ($b === '') return null;
    foreach ($recursos as $r) {
        if (normalizar_nombre((string)$r) === $b) return $r;
    }
    foreach ($recursos as $r) {
        $n = normalizar_nombre((string)$r);
        if ($n !== '' && (strpos($n, $b) !== false || strpos($b, $n) !== false)) return $r;
    }
    return null;
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

    $recursos     = $c['recursos'] ?? [];
    $rangos       = rangos_ocupados($fecha, $c, $idNegocio);
    $profAsignado = null; // null = el negocio no maneja personal (un solo lugar)

    if ($recursos) {
        $profIn = trim((string)($datos['profesional'] ?? ''));
        if ($profIn !== '') {
            // El cliente pidio a alguien en especifico
            $prof = resolver_profesional($profIn, $recursos);
            if ($prof === null) {
                return 'NO REGISTRADA: "' . $profIn . '" no esta en el personal del negocio. El personal es: ' . implode(', ', $recursos) . '. Confirma con el cliente con quien quiere, o deja que el sistema asigne a quien este libre.';
            }
            if (se_traslapa($ini, $fin, rangos_de_profesional($rangos, $prof))) {
                return 'OCUPADO: ' . $prof . ' ya tiene una cita a esa hora. Ofrece al cliente otra hora con ' . $prof . ', u otra persona del personal. Usa consultar_disponibilidad. NO se registro nada.';
            }
            $profAsignado = $prof;
        } else {
            // Sin preferencia: asignar a la primera persona libre a esa hora
            foreach ($recursos as $r) {
                if (!se_traslapa($ini, $fin, rangos_de_profesional($rangos, $r))) { $profAsignado = $r; break; }
            }
            if ($profAsignado === null) {
                return 'HORARIO OCUPADO: a esa hora todo el personal esta ocupado. Discúlpate y ofrece otro horario; usa consultar_disponibilidad. NO se registro nada.';
            }
        }
    } else {
        // Un solo lugar (comportamiento clasico)
        if (se_traslapa($ini, $fin, rangos_de_profesional($rangos, null))) {
            return 'HORARIO OCUPADO: ese horario se encima con otra cita. Discúlpate y ofrece otro horario; usa consultar_disponibilidad para ver los libres. NO se registro nada.';
        }
    }

    $dia = trim((string)($datos['dia'] ?? ''));
    if ($dia === '') $dia = $fecha;

    $st = conexion()->prepare("INSERT INTO citas (id_negocio, nombre, servicio, profesional, fecha, dia_texto, hora, duracion, contacto, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    $st->execute([$idNegocio, $nombre, $servicio, $profAsignado, $fecha, $dia, $horaTxt, $dur, $contacto ?? 'desconocido']);
    $folio = (int)conexion()->lastInsertId();

    // Avisar al dueño por WhatsApp (si tiene número de avisos configurado).
    avisar_cita_agendada($c, ['nombre' => $nombre, 'servicio' => $servicio, 'dia' => $dia, 'hora' => $horaTxt, 'profesional' => $profAsignado]);

    $conQuien = $profAsignado !== null ? ' con ' . $profAsignado : '';
    return 'Cita registrada con folio #' . $folio . ' (' . $dur . ' min)' . $conQuien . '. Confirma al cliente que una persona del negocio se la confirmara por este medio'
         . ($profAsignado !== null ? ', y mencionale que su cita es con ' . $profAsignado : '') . '.';
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

    // ¿Contra que agenda(s) medimos? Una persona concreta, cualquiera del personal,
    // o el negocio como un solo lugar si no hay personal cargado.
    $recursos = $c['recursos'] ?? [];
    $profIn   = trim((string)($datos['profesional'] ?? ''));
    $etqProf  = '';
    if ($recursos) {
        if ($profIn !== '') {
            $prof = resolver_profesional($profIn, $recursos);
            if ($prof === null) {
                return '"' . $profIn . '" no esta en el personal. El personal es: ' . implode(', ', $recursos) . '. Pregunta al cliente con quien quiere, o consulta sin especificar persona.';
            }
            $agendas = [$prof];
            $etqProf = ' con ' . $prof;
        } else {
            $agendas = $recursos; // libre si CUALQUIERA esta disponible
        }
    } else {
        $agendas = [null]; // un solo lugar
    }

    $libres = [];
    for ($m = $abre; $m + $dur <= $cierra; $m += $intervalo) {
        if ($fecha === $hoy && $m <= $ahoraMin) continue;
        $hayAlguien = false;
        foreach ($agendas as $p) {
            if (!se_traslapa($m, $m + $dur, rangos_de_profesional($rangos, $p))) { $hayAlguien = true; break; }
        }
        if ($hayAlguien) $libres[] = min_a_hhmm($m);
    }

    if (!$libres) {
        return 'El ' . $dia . ' ' . $fecha . ' no hay horarios libres' . $etqProf . ($servicio !== '' ? ' para ' . $servicio : '') . '. Ofrece al cliente otro dia' . ($etqProf !== '' ? ' u otra persona del personal' : '') . '.';
    }
    return 'Horarios LIBRES el ' . $dia . ' ' . $fecha . $etqProf . ($servicio !== '' ? ' para ' . $servicio . ' (' . $dur . ' min)' : '') . ': ' . implode(', ', $libres) . '. Ofrece estos horarios al cliente.';
}

function escalar_a_humano(array $datos, ?string $contacto, int $idNegocio): string {
    $motivo  = trim((string)($datos['motivo'] ?? ''));
    $persona = (string)($contacto ?? 'desconocido');
    $c       = cargar_conocimiento($idNegocio);

    activar_handoff($idNegocio, $persona, $motivo);   // pausa el bot para este chat
    avisar_escalacion($c, $persona, $motivo);          // avisa al dueño por WhatsApp

    return 'Escalado a una persona del negocio (el bot quedo en pausa para este chat). '
         . 'Avisa al cliente con calidez que en breve lo contactara una persona del negocio. '
         . 'No sigas ofreciendo ayudarlo tu automaticamente.';
}
