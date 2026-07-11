<?php
// Control de caja: marcar citas como cobradas y sumar ingresos por periodo.
// El monto se guarda en la cita al cobrar (monto_cobrado), tomando por defecto el
// precio del servicio. El corte se calcula por pagado_en (cuando entro el dinero).

require_once __DIR__ . '/../config/db.php';

function caja_normalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    return preg_replace('/\s+/', ' ', $s);
}

// Precio del servicio (por nombre) de este negocio. 0 si no lo encuentra.
function precio_de_servicio(string $servicio, int $idNegocio): float {
    $buscado = caja_normalizar($servicio);
    if ($buscado === '') return 0.0;
    $st = conexion()->prepare("SELECT nombre, precio FROM servicios WHERE id_negocio = ?");
    $st->execute([$idNegocio]);
    foreach ($st as $s) {
        if (caja_normalizar((string)$s['nombre']) === $buscado) return (float)$s['precio'];
    }
    // coincidencia parcial como respaldo
    $st->execute([$idNegocio]);
    foreach ($st as $s) {
        $n = caja_normalizar((string)$s['nombre']);
        if ($n !== '' && (strpos($n, $buscado) !== false || strpos($buscado, $n) !== false)) return (float)$s['precio'];
    }
    return 0.0;
}

// Marca una cita como cobrada (re-verificando que es del negocio). $metodo: efectivo|transferencia.
function marcar_cita_pagada(int $idCita, int $idNegocio, string $metodo, float $monto): bool {
    $metodo = in_array($metodo, ['efectivo', 'transferencia'], true) ? $metodo : 'efectivo';
    $st = conexion()->prepare(
        "UPDATE citas SET pagado = 1, metodo_pago = ?, monto_cobrado = ?, pagado_en = NOW()
         WHERE id = ? AND id_negocio = ?"
    );
    return $st->execute([$metodo, round($monto, 2), $idCita, $idNegocio]);
}

// Deshace el cobro de una cita.
function marcar_cita_no_pagada(int $idCita, int $idNegocio): bool {
    $st = conexion()->prepare(
        "UPDATE citas SET pagado = 0, metodo_pago = NULL, monto_cobrado = NULL, pagado_en = NULL
         WHERE id = ? AND id_negocio = ?"
    );
    return $st->execute([$idCita, $idNegocio]);
}

// Rango [desde, hasta) en 'Y-m-d H:i:s' para un periodo dado.
function rango_periodo(string $periodo): array {
    switch ($periodo) {
        case 'ayer':
            $d = date('Y-m-d 00:00:00', strtotime('yesterday'));
            $h = date('Y-m-d 00:00:00', strtotime('today'));
            break;
        case 'semana':
            $d = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $h = date('Y-m-d 00:00:00', strtotime('monday next week'));
            break;
        case 'mes':
            $d = date('Y-m-01 00:00:00');
            $h = date('Y-m-01 00:00:00', strtotime('first day of next month'));
            break;
        case 'hoy':
        default:
            $d = date('Y-m-d 00:00:00');
            $h = date('Y-m-d 00:00:00', strtotime('tomorrow'));
            break;
    }
    return [$d, $h];
}

// Resumen de lo cobrado en [desde, hasta): total, por metodo y numero de citas.
function resumen_caja(int $idNegocio, string $desde, string $hasta): array {
    $r = ['total' => 0.0, 'efectivo' => 0.0, 'transferencia' => 0.0, 'num' => 0];
    $st = conexion()->prepare(
        "SELECT metodo_pago, SUM(monto_cobrado) t, COUNT(*) n
         FROM citas
         WHERE id_negocio = ? AND pagado = 1 AND pagado_en >= ? AND pagado_en < ?
         GROUP BY metodo_pago"
    );
    $st->execute([$idNegocio, $desde, $hasta]);
    foreach ($st as $row) {
        $monto = (float)$row['t'];
        $r['total'] += $monto;
        $r['num']  += (int)$row['n'];
        $m = (string)$row['metodo_pago'];
        if ($m === 'efectivo')            $r['efectivo'] += $monto;
        elseif ($m === 'transferencia')   $r['transferencia'] += $monto;
    }
    return $r;
}

// Citas ya pasadas (o de hoy) sin cobrar: total y numero.
function pendientes_cobro(int $idNegocio): array {
    $st = conexion()->prepare(
        "SELECT servicio, monto_cobrado FROM citas
         WHERE id_negocio = ? AND pagado = 0 AND estado <> 'cancelada'
           AND fecha IS NOT NULL AND fecha <= CURDATE()"
    );
    $st->execute([$idNegocio]);
    $total = 0.0; $num = 0;
    foreach ($st as $c) {
        $total += precio_de_servicio((string)$c['servicio'], $idNegocio);
        $num++;
    }
    return ['total' => $total, 'num' => $num];
}

function caja_money(float $n): string {
    return '$' . number_format($n, 2, '.', ',');
}

// ---- Consulta de caja por WhatsApp (para el dueño) ----

// ¿El mensaje del dueño pide un corte/ingresos? (evita disparar con "corte de cabello").
function es_consulta_caja(string $texto): bool {
    $t = caja_normalizar($texto);
    $claves = [
        'corte de caja', 'corte de hoy', 'corte del dia', 'corte de la semana', 'corte del mes',
        'cuanto llev', 'cuanto vend', 'cuanto cobr', 'cuanto gan', 'cuanto hice', 'cuanto he hecho',
        'mis ventas', 'ventas de', 'ventas del', 'mis ingresos', 'ingresos de', 'mi caja', 'resumen de caja',
    ];
    foreach ($claves as $k) {
        if (strpos($t, $k) !== false) return true;
    }
    return false;
}

function periodo_de_texto(string $texto): string {
    $t = caja_normalizar($texto);
    if (strpos($t, 'semana') !== false) return 'semana';
    if (strpos($t, 'mes')    !== false) return 'mes';
    if (strpos($t, 'ayer')   !== false) return 'ayer';
    return 'hoy';
}

function etiqueta_periodo(string $periodo): string {
    return ['hoy' => 'hoy', 'ayer' => 'ayer', 'semana' => 'esta semana', 'mes' => 'este mes'][$periodo] ?? 'hoy';
}

// Texto del corte para responder al dueño por WhatsApp.
function corte_texto(int $idNegocio, string $periodo): string {
    [$desde, $hasta] = rango_periodo($periodo);
    $r = resumen_caja($idNegocio, $desde, $hasta);
    $p = pendientes_cobro($idNegocio);

    $lineas = [];
    $lineas[] = 'Corte de ' . etiqueta_periodo($periodo) . ':';
    $lineas[] = 'Total cobrado: ' . caja_money($r['total']) . ' (' . $r['num'] . ' ' . ($r['num'] === 1 ? 'cita' : 'citas') . ')';
    $lineas[] = 'Efectivo: ' . caja_money($r['efectivo']);
    $lineas[] = 'Transferencia: ' . caja_money($r['transferencia']);
    if ($p['num'] > 0) {
        $lineas[] = 'Pendientes de cobro: ' . caja_money($p['total']) . ' (' . $p['num'] . ')';
    }
    return implode("\n", $lineas);
}
