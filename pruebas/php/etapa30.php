<?php
/**
 * pruebas/php/etapa30.php — ResumenFiscal: a los impuestos sólo entran facturas pagadas y gastos pagados.
 *
 * Comprueba la regla de pago de la hoja «FISCAL» (Fiscal → Resumen, impuestos de Contabilidad, cierre y PDFs):
 *   · una factura «facturada» SIN cobro o con pago PARCIAL no suma «facturado»: sale como excluida
 *     (n.º e importe en 'facturas_sin_pagar[_n]');
 *   · al confirmarse el pago al 100 %, la factura entra en el mes de su fecha y deja de ser excluida;
 *   · un gasto con estado_pago 'pendiente' no resta: sale como 'gastos_no_pagados[_n]';
 *   · 'movimientos()' muestra en el «Detalle» el estado de cada fila (Pagada/Pago parcial/Sin pagar y
 *     Pagado/Por pagar) y reparte los totales entre lo que sí cuenta ('ventas_facturadas_pagadas',
 *     'gastos_pagados') y todo lo registrado;
 *   · 'impuestosDelPeriodo()' devuelve esas mismas cifras por mes y por año;
 *   · el método de pago del CFDI es informativo pero explica cada caso: PUE (una sola exhibición)
 *     descuenta desde su registro, PPD (parcialidades) sólo al liquidarse y «por definir» sólo cuando
 *     se marque pagado; los tres aparecen en el «Detalle» de movimientos();
 *   · el cierre de mes guarda en su fotografía la estimación con esta regla.
 *
 * FASE 7 (integración con MotorFiscal): desde entonces el resumen sale de UN solo motor.
 * Lo que esta etapa valida cambió respecto a la versión anterior (y aquí se documenta):
 *   · el contribuyente necesita CONFIGURACIÓN FISCAL vigente (régimen + flujo de efectivo):
 *     sin ella el motor no calcula en vez de inventar cifras;
 *   · «facturas_sin_pagar» ahora declara lo que FALTA por cobrar (no el total de la factura);
 *   · una factura cobrada entra aunque no esté marcada como facturada (flujo de efectivo);
 *   · el ISR usa la TARIFA progresiva del catálogo art. 106 (acumulado del año),
 *     no la tasa plana configurable de antes;
 *   · la fotografía del cierre añade motor, versiones y «habilitado» a sus columnas.
 * Los movimientos() y el detalle por método de pago no cambian: siguen siendo los mismos.
 *
 * Trabaja sobre un contribuyente PROPIO (rfc T30FISCAL01): no toca los datos de las demás pruebas.
 *
 * Los nombres llevan el «30» para no chocar con los de las otras etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa30.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CatalogoFiscal;
use App\CierrePeriodo;
use App\MotorFiscal;
use App\ResumenFiscal;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$ig  = fn ($a, $b) => abs((float) $a - (float) $b) < 0.005;          // igualdad con tolerancia de centavo
$num = fn ($v) => number_format((float) $v, 2, '.', '');

$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$rfc     = 'T30FISCAL01';

/** Borra todo lo de esta etapa (contribuyente propio) en orden de dependencias. */
$limpiar = function () use ($db, $rfc) {
    $c = (int) ($db->query("SELECT id FROM contribuyentes WHERE rfc = '$rfc'")->fetch_row()[0] ?? 0);
    if ($c > 0) {
        $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE contribuyente_id = $c)");
        $db->query("DELETE FROM ingresos WHERE contribuyente_id = $c");
        $db->query("DELETE FROM gasto_reparto WHERE gasto_id IN (SELECT id FROM gastos WHERE contribuyente_id = $c)");
        $db->query("DELETE FROM gastos WHERE contribuyente_id = $c");
        $db->query("DELETE FROM periodos_historial WHERE periodo_id IN (SELECT id FROM periodos_contables WHERE contribuyente_id = $c)");
        $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = $c");
        $db->query("DELETE FROM configuracion_fiscal WHERE contribuyente_id = $c");
        $db->query("DELETE FROM empresas WHERE contribuyente_id = $c");
        $db->query("DELETE FROM contribuyentes WHERE id = $c");
    }
    $db->query("DELETE FROM clientes WHERE nombre LIKE 'T30 %'");
};
$limpiar();

$db->query("INSERT INTO contribuyentes (rfc, razon_social, tipo_persona, regimen, activo)
            VALUES ('$rfc', 'T30 Contribuyente', 'fisica', 'Actividades Empresariales y Profesionales', 1)");
$contrib = (int) $db->insert_id;
$db->query("INSERT INTO empresas (nombre, contribuyente_id, activa) VALUES ('T30 Negocio', $contrib, 1)");
$empresa = (int) $db->insert_id;
$db->query("INSERT INTO clientes (nombre) VALUES ('T30 Cliente')");
$cliente = (int) $db->insert_id;
// FASE 7: sin configuración fiscal vigente (régimen y base) el motor NO calcula.
$db->query("INSERT INTO configuracion_fiscal (contribuyente_id, vigente_desde, tipo_persona, regimen, base_ingresos,
            confirmada_con_contador, motivo_cambio, usuario_id)
            VALUES ($contrib, '2026-01-01', 'fisica', 'Actividades Empresariales y Profesionales', 'flujo_efectivo', 1,
            'Configuración de la etapa 30', $usuario)");
// Tarifa art. 106 del catálogo activo (para las expectativas de ISR de esta etapa).
$tarifa = fn (int $mes): array => CatalogoFiscal::activa('isr_pagos_provisionales_art106', 2026)['doc']['tarifas'][(string) $mes] ?? [];
$isrEsperado = fn (int $utilidadPesos, int $mes): float => MotorFiscal::impuestoSegunTarifa((int) round($utilidadPesos * 100), $tarifa($mes)) / 100;

/** Factura emitida (ingreso) con su importe desglosado. */
$factura = function (string $folio, string $fecha, float $sub, float $iva, string $facturacion) use ($db, $contrib, $empresa, $cliente): int {
    $db->query(sprintf(
        "INSERT INTO ingresos (contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto, subtotal, iva, total, estado, estado_facturacion)
         VALUES (%d, %d, %d, '%s', '%s', 'Factura de prueba etapa 30', %.2f, %.2f, %.2f, 'vigente', '%s')",
        $contrib, $empresa, $cliente, $fecha, $folio, $sub, $iva, $sub + $iva, $facturacion
    ));

    return (int) $db->insert_id;
};
$cobro = function (int $ingreso, string $fecha, float $importe) use ($db, $usuario): int {
    $db->query(sprintf(
        "INSERT INTO cobros (ingreso_id, fecha, importe, metodo, estado, usuario_id) VALUES (%d, '%s', %.2f, 'Transferencia', 'vigente', %d)",
        $ingreso, $fecha, $importe, $usuario
    ));

    return (int) $db->insert_id;
};
/** Gasto con su estado de pago. */
$gasto = function (string $concepto, string $fecha, float $sub, float $iva, string $pago) use ($db, $contrib, $empresa, $usuario): int {
    $db->query(sprintf(
        "INSERT INTO gastos (contribuyente_id, empresa_id, fecha, beneficiario, concepto, naturaleza, subtotal, iva, total,
                            estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, estado, usuario_id)
         VALUES (%d, %d, '%s', 'T30 Proveedor', '%s', 'gasto', %.2f, %.2f, %.2f, '%s', %s, 1, 'validado', 'vigente', %d)",
        $contrib, $empresa, $fecha, $concepto, $sub, $iva, $sub + $iva, $pago,
        $pago === 'pagado' ? "'$fecha'" : 'NULL', $usuario
    ));

    return (int) $db->insert_id;
};

echo "Carga de datos (marzo y abril de 2026)\n";
$iA = $factura('T30-A', '2026-03-10', 10000, 1600, 'facturada');   // 11,600 sin cobrar
$iB = $factura('T30-B', '2026-03-15', 5000, 800, 'facturada');     //  5,800 a medias
$iC = $factura('T30-C', '2026-03-20', 2000, 320, 'facturada');     //  2,320 pagada
$iD = $factura('T30-D', '2026-04-05', 4000, 640, 'facturada');     //  4,640 pagada
$iE = $factura('T30-E', '2026-04-10', 1000, 160, 'no_facturada');  //  1,160 pagada pero sin factura
$gA = $gasto('T30 Gasto pagado', '2026-03-12', 1000, 160, 'pagado');       // 1,160 pagado
$gB = $gasto('T30 Gasto por pagar', '2026-03-18', 2000, 320, 'pendiente'); // 2,320 por pagar
$cobro($iB, '2026-03-16', 2900);                                   // parcialidades: la mitad
$cobro($iC, '2026-03-21', 2320);
$cobro($iD, '2026-04-06', 4640);
$cobro($iE, '2026-04-11', 1160);
$prueba('las 5 facturas y los 2 gastos quedaron registrados',
    (int) $uno("SELECT COUNT(*) FROM ingresos WHERE contribuyente_id = $contrib") === 5
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE contribuyente_id = $contrib") === 2 && $gA > 0 && $gB > 0);

echo "Facturado: sólo lo cobrado al 100 %\n";
$r = ResumenFiscal::anual($contrib, 2026);
$mar = $r['meses'][3];
$prueba('marzo: sólo la factura pagada cuenta (2,320)', $ig($mar['facturado'], 2320), $num($mar['facturado']));
$prueba('  las otras 2 salen excluidas por lo que FALTA cobrar: 11,600 + 2,900 = 14,500',
    $mar['facturas_sin_pagar_n'] === 2 && $ig($mar['facturas_sin_pagar'], 14500),
    $mar['facturas_sin_pagar_n'] . ' / ' . $num($mar['facturas_sin_pagar']));
$prueba('  IVA facturado sólo del cobrado (320) y acreditable sólo del pagado (160) → 160 por pagar',
    $ig($mar['iva_facturado'], 320) && $ig($mar['iva_acreditado'], 160) && $ig($mar['iva_por_pagar'], 160),
    $num($mar['iva_facturado']) . '/' . $num($mar['iva_acreditado']));
$prueba('abril: la cobrada cuenta aunque no esté marcada como facturada (4,640 + 1,160 = 5,800) y no aparece excluida',
    $ig($r['meses'][4]['facturado'], 5800) && $r['meses'][4]['facturas_sin_pagar_n'] === 0, $num($r['meses'][4]['facturado']));
$prueba('los excluidos se acumulan en el total del año (14,500 por cobrar)', $ig($r['total']['facturas_sin_pagar'], 14500), $num($r['total']['facturas_sin_pagar']));
$prueba('ninguna factura queda «sin clasificar»', $r['sin_clasificar'] === 0);

echo "Gastos: sólo los pagados descuentan\n";
$prueba('marzo: pagos y gastos = 1,160 (el gasto pendiente no descuenta)', $ig($mar['pagos'], 1160), $num($mar['pagos']));
$prueba('  el gasto por pagar sale excluido (2,320)', $mar['gastos_no_pagados_n'] === 1 && $ig($mar['gastos_no_pagados'], 2320),
    $mar['gastos_no_pagados_n'] . ' / ' . $num($mar['gastos_no_pagados']));
$prueba('  ISR del mes con la TARIFA art. 106 del catálogo sobre la utilidad acumulada (2,000 − 1,000)',
    $ig($mar['isr_por_pagar'], $isrEsperado(1000, 3)), $num($mar['isr_por_pagar']) . ' vs ' . $num($isrEsperado(1000, 3)));
$prueba('impuestosDelPeriodo(3) devuelve la misma estimación', $ig(ResumenFiscal::impuestosDelPeriodo($contrib, 2026, 3)['facturado'], 2320));

echo "Al confirmarse el pago, la factura entra en su mes\n";
$cobro($iB, '2026-03-25', 2900);                                    // resto: ya pagada al 100 %
$r2 = ResumenFiscal::anual($contrib, 2026);
$prueba('marzo suma ahora 2,320 + 5,800 = 8,120', $ig($r2['meses'][3]['facturado'], 8120), $num($r2['meses'][3]['facturado']));
$prueba('  queda 1 factura fuera (la que nunca se cobró), por 11,600',
    $r2['meses'][3]['facturas_sin_pagar_n'] === 1 && $ig($r2['meses'][3]['facturas_sin_pagar'], 11600),
    $r2['meses'][3]['facturas_sin_pagar_n'] . ' / ' . $num($r2['meses'][3]['facturas_sin_pagar']));
$prueba('  IVA facturado de marzo = 320 + 800 = 1,120', $ig($r2['meses'][3]['iva_facturado'], 1120), $num($r2['meses'][3]['iva_facturado']));

echo "Movimientos: cada fila dice si está pagada y los totales separan lo que cuenta\n";
$d = ResumenFiscal::movimientos($contrib, 2026);
$porReferencia = [];
foreach ($d['filas'] as $f) {
    $porReferencia[$f['referencia']] = $f;
}
$prueba('lista las 5 facturas y los 2 gastos', count($d['filas']) === 7, count($d['filas']));
$prueba('detalle de la factura sin cobrar: «Facturada · Sin pagar»', $porReferencia['T30-A']['detalle'] === 'Facturada · Sin pagar', $porReferencia['T30-A']['detalle'] ?? '(falta)');
$prueba('detalle de la que estaba en parcialidades: «Facturada · Pagada»', $porReferencia['T30-B']['detalle'] === 'Facturada · Pagada', $porReferencia['T30-B']['detalle'] ?? '(falta)');
$prueba('detalle de la no facturada: «No facturada · Pagada»', $porReferencia['T30-E']['detalle'] === 'No facturada · Pagada', $porReferencia['T30-E']['detalle'] ?? '(falta)');
$prueba('detalle de los gastos: «Sin tipo · Pagado» y «Sin tipo · Por pagar»',
    ($porReferencia['T30 Gasto pagado']['detalle'] ?? '') === 'Sin tipo · Pagado'
    && ($porReferencia['T30 Gasto por pagar']['detalle'] ?? '') === 'Sin tipo · Por pagar',
    ($porReferencia['T30 Gasto pagado']['detalle'] ?? '(falta)') . ' | ' . ($porReferencia['T30 Gasto por pagar']['detalle'] ?? '(falta)'));
$tot = $d['totales'];
$prueba('ventas registradas 25,520 (con la no facturada)', $ig($tot['ventas'], 25520), $num($tot['ventas']));
$prueba('  de ellas facturadas 24,360 y ya cobradas 12,760', $ig($tot['ventas_facturadas'], 24360) && $ig($tot['ventas_facturadas_pagadas'], 12760),
    $num($tot['ventas_facturadas']) . ' / ' . $num($tot['ventas_facturadas_pagadas']));
$prueba('gastos 3,480 y pagados 1,160 (sólo ellos descuentan)', $ig($tot['gastos'], 3480) && $ig($tot['gastos_pagados'], 1160),
    $num($tot['gastos']) . ' / ' . $num($tot['gastos_pagados']));
$prueba('la IVA de ventas registrado sigue siendo el completo (3,520)', $ig($tot['iva_ventas'], 3520), $num($tot['iva_ventas']));

echo "IVA real de cada operación y métodos de pago del CFDI (informativos)\n";
$iF = $factura('T30-F', '2026-04-20', 3000, 0, 'facturada');   // 3,000 al 0 %: base = total, IVA 0
$cobro($iF, '2026-04-21', 3000);
$gPPD = $gasto('T30 Gasto PPD', '2026-04-22', 500, 80, 'pendiente');
$gPOR = $gasto('T30 Gasto por definir', '2026-04-23', 300, 48, 'pendiente');
$db->query("UPDATE gastos SET metodo_pago_sat = 'PPD' WHERE id = $gPPD");
$db->query("UPDATE gastos SET metodo_pago_sat = 'POR' WHERE id = $gPOR");

$r3 = ResumenFiscal::anual($contrib, 2026);
$abr = $r3['meses'][4];
$prueba('la factura al 0 % entra con su base completa y IVA 0: no se inventa el 16 % (con la cobrada: 8,800 / 800 / 8,000)',
    $ig($abr['facturado'], 8800) && $ig($abr['iva_facturado'], 800) && $ig($abr['facturado_sin_iva'], 8000),
    $num($abr['facturado']) . ' / ' . $num($abr['iva_facturado']) . ' / ' . $num($abr['facturado_sin_iva']));
$prueba('PPD y «por definir» sin pagar: los dos quedan fuera (928)',
    $abr['gastos_no_pagados_n'] === 2 && $ig($abr['gastos_no_pagados'], 928),
    $abr['gastos_no_pagados_n'] . ' / ' . $num($abr['gastos_no_pagados']));
$prueba('las fórmulas cuadran: IVA = cobrado − acreditable (800 − 0) e ISR con la tarifa art. 106 (utilidad 14,000)',
    $ig($abr['iva_por_pagar'], 800) && $ig($abr['isr_por_pagar'], $isrEsperado(14000, 4)),
    $num($abr['iva_por_pagar']) . ' / ' . $num($abr['isr_por_pagar']));

$db->query("UPDATE gastos SET estado_pago = 'pagado', fecha_pago = '2026-04-24' WHERE id = $gPPD");
$r4 = ResumenFiscal::anual($contrib, 2026);
$abr2 = $r4['meses'][4];
$prueba('al pagarse el PPD sí descuenta (580 con 80 de IVA) y el «por definir» sigue fuera',
    $ig($abr2['pagos'], 580) && $ig($abr2['iva_acreditado'], 80) && $abr2['gastos_no_pagados_n'] === 1,
    $num($abr2['pagos']) . ' / ' . $num($abr2['iva_acreditado']));
$prueba('  el ISR baja al pagar el PPD (utilidad acumulada 15,000 − 1,500 = 13,500 con tarifa art. 106)',
    $ig($abr2['isr_por_pagar'], $isrEsperado(13500, 4)), $num($abr2['isr_por_pagar']));

$d2 = ResumenFiscal::movimientos($contrib, 2026, 4);
$porRef = [];
foreach ($d2['filas'] as $f) {
    $porRef[$f['referencia']] = $f;
}
$prueba('el detalle muestra el método: «Pagado · PPD · parcialidades» y «Por pagar · Por definir (aún no se decide)»',
    ($porRef['T30 Gasto PPD']['detalle'] ?? '') === 'Sin tipo · Pagado · PPD · parcialidades'
    && ($porRef['T30 Gasto por definir']['detalle'] ?? '') === 'Sin tipo · Por pagar · Por definir (aún no se decide)',
    ($porRef['T30 Gasto PPD']['detalle'] ?? '(falta)') . ' | ' . ($porRef['T30 Gasto por definir']['detalle'] ?? '(falta)'));
$prueba('los totales separan el IVA de lo pagado (80) del IVA registrado (128)',
    $ig($d2['totales']['iva_gastos_pagados'], 80) && $ig($d2['totales']['iva_gastos'], 128),
    $num($d2['totales']['iva_gastos_pagados']) . ' / ' . $num($d2['totales']['iva_gastos']));

// Los tres métodos del CFDI, uno al lado del otro: PUE descuenta ya, PPD y POR esperan su pago.
$gPUE = $gasto('T30 Gasto PUE', '2026-04-25', 1000, 160, 'pagado');
$db->query("UPDATE gastos SET metodo_pago_sat = 'PUE' WHERE id = $gPUE");
$r5 = ResumenFiscal::anual($contrib, 2026);
$abr3 = $r5['meses'][4];
$prueba('PUE (una sola exhibición) descuenta desde su registro: no pide nada más para entrar',
    $ig($abr3['pagos'], 1740) && $ig($abr3['iva_acreditado'], 240),
    $num($abr3['pagos']) . ' / ' . $num($abr3['iva_acreditado']));
$prueba('  con PUE, PPD pagado y POR fuera, el ISR queda en la tarifa art. 106 sobre la utilidad 12,500',
    $ig($abr3['isr_por_pagar'], $isrEsperado(12500, 4)), $num($abr3['isr_por_pagar']));
$prueba('  el IVA por pagar baja a 800 − 240 = 560', $ig($abr3['iva_por_pagar'], 560), $num($abr3['iva_por_pagar']));
$prueba('  y sigue quedando 1 gasto fuera: el «por definir» (348)',
    $abr3['gastos_no_pagados_n'] === 1 && $ig($abr3['gastos_no_pagados'], 348),
    $abr3['gastos_no_pagados_n'] . ' / ' . $num($abr3['gastos_no_pagados']));

$d3 = ResumenFiscal::movimientos($contrib, 2026, 4);
$porRef3 = [];
foreach ($d3['filas'] as $f) {
    $porRef3[$f['referencia']] = $f;
}
$prueba('los tres métodos se leen en el detalle: «Pagado · PUE · una sola exhibición»,
        «Pagado · PPD · parcialidades» y «Por pagar · Por definir (aún no se decide)»',
    ($porRef3['T30 Gasto PUE']['detalle'] ?? '') === 'Sin tipo · Pagado · PUE · una sola exhibición'
    && ($porRef3['T30 Gasto PPD']['detalle'] ?? '') === 'Sin tipo · Pagado · PPD · parcialidades'
    && ($porRef3['T30 Gasto por definir']['detalle'] ?? '') === 'Sin tipo · Por pagar · Por definir (aún no se decide)',
    ($porRef3['T30 Gasto PUE']['detalle'] ?? '(falta)') . ' | ' . ($porRef3['T30 Gasto PPD']['detalle'] ?? '(falta)') . ' | ' . ($porRef3['T30 Gasto por definir']['detalle'] ?? '(falta)'));
$prueba('el método es informativo: cuenta el pago, no la etiqueta (PUE y PPD pagados ya descuentan)',
    $ig($d3['totales']['gastos_pagados'], 1740) && $ig($d3['totales']['iva_gastos_pagados'], 240),
    $num($d3['totales']['gastos_pagados']) . ' / ' . $num($d3['totales']['iva_gastos_pagados']));

echo "Cierre del mes: la fotografía respeta la regla de pago\n";
$ver = fn (int $mes) => array_column(CierrePeriodo::verificaciones($contrib, 2026, $mes), null, 'codigo');
$codigos = $ver(3);
$prueba('el cierre sigue avisando «Ventas con cobro pendiente» y «Gastos con pago pendiente»',
    ($codigos['cobros_pendientes']['nivel'] ?? '') === 'info' && ($codigos['pagos_pendientes']['nivel'] ?? '') === 'info',
    json_encode(array_keys($codigos)));
$prueba('  y ahora explican que no entran hasta pagar', str_contains($codigos['cobros_pendientes']['detalle'] ?? '', 'No entran en la estimación')
    && str_contains($codigos['pagos_pendientes']['detalle'] ?? '', 'no descuentan'), $codigos['cobros_pendientes']['detalle'] ?? '(falta)');
$prueba('no hay errores que bloqueen el cierre', !array_filter(CierrePeriodo::verificaciones($contrib, 2026, 3), fn ($v) => $v['nivel'] === 'error'),
    json_encode(array_column(CierrePeriodo::verificaciones($contrib, 2026, 3), 'codigo')));
$prueba('cerrar marzo (reconociendo facturas sin adjuntar)', CierrePeriodo::cerrar($contrib, 2026, 3, $usuario, ['facturas_sin_adjuntar'], 'Cierre de prueba etapa 30'),
    implode('|', CierrePeriodo::getErrores()));
$snap = CierrePeriodo::periodo($contrib, 2026, 3)['snapshot']['fiscal'] ?? [];
$prueba('la foto guarda el facturado con las facturas ya cobradas (8,120)', $ig($snap['facturado'] ?? -1, 8120), $num($snap['facturado'] ?? -1));
$prueba('  y los «pagos y gastos» sólo con lo pagado (1,160)', $ig($snap['pagos'] ?? -1, 1160), $num($snap['pagos'] ?? -1));
$esperado = ['facturado', 'pagos', 'habilitado', 'iva_por_pagar', 'isr_por_pagar', 'impuestos'];
$prueba('  la foto conserva sus columnas de siempre (y nada más)', count($snap) === count($esperado)
    && !array_diff(array_keys($snap), $esperado) && !array_diff($esperado, array_keys($snap)), implode(',', array_keys($snap)));

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
