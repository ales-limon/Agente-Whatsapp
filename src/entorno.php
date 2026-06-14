<?php
// Carga de variables desde .env (parser minimal propio, mismo enfoque que el consultorio).
// Soporta # y ; como comentarios solo a inicio de linea.

function leer_env(string $ruta): array {
    $vars = [];
    if (!is_file($ruta)) return $vars;
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || $linea[0] === ';') continue;
        $pos = strpos($linea, '=');
        if ($pos === false) continue;
        $clave = trim(substr($linea, 0, $pos));
        $valor = trim(substr($linea, $pos + 1));
        // Quitar comillas envolventes si las trae
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        $vars[$clave] = $valor;
    }
    return $vars;
}

function cargar_entorno(): void {
    static $cargado = false;
    if ($cargado) return;
    foreach (leer_env(__DIR__ . '/../.env') as $k => $v) {
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
    $cargado = true;

    // Zona horaria: sin esto el servidor calcula la fecha en su propia zona
    // (ej. Europe/Berlin en Laragon) y "hoy" sale con el dia equivocado.
    $zona = $_ENV['ZONA_HORARIA'] ?? 'America/Mexico_City';
    date_default_timezone_set($zona !== '' ? $zona : 'America/Mexico_City');
}

function env(string $clave, $default = null) {
    cargar_entorno();
    $v = $_ENV[$clave] ?? getenv($clave);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}
