<?php
// Instalador idempotente: crea la BD, las tablas y (la primera vez) migra el
// negocio que estaba en storage/conocimiento.json + sus citas a la BD.
// Ejecutar una vez:  php instalar.php   (o abrir en el navegador)

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/config/db.php';
cargar_entorno();

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '3306');
$name = env('DB_NAME', 'agente_whatsapp');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');

// 1. Crear la base de datos (best-effort: en hosting compartido el usuario de BD
//    suele NO tener permiso de CREATE DATABASE; ahi la BD se crea desde el panel).
try {
    $root = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "BD '$name' lista.\n";
} catch (Throwable $e) {
    echo "Nota: no se creo la BD automaticamente (ya existe o sin permiso CREATE DATABASE). Continuo asumiendo que '$name' existe.\n";
}

// 2. Crear las tablas
$pdo    = conexion();
$schema = file_get_contents(__DIR__ . '/database/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
    $pdo->exec($stmt);
}
echo "Tablas creadas.\n";

// Asegurar columnas nuevas en instalaciones que ya existian (CREATE IF NOT EXISTS no agrega columnas)
$colExiste = function (string $tabla, string $col) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$tabla, $col]);
    return (int)$st->fetchColumn() > 0;
};
if (!$colExiste('negocios', 'numero_avisos')) {
    $pdo->exec("ALTER TABLE negocios ADD COLUMN numero_avisos VARCHAR(40) NULL AFTER numero_whatsapp");
    echo "Columna negocios.numero_avisos agregada.\n";
}
if (!$colExiste('negocios', 'limite_mensajes_mes')) {
    $pdo->exec("ALTER TABLE negocios ADD COLUMN limite_mensajes_mes INT NOT NULL DEFAULT 0 AFTER numero_avisos");
    echo "Columna negocios.limite_mensajes_mes agregada.\n";
}
if (!$colExiste('citas', 'profesional')) {
    $pdo->exec("ALTER TABLE citas ADD COLUMN profesional VARCHAR(120) NULL AFTER servicio");
    echo "Columna citas.profesional agregada.\n";
}
if (!$colExiste('citas', 'recordado_en')) {
    $pdo->exec("ALTER TABLE citas ADD COLUMN recordado_en DATETIME NULL AFTER estado");
    echo "Columna citas.recordado_en agregada.\n";
}
if (!$colExiste('negocios', 'recordatorio_horas_antes')) {
    $pdo->exec("ALTER TABLE negocios ADD COLUMN recordatorio_horas_antes INT NOT NULL DEFAULT 0 AFTER limite_mensajes_mes");
    echo "Columna negocios.recordatorio_horas_antes agregada.\n";
}

// Backfill del enlace usuario-negocio desde la columna usuarios.id_negocio (idempotente).
$pdo->exec("INSERT IGNORE INTO usuario_negocio (id_usuario, id_negocio)
            SELECT id, id_negocio FROM usuarios WHERE id_negocio IS NOT NULL");
echo "Enlaces usuario-negocio respaldados.\n";

// 3. Migrar el negocio del JSON la primera vez
$hay = (int)$pdo->query("SELECT COUNT(*) FROM negocios")->fetchColumn();
if ($hay > 0) {
    echo "Ya existen $hay negocio(s); no se migra nada.\n";
    echo "Listo.\n";
    exit;
}

$rutaC = __DIR__ . '/storage/conocimiento.json';
if (!is_file($rutaC)) {
    echo "No hay conocimiento.json para migrar. Crea negocios desde superadmin.php.\n";
    exit;
}

$j      = json_decode(file_get_contents($rutaC), true) ?: [];
$nombre = $j['negocio'] ?? 'Mi negocio';
$id     = crear_negocio($nombre);

$datos = [
    'negocio'             => $nombre,
    'descripcion'         => $j['descripcion'] ?? '',
    'ubicacion'           => $j['ubicacion'] ?? '',
    'telefono'            => $j['telefono'] ?? '',
    'numero_whatsapp'     => '',
    'politicas'           => $j['politicas'] ?? '',
    'instrucciones_extra' => $j['instrucciones_extra'] ?? '',
    'intervalo_minutos'   => (int)($j['intervalo_minutos'] ?? 30),
    'horario_estructurado' => $j['horario_estructurado'] ?? [],
    'servicios'           => array_map(
        fn($s) => ['nombre' => $s['nombre'] ?? '', 'precio' => $s['precio'] ?? '0', 'duracion' => (int)($s['duracion'] ?? 30)],
        $j['servicios'] ?? []
    ),
];
guardar_configuracion($id, $datos);
$slug = negocio_por_id($id)['slug'];
echo "Negocio migrado: $nombre (id $id, slug '$slug').\n";

// Migrar citas.json
$rutaCitas = __DIR__ . '/storage/citas.json';
if (is_file($rutaCitas)) {
    $citas = json_decode(file_get_contents($rutaCitas), true) ?: [];
    $st = $pdo->prepare("INSERT INTO citas (id_negocio, nombre, servicio, fecha, dia_texto, hora, duracion, contacto, estado, creado_en) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $migradas = 0;
    foreach ($citas as $ci) {
        $st->execute([
            $id,
            $ci['nombre'] ?? '',
            $ci['servicio'] ?? '',
            !empty($ci['fecha']) ? $ci['fecha'] : null,
            $ci['dia'] ?? '',
            $ci['hora'] ?? '',
            (int)($ci['duracion'] ?? 30),
            $ci['contacto'] ?? '',
            $ci['estado'] ?? 'pendiente',
            !empty($ci['creado_en']) ? date('Y-m-d H:i:s', strtotime($ci['creado_en'])) : date('Y-m-d H:i:s'),
        ]);
        $migradas++;
    }
    echo "Citas migradas: $migradas.\n";
}

echo "Listo. Abre superadmin.php para ver tus negocios.\n";
