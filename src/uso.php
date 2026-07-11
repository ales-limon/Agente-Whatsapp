<?php
// Medidor de uso por negocio: cuenta los mensajes atendidos por la IA y los
// tokens consumidos cada mes, y aplica el límite mensual del plan. Cada mensaje
// que responde la IA cuesta (tokens de Claude + tarifa de Twilio), así que esto
// es lo que protege el margen: un negocio no puede dispararnos el costo sin tope.

require_once __DIR__ . '/../config/db.php';

// Periodo de facturación actual en formato 'YYYY-MM' (mes natural del servidor).
function periodo_actual(): string {
    return date('Y-m');
}

// Consumo acumulado de un negocio en un periodo (mensajes y tokens).
function uso_mes(int $idNegocio, string $periodo = ''): array {
    $periodo = $periodo !== '' ? $periodo : periodo_actual();
    $st = conexion()->prepare(
        "SELECT mensajes, tokens_entrada, tokens_salida
           FROM uso_mensual WHERE id_negocio = ? AND periodo = ?"
    );
    $st->execute([$idNegocio, $periodo]);
    $r = $st->fetch();
    return $r ?: ['mensajes' => 0, 'tokens_entrada' => 0, 'tokens_salida' => 0];
}

// ¿El negocio puede atender un mensaje más este mes?
// limite_mensajes_mes = 0  =>  ilimitado (pilotos, dogfood).
function dentro_de_limite(array $negocio): bool {
    $limite = (int)($negocio['limite_mensajes_mes'] ?? 0);
    if ($limite <= 0) return true;
    $uso = uso_mes((int)$negocio['id']);
    return (int)$uso['mensajes'] < $limite;
}

// Costo estimado en USD del consumo de Claude (modelo Haiku 4.5).
// Precios oficiales: entrada $1.00 / 1M tokens, salida $5.00 / 1M tokens.
// Solo cubre el costo de la IA; NO incluye la tarifa de Twilio/WhatsApp.
define('PRECIO_ENTRADA_POR_MTOK', 1.00);
define('PRECIO_SALIDA_POR_MTOK', 5.00);
function costo_estimado_usd(int $tokensEntrada, int $tokensSalida): float {
    return $tokensEntrada / 1000000 * PRECIO_ENTRADA_POR_MTOK
         + $tokensSalida  / 1000000 * PRECIO_SALIDA_POR_MTOK;
}

// Formatea un costo en USD para mostrar: "<$0.01" para importes menores a un centavo.
function formato_costo_usd(float $usd): string {
    if ($usd > 0 && $usd < 0.01) return '<$0.01 USD';
    return '$' . number_format($usd, 2) . ' USD';
}

// Suma 1 mensaje atendido y los tokens consumidos al periodo actual del negocio.
// Crea la fila del mes la primera vez (UPSERT).
function registrar_uso(int $idNegocio, int $tokensEntrada = 0, int $tokensSalida = 0): void {
    $st = conexion()->prepare(
        "INSERT INTO uso_mensual (id_negocio, periodo, mensajes, tokens_entrada, tokens_salida)
              VALUES (?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE mensajes       = mensajes + 1,
                                 tokens_entrada = tokens_entrada + ?,
                                 tokens_salida  = tokens_salida + ?"
    );
    $st->execute([$idNegocio, periodo_actual(), $tokensEntrada, $tokensSalida, $tokensEntrada, $tokensSalida]);
}
