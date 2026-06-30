<?php
// Capa de negocios (tenants). Resolucion, listado y alta. Todo el aislamiento
// multitenant se ancla en id_negocio.

require_once __DIR__ . '/../config/db.php';

function slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function normalizar_numero(string $n): string {
    return trim(str_replace(['whatsapp:', ' '], '', $n));
}

function negocio_por_slug(string $slug): ?array {
    $st = conexion()->prepare("SELECT * FROM negocios WHERE slug = ? AND activo = 1");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function negocio_por_id(int $id): ?array {
    $st = conexion()->prepare("SELECT * FROM negocios WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function negocio_por_numero(string $numero): ?array {
    $st = conexion()->prepare("SELECT * FROM negocios WHERE numero_whatsapp = ? AND activo = 1");
    $st->execute([normalizar_numero($numero)]);
    return $st->fetch() ?: null;
}

function listar_negocios(): array {
    return conexion()->query("SELECT * FROM negocios ORDER BY nombre")->fetchAll();
}

function primer_negocio(): ?array {
    $r = conexion()->query("SELECT * FROM negocios WHERE activo = 1 ORDER BY id LIMIT 1")->fetch();
    return $r ?: null;
}

// Crea un negocio con horario por defecto (lun-vie 10-19, sab 10-14, dom cerrado).
function crear_negocio(string $nombre, string $slug = ''): int {
    $pdo  = conexion();
    $slug = $slug !== '' ? slugify($slug) : slugify($nombre);
    if ($slug === '') $slug = 'negocio';

    // asegurar slug unico
    $base = $slug; $i = 2;
    while (negocio_por_slug($slug)) { $slug = $base . '-' . $i; $i++; }

    $st = $pdo->prepare("INSERT INTO negocios (slug, nombre, intervalo_minutos) VALUES (?, ?, 30)");
    $st->execute([$slug, $nombre]);
    $id = (int)$pdo->lastInsertId();

    $defaults = [
        'lunes' => ['10:00', '19:00'], 'martes' => ['10:00', '19:00'], 'miercoles' => ['10:00', '19:00'],
        'jueves' => ['10:00', '19:00'], 'viernes' => ['10:00', '19:00'], 'sabado' => ['10:00', '14:00'],
        'domingo' => [null, null],
    ];
    $sh = $pdo->prepare("INSERT INTO horarios (id_negocio, dia, abre, cierra) VALUES (?, ?, ?, ?)");
    foreach ($defaults as $dia => [$abre, $cierra]) {
        $sh->execute([$id, $dia, $abre, $cierra]);
    }
    return $id;
}

// Guarda la configuracion completa de un negocio (datos, horario y servicios).
function guardar_configuracion(int $id, array $datos): void {
    $pdo = conexion();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE negocios SET nombre=?, descripcion=?, ubicacion=?, telefono=?, politicas=?, instrucciones_extra=?, intervalo_minutos=?, numero_avisos=? WHERE id=?");
        $st->execute([
            $datos['negocio'], $datos['descripcion'], $datos['ubicacion'], $datos['telefono'],
            $datos['politicas'], $datos['instrucciones_extra'], $datos['intervalo_minutos'],
            ($datos['numero_avisos'] ?? '') !== '' ? normalizar_numero($datos['numero_avisos']) : null,
            $id,
        ]);

        $pdo->prepare("DELETE FROM horarios WHERE id_negocio = ?")->execute([$id]);
        $sh = $pdo->prepare("INSERT INTO horarios (id_negocio, dia, abre, cierra) VALUES (?, ?, ?, ?)");
        foreach ($datos['horario_estructurado'] as $dia => $h) {
            if (is_array($h) && !empty($h['abre']) && !empty($h['cierra'])) {
                $sh->execute([$id, $dia, $h['abre'], $h['cierra']]);
            } else {
                $sh->execute([$id, $dia, null, null]);
            }
        }

        $pdo->prepare("DELETE FROM servicios WHERE id_negocio = ?")->execute([$id]);
        $ss = $pdo->prepare("INSERT INTO servicios (id_negocio, nombre, precio, duracion, orden) VALUES (?, ?, ?, ?, ?)");
        foreach ($datos['servicios'] as $orden => $s) {
            $ss->execute([$id, $s['nombre'], (float)$s['precio'], (int)$s['duracion'], $orden]);
        }

        // Personal que atiende. Se guarda como snapshot de nombres (la cita guarda
        // el nombre como texto, no por FK), por eso podemos borrar y reinsertar.
        $pdo->prepare("DELETE FROM recursos WHERE id_negocio = ?")->execute([$id]);
        $sr = $pdo->prepare("INSERT INTO recursos (id_negocio, nombre, orden) VALUES (?, ?, ?)");
        foreach (($datos['recursos'] ?? []) as $orden => $nombre) {
            $nombre = trim((string)$nombre);
            if ($nombre !== '') $sr->execute([$id, $nombre, $orden]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Fija el límite de mensajes/mes de un negocio (0 = ilimitado). Lo gestiona el superadmin
// segun el plan contratado; es el tope que protege el margen.
function fijar_limite_mensajes(int $id, int $limite): void {
    $limite = max(0, $limite);
    conexion()->prepare("UPDATE negocios SET limite_mensajes_mes = ? WHERE id = ?")->execute([$limite, $id]);
}

// Cambia la dirección (slug) de un negocio. Valida que no choque con otro.
// OJO: cambia la URL del chat web; los QR/enlaces viejos dejan de servir.
function actualizar_slug(int $id, string $slugDeseado): array {
    $slug = slugify($slugDeseado);
    if ($slug === '') return ['exito' => false, 'mensaje' => 'La dirección del chat no puede quedar vacía.'];
    $st = conexion()->prepare("SELECT id FROM negocios WHERE slug = ? AND id <> ?");
    $st->execute([$slug, $id]);
    if ($st->fetch()) return ['exito' => false, 'mensaje' => 'Esa dirección ya está en uso por otro negocio. Elige otra.'];
    conexion()->prepare("UPDATE negocios SET slug = ? WHERE id = ?")->execute([$slug, $id]);
    return ['exito' => true, 'slug' => $slug];
}

// Asigna (o limpia) el numero de WhatsApp de un negocio. Lo gestiona el superadmin.
function asignar_numero(int $id, string $numero): array {
    $numero = trim($numero);
    $valor  = $numero !== '' ? normalizar_numero($numero) : null;

    if ($valor !== null) {
        $st = conexion()->prepare("SELECT id FROM negocios WHERE numero_whatsapp = ? AND id <> ?");
        $st->execute([$valor, $id]);
        if ($st->fetch()) return ['exito' => false, 'mensaje' => 'Ese número ya está asignado a otro negocio.'];
    }
    try {
        conexion()->prepare("UPDATE negocios SET numero_whatsapp = ? WHERE id = ?")->execute([$valor, $id]);
        return ['exito' => true];
    } catch (Throwable $e) {
        error_log('asignar_numero: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo asignar el número.'];
    }
}
