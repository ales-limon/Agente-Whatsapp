<?php
// Cron: envia recordatorios de cita a los CLIENTES, X horas antes de su cita
// (configurable por negocio en recordatorio_horas_antes; 0 = desactivado).
// Solo a clientes que agendaron por WhatsApp (tienen numero de telefono); los del
// chat web son anonimos y no se les puede recordar. Marca recordado_en para no repetir.
//
// Ejecutar por cron en el VPS cada hora, ej:
//   0 * * * * cd /ruta/al/proyecto && php recordatorios.php >> storage/recordatorios.log 2>&1
//
// Solo CLI (no accesible por web).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/notificaciones.php';
cargar_entorno();

$pdo   = conexion();
$ahora = time();

$negocios = $pdo->query("SELECT id FROM negocios WHERE activo = 1 AND recordatorio_horas_antes > 0")->fetchAll();
$enviados = 0;
$revisados = 0;

foreach ($negocios as $n) {
    $idNegocio = (int)$n['id'];
    $c         = cargar_conocimiento($idNegocio);
    $horas     = (int)($c['recordatorio_horas_antes'] ?? 0);
    if ($horas <= 0) continue;
    $limite = $ahora + $horas * 3600;

    // Citas activas, aun no recordadas, de hoy en adelante.
    $st = $pdo->prepare(
        "SELECT * FROM citas
         WHERE id_negocio = ? AND recordado_en IS NULL
           AND estado IN ('pendiente','confirmada') AND fecha >= CURDATE()"
    );
    $st->execute([$idNegocio]);

    foreach ($st as $cita) {
        $revisados++;
        $contacto = trim((string)($cita['contacto'] ?? ''));

        // Solo clientes con numero de WhatsApp real (no chat web anonimo).
        if ($contacto === '' || stripos($contacto, 'web:') === 0) continue;
        $soloDigitos = preg_replace('/\D/', '', $contacto);
        if (strlen($soloDigitos) < 8) continue;

        // Momento exacto de la cita (fecha + hora). Toleramos "12:30", "12:30 hrs", etc.
        $hora = preg_replace('/[^0-9:]/', '', (string)($cita['hora'] ?? ''));
        $ts   = strtotime(((string)($cita['fecha'] ?? '')) . ' ' . $hora);
        if ($ts === false) continue;

        // Enviar solo si la cita esta dentro de la ventana [ahora, ahora + horas].
        if ($ts <= $ahora || $ts > $limite) continue;

        if (avisar_recordatorio_cliente($c, $cita)) {
            $pdo->prepare("UPDATE citas SET recordado_en = NOW() WHERE id = ?")->execute([(int)$cita['id']]);
            $enviados++;
        }
    }
}

echo date('Y-m-d H:i:s') . " | negocios: " . count($negocios) . " | citas revisadas: $revisados | recordatorios enviados: $enviados\n";
