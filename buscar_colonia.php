<?php
// Endpoint de autocompletado del buscador de colonias (catálogo SEPOMEX).
// GET ?q=provi -> JSON [{cp, colonia, municipio}, ...]. Solo usuarios autenticados.

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/domicilio.php';
cargar_entorno();
aplicar_headers_seguridad();
requiere_autenticacion();

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
echo json_encode(buscar_colonias((string)($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
exit;
