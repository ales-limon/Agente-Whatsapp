<?php
// Prueba local del agente desde la terminal (sin Twilio). Usa el primer negocio
// o el que indiques por slug.
//
// Uso:
//   php probar.php "Hola, cuanto cuesta un corte?"
//   php probar.php "Que horarios tienes el sabado?" barbershop-el-bigote-de-oro

require_once __DIR__ . '/src/entorno.php';
require_once __DIR__ . '/src/negocios.php';
require_once __DIR__ . '/src/conocimiento.php';
require_once __DIR__ . '/src/claude.php';

cargar_entorno();

if (!env('ANTHROPIC_API_KEY')) {
    fwrite(STDERR, "ERROR: falta ANTHROPIC_API_KEY en el archivo .env\n");
    exit(1);
}

$slug    = $argv[2] ?? '';
$negocio = $slug !== '' ? negocio_por_slug($slug) : primer_negocio();
if (!$negocio) {
    fwrite(STDERR, "No hay negocios. Corre 'php instalar.php' o crea uno en superadmin.php\n");
    exit(1);
}
$idNegocio = (int)$negocio['id'];

$mensaje      = $argv[1] ?? 'Hola, que servicios tienen y cuanto cuestan?';
$c            = cargar_conocimiento($idNegocio);
$systemPrompt = construir_system_prompt($c);

echo "Negocio:    {$negocio['nombre']}\n";
echo "Cliente:    $mensaje\n\n";
$respuesta = responder_con_claude($systemPrompt, [], $mensaje, 'CLI', $idNegocio);
echo "Asistente:  $respuesta\n";
