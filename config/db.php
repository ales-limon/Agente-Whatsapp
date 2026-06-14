<?php
// Conexion PDO a MySQL. Lee credenciales del .env. BD propia del agente
// (separada de las del consultorio).

require_once __DIR__ . '/../src/entorno.php';

function conexion(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    cargar_entorno();

    $host   = env('DB_HOST', '127.0.0.1');
    $puerto = env('DB_PORT', '3306');
    $nombre = env('DB_NAME', 'agente_whatsapp');
    $user   = env('DB_USER', 'root');
    $pass   = env('DB_PASS', '');

    $dsn = "mysql:host=$host;port=$puerto;dbname=$nombre;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
