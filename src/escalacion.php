<?php
// Estado de "atención humana" por conversación. Si hay fila para (negocio, contacto),
// el bot NO responde a ese cliente: lo atiende una persona, hasta reactivar en el panel.

require_once __DIR__ . '/../config/db.php';

function handoff_activo(int $idNegocio, string $contacto): bool {
    $st = conexion()->prepare("SELECT 1 FROM atencion_humana WHERE id_negocio = ? AND contacto = ? LIMIT 1");
    $st->execute([$idNegocio, $contacto]);
    return (bool)$st->fetchColumn();
}

function activar_handoff(int $idNegocio, string $contacto, string $motivo = ''): void {
    $motivo = mb_substr(trim($motivo), 0, 255);
    $st = conexion()->prepare(
        "INSERT INTO atencion_humana (id_negocio, contacto, motivo) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE motivo = ?"
    );
    $st->execute([$idNegocio, $contacto, $motivo, $motivo]);
}

function desactivar_handoff(int $idNegocio, string $contacto): void {
    $st = conexion()->prepare("DELETE FROM atencion_humana WHERE id_negocio = ? AND contacto = ?");
    $st->execute([$idNegocio, $contacto]);
}

// Mapa contacto => fila, de los chats actualmente en atención humana de un negocio.
function contactos_escalados(int $idNegocio): array {
    $st = conexion()->prepare("SELECT contacto, motivo, creado_en FROM atencion_humana WHERE id_negocio = ?");
    $st->execute([$idNegocio]);
    $m = [];
    foreach ($st as $r) $m[$r['contacto']] = $r;
    return $m;
}
