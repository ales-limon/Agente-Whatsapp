<?php
// Modo A DOMICILIO: zonas de atención (con sus días) y directorio de clientes
// conocidos. El agente identifica al cliente por su número de WhatsApp, obtiene su
// zona y sólo ofrece los días en que el negocio atiende esa zona.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/negocios.php'; // normalizar_numero, mismo_numero

// Días de la semana (clave interna => etiqueta), en orden.
function dias_semana(): array {
    return [
        'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
        'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
    ];
}

// ---------- Zonas ----------

function listar_zonas(int $idNegocio): array {
    $st = conexion()->prepare("SELECT id, nombre, dias, colonias FROM zonas WHERE id_negocio = ? ORDER BY orden, id");
    $st->execute([$idNegocio]);
    $out = [];
    foreach ($st as $z) {
        $cols = json_decode((string)($z['colonias'] ?? ''), true);
        $out[] = [
            'id'       => (int)$z['id'],
            'nombre'   => (string)$z['nombre'],
            'dias'     => array_values(array_filter(array_map('trim', explode(',', (string)$z['dias'])))),
            'colonias' => is_array($cols) ? $cols : [],
        ];
    }
    return $out;
}

// Reemplaza todas las zonas del negocio. $zonas = [['nombre'=>..., 'dias'=>[...]], ...].
// Se guarda por nombre (el cliente referencia la zona por su nombre), así que borrar
// y reinsertar es seguro (no hay FKs por id apuntando a zonas).
function guardar_zonas(int $idNegocio, array $zonas): void {
    $pdo = conexion();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM zonas WHERE id_negocio = ?")->execute([$idNegocio]);
        $st = $pdo->prepare("INSERT INTO zonas (id_negocio, nombre, dias, colonias, orden) VALUES (?, ?, ?, ?, ?)");
        foreach ($zonas as $orden => $z) {
            $nombre = trim((string)($z['nombre'] ?? ''));
            if ($nombre === '') continue;
            $dias = is_array($z['dias'] ?? null) ? implode(',', $z['dias']) : (string)($z['dias'] ?? '');
            $cols = is_array($z['colonias'] ?? null) ? json_encode(array_values($z['colonias']), JSON_UNESCAPED_UNICODE) : null;
            $st->execute([$idNegocio, $nombre, $dias, $cols, $orden]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Días (claves) en que el negocio atiende una zona por su nombre. [] si no la halla.
function dias_de_zona(int $idNegocio, string $nombreZona): array {
    $buscado = mb_strtolower(trim($nombreZona), 'UTF-8');
    if ($buscado === '') return [];
    foreach (listar_zonas($idNegocio) as $z) {
        if (mb_strtolower($z['nombre'], 'UTF-8') === $buscado) return $z['dias'];
    }
    return [];
}

// Busca colonias del catálogo SEPOMEX por nombre o por CP. Devuelve [{cp,colonia,municipio}].
function buscar_colonias(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q) < 3) return [];
    $pdo = conexion();
    if (ctype_digit($q)) {
        $st = $pdo->prepare("SELECT cp, colonia, municipio FROM colonias WHERE cp LIKE ? ORDER BY colonia LIMIT ?");
        $st->bindValue(1, $q . '%');
    } else {
        $st = $pdo->prepare("SELECT cp, colonia, municipio FROM colonias WHERE colonia LIKE ? ORDER BY colonia LIMIT ?");
        $st->bindValue(1, '%' . $q . '%');
    }
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Dada una colonia (por su CP), ¿a qué zona del negocio pertenece? Devuelve la zona
// (nombre, dias, colonias) o null. Sirve para autodetectar la zona de un cliente.
function zona_de_cp(int $idNegocio, string $cp): ?array {
    $cp = trim($cp);
    if ($cp === '') return null;
    foreach (listar_zonas($idNegocio) as $z) {
        foreach ($z['colonias'] as $col) {
            if ((string)($col['cp'] ?? '') === $cp) return $z;
        }
    }
    return null;
}

// ---------- Directorio de clientes ----------

function listar_clientes(int $idNegocio): array {
    $st = conexion()->prepare("SELECT * FROM clientes WHERE id_negocio = ? ORDER BY nombre");
    $st->execute([$idNegocio]);
    return $st->fetchAll();
}

// Busca un cliente por su número de WhatsApp (compara por los últimos 10 dígitos,
// robusto al +52/+521). Devuelve la fila o null.
function buscar_cliente_por_numero(int $idNegocio, string $numero): ?array {
    if (trim($numero) === '') return null;
    $st = conexion()->prepare("SELECT * FROM clientes WHERE id_negocio = ?");
    $st->execute([$idNegocio]);
    foreach ($st as $c) {
        if (mismo_numero($numero, (string)$c['numero'])) return $c;
    }
    return null;
}

function crear_cliente(int $idNegocio, string $nombre, string $numero, string $zona, string $colonia, string $cp, string $direccion, string $notas = ''): array {
    $nombre = trim($nombre);
    $numero = normalizar_numero($numero);
    if ($nombre === '' || $numero === '') return ['exito' => false, 'mensaje' => 'Nombre y WhatsApp son obligatorios.'];

    if (buscar_cliente_por_numero($idNegocio, $numero)) {
        return ['exito' => false, 'mensaje' => 'Ya existe un cliente con ese número.'];
    }
    $st = conexion()->prepare(
        "INSERT INTO clientes (id_negocio, nombre, numero, zona, colonia, cp, direccion, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([
        $idNegocio, $nombre, $numero, trim($zona) ?: null,
        trim($colonia) ?: null, trim($cp) ?: null, trim($direccion) ?: null, trim($notas) ?: null,
    ]);
    $id = (int)conexion()->lastInsertId();
    // Ligar las citas que este número ya tenía (agendadas antes de darlo de alta).
    vincular_citas_cliente($id, $idNegocio, $numero);
    return ['exito' => true, 'id' => $id];
}

// Un cliente por su id (re-verificando negocio). null si no existe o no es del negocio.
function obtener_cliente(int $idCliente, int $idNegocio): ?array {
    $st = conexion()->prepare("SELECT * FROM clientes WHERE id = ? AND id_negocio = ?");
    $st->execute([$idCliente, $idNegocio]);
    return $st->fetch() ?: null;
}

function actualizar_cliente(int $idCliente, int $idNegocio, string $nombre, string $numero, string $zona, string $colonia, string $cp, string $direccion, string $notas = ''): array {
    $actual = obtener_cliente($idCliente, $idNegocio);
    if (!$actual) return ['exito' => false, 'mensaje' => 'Cliente no encontrado.'];

    $nombre = trim($nombre);
    $numero = normalizar_numero($numero);
    if ($nombre === '' || $numero === '') return ['exito' => false, 'mensaje' => 'Nombre y WhatsApp son obligatorios.'];

    // Si cambió el número, verificar que no choque con otro cliente.
    if (!mismo_numero($numero, (string)$actual['numero'])) {
        $otro = buscar_cliente_por_numero($idNegocio, $numero);
        if ($otro && (int)$otro['id'] !== $idCliente) {
            return ['exito' => false, 'mensaje' => 'Ya existe otro cliente con ese número.'];
        }
    }

    $st = conexion()->prepare(
        "UPDATE clientes SET nombre = ?, numero = ?, zona = ?, colonia = ?, cp = ?, direccion = ?, notas = ?
         WHERE id = ? AND id_negocio = ?"
    );
    $st->execute([
        $nombre, $numero, trim($zona) ?: null, trim($colonia) ?: null, trim($cp) ?: null,
        trim($direccion) ?: null, trim($notas) ?: null, $idCliente, $idNegocio,
    ]);

    // Si cambió el número, re-ligar sus citas: soltar las viejas y enganchar las del nuevo.
    if (!mismo_numero($numero, (string)$actual['numero'])) {
        conexion()->prepare("UPDATE citas SET id_cliente = NULL WHERE id_cliente = ? AND id_negocio = ?")
                   ->execute([$idCliente, $idNegocio]);
        vincular_citas_cliente($idCliente, $idNegocio, $numero);
    }
    return ['exito' => true];
}

function borrar_cliente(int $idCliente, int $idNegocio): void {
    conexion()->prepare("DELETE FROM clientes WHERE id = ? AND id_negocio = ?")->execute([$idCliente, $idNegocio]);
}

// Engancha a este cliente las citas del negocio que aún no tienen dueño y cuyo número
// coincide (últimos 10 dígitos). Devuelve cuántas ligó. Reusa mismo_numero para ser
// robusto a los formatos +52 / +521 / whatsapp:.
function vincular_citas_cliente(int $idCliente, int $idNegocio, string $numero): int {
    $numero = trim($numero);
    if ($idCliente <= 0 || $numero === '') return 0;
    $pdo = conexion();
    $st  = $pdo->prepare("SELECT id, contacto FROM citas WHERE id_negocio = ? AND id_cliente IS NULL");
    $st->execute([$idNegocio]);
    $ids = [];
    foreach ($st as $row) {
        if (mismo_numero($numero, (string)$row['contacto'])) $ids[] = (int)$row['id'];
    }
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE citas SET id_cliente = ? WHERE id_negocio = ? AND id IN ($in)")
        ->execute(array_merge([$idCliente, $idNegocio], $ids));
    return count($ids);
}

// Historial de citas de un cliente + resumen de lo cobrado. Devuelve:
//   ['citas' => [...], 'total' => float, 'num_cobradas' => int, 'ultima' => 'Y-m-d H:i:s'|null]
function pagos_de_cliente(int $idCliente, int $idNegocio): array {
    $st = conexion()->prepare(
        "SELECT id, servicio, profesional, fecha, dia_texto, hora, estado, pagado, metodo_pago, monto_cobrado, pagado_en
         FROM citas
         WHERE id_cliente = ? AND id_negocio = ?
         ORDER BY (fecha IS NULL), fecha DESC, hora DESC, id DESC"
    );
    $st->execute([$idCliente, $idNegocio]);
    $citas = $st->fetchAll();
    $total = 0.0; $num = 0; $ultima = null;
    foreach ($citas as $c) {
        if ((int)$c['pagado'] === 1) {
            $total += (float)$c['monto_cobrado'];
            $num++;
            $pe = (string)($c['pagado_en'] ?? '');
            if ($pe !== '' && ($ultima === null || $pe > $ultima)) $ultima = $pe;
        }
    }
    return ['citas' => $citas, 'total' => $total, 'num_cobradas' => $num, 'ultima' => $ultima];
}

// ---------- Contexto para el agente (modo a domicilio) ----------

// Bloque que se agrega al system prompt según QUIÉN escribe. Le dice al agente si el
// cliente está registrado, su zona y días, y las reglas de seguridad/privacidad.
// Devuelve '' si el negocio NO es a domicilio (no cambia nada).
function bloque_contexto_domicilio(int $idNegocio, array $c, string $contacto): string {
    if (empty($c['a_domicilio'])) return '';

    $esWeb = stripos($contacto, 'web:') === 0;
    $cli   = $esWeb ? null : buscar_cliente_por_numero($idNegocio, $contacto);

    $t = "\nATENCIÓN A DOMICILIO: este negocio va a la casa del cliente.\n";

    if ($cli) {
        $zona = trim((string)($cli['zona'] ?? ''));
        $t   .= 'El cliente que te escribe está REGISTRADO: ' . $cli['nombre'] . '.';
        if ($zona !== '') {
            $dias = dias_de_zona($idNegocio, $zona);
            $lbls = $dias ? implode(', ', array_map(fn($d) => ucfirst($d), $dias)) : '(sin días configurados)';
            $t   .= ' Zona "' . $zona . '", que se atiende: ' . $lbls . '.';
            $t   .= ' Si pregunta "cuándo vienes por mi zona", dile esos días y ofrécele la próxima fecha. Al agendar, ofrece SOLO esos días.';
        } else {
            $t .= ' (sin zona asignada; ofrece según el horario normal).';
        }
        if (trim((string)($cli['direccion'] ?? '')) !== '') {
            $t .= ' Su dirección ya está registrada, NO se la vuelvas a pedir.';
        }
        $t .= "\n";
    } else {
        $t .= 'Quien te escribe NO está registrado como cliente' . ($esWeb ? ' (además es el chat web, anónimo)' : '') . '. '
            . 'NO agendes una visita a domicilio a alguien desconocido (es por SEGURIDAD). Puedes responder dudas generales '
            . '(servicios, precios, zonas, horarios). Si quiere agendar, pídele nombre, WhatsApp, colonia y dirección, y usa '
            . "escalar_a_humano para que el negocio lo registre y apruebe.\n";
    }
    $t .= "NUNCA reveles la dirección, el nombre ni datos de ningún otro cliente.\n";
    return $t;
}
