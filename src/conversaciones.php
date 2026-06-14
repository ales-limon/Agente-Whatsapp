<?php
// Historial de conversacion por negocio + contacto, en la tabla mensajes.

require_once __DIR__ . '/../config/db.php';

function cargar_historial(int $idNegocio, string $contacto, int $max = 20): array {
    $st = conexion()->prepare("SELECT rol AS role, contenido AS content FROM mensajes WHERE id_negocio = ? AND contacto = ? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $idNegocio, PDO::PARAM_INT);
    $st->bindValue(2, $contacto);
    $st->bindValue(3, $max, PDO::PARAM_INT);
    $st->execute();
    return array_reverse($st->fetchAll());
}

function guardar_mensaje(int $idNegocio, string $contacto, string $rol, string $texto): void {
    $st = conexion()->prepare("INSERT INTO mensajes (id_negocio, contacto, rol, contenido) VALUES (?, ?, ?, ?)");
    $st->execute([$idNegocio, $contacto, $rol, $texto]);
}
