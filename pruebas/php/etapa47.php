<?php
/**
 * pruebas/php/etapa47.php — Gastos generales y de proveedor en Análisis Financiero.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa47.php
 *
 * Comprueba que el análisis incluye los gastos del mes que antes se perdían:
 *   · gastos generales (sin negocio asignado);
 *   · gastos pendientes con proveedor que no están ligados a un pedido;
 *   · gastos compartidos, sólo en la parte de los negocios seleccionados;
 *   · gastos ligados a un pedido no se cuentan dos veces.
 *
 * Usa un periodo lejano (2031-07) y el prefijo «T47» para no chocar con datos reales.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\AnalisisFinanciero;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};

$usuario       = (int) $db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_row()[0];
$contribuyente = (int) $db->query('SELECT id FROM contribuyentes ORDER BY id LIMIT 1')->fetch_row()[0];
$anio          = 2031;
$mes           = 7;

$limpiar = function () use ($db) {
    $proveedores = array_column(
        $db->query("SELECT id FROM proveedores WHERE nombre LIKE 'T47 %'")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    if ($proveedores) {
        $ids = implode(',', array_map('intval', $proveedores));
        $ordenes = array_column(
            $db->query("SELECT id FROM ordenes_compra WHERE proveedor_id IN ($ids)")->fetch_all(MYSQLI_ASSOC),
            'id'
        );
        if ($ordenes) {
            $ordenesIds = implode(',', array_map('intval', $ordenes));
            $db->query("DELETE FROM pagos_compra WHERE orden_id IN ($ordenesIds)");
            $db->query("DELETE FROM ordenes_compra WHERE id IN ($ordenesIds)");
        }
        $db->query("DELETE FROM gastos WHERE proveedor_id IN ($ids)");
        $db->query("DELETE FROM proveedores WHERE id IN ($ids)");
    }
    $db->query("DELETE FROM gastos WHERE concepto LIKE 'T47 %'");
};
$limpiar();

$db->query(
    "INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo)
     VALUES ('T47 Proveedor análisis', 'T47XAXX010101', 'Material', 1)"
);
$proveedor = (int) $db->insert_id;

/** Inserta un gasto del periodo de prueba y devuelve su id. */
$gasto = function (array $datos) use ($db, $contribuyente, $usuario, $anio, $mes): int {
    $fecha      = sprintf('%04d-%02d-15', $anio, $mes);
    $concepto   = $db->real_escape_string((string) $datos['concepto']);
    $beneficio  = $db->real_escape_string((string) ($datos['beneficiario'] ?? 'T47'));
    $tipo       = $db->real_escape_string((string) ($datos['beneficiario_tipo'] ?? 'otro'));
    $proveedor  = ($datos['proveedor_id'] ?? null) === null ? 'NULL' : (int) $datos['proveedor_id'];
    $empresa    = ($datos['empresa_id'] ?? null) === null ? 'NULL' : (int) $datos['empresa_id'];
    $compartido = !empty($datos['compartido']) ? 1 : 0;
    $estadoPago = (string) ($datos['estado_pago'] ?? 'pendiente');
    $subtotal   = (float) $datos['subtotal'];
    $iva        = (float) ($datos['iva'] ?? 0);
    $total      = $subtotal + $iva;

    $db->query(
        "INSERT INTO gastos
            (contribuyente_id, fecha, proveedor_id, beneficiario_tipo, beneficiario, concepto, naturaleza,
             empresa_id, compartido, subtotal, iva_tasa, iva, total, estado_pago, comprobante_fiscal, estado, usuario_id)
         VALUES
            ($contribuyente, '$fecha', $proveedor, '$tipo', '$beneficio', '$concepto', 'gasto',
             $empresa, $compartido, $subtotal, 0.00, $iva, $total, '$estadoPago', 0, 'vigente', $usuario)"
    );

    return (int) $db->insert_id;
};

$reparto = function (int $gastoId, array $porcentajes) use ($db): void {
    foreach ($porcentajes as $empresaId => $porcentaje) {
        $db->query("INSERT INTO gasto_reparto (gasto_id, empresa_id, porcentaje) VALUES ($gastoId, " . (int) $empresaId . ", $porcentaje)");
    }
};

/* --- Datos ---------------------------------------------------------------- */

// 1. General pendiente: no tiene negocio y no es compartido.
$generalPendiente = $gasto([
    'concepto' => 'T47 Gastos generales pendientes',
    'subtotal' => 1000, 'iva' => 160,
]);

// 2. Proveedor pendiente sin pedido: antes se descartaba por el filtro de proveedor.
$proveedorSuelto = $gasto([
    'concepto' => 'T47 Gasto de proveedor sin pedido',
    'subtotal' => 500, 'iva' => 80, 'proveedor_id' => $proveedor, 'beneficiario_tipo' => 'proveedor',
    'beneficiario' => 'T47 Proveedor análisis',
]);

// 3. Pendiente ligado a un pedido: se muestra en proveedores, no en gastos.
$db->query(
    "INSERT INTO ordenes_compra
        (folio, tipo, proveedor_id, fecha_solicitud, referencia, contribuyente_id,
         estado, total_esperado, estado_cfdi, iva, usuario_id)
     VALUES
        ('T47-OC-1', 'insumo', $proveedor, '2031-07-15', 'T47-COMPRA', $contribuyente,
         'completa', 2000.00, 'pendiente_cfdi', 320.00, $usuario)"
);
$orden = (int) $db->insert_id;
$ligadoAlPedido = $gasto([
    'concepto' => 'T47 Gasto ligado al pedido',
    'subtotal' => 2000, 'iva' => 320, 'proveedor_id' => $proveedor, 'beneficiario_tipo' => 'proveedor',
    'beneficiario' => 'T47 Proveedor análisis',
]);
$db->query("UPDATE ordenes_compra SET gasto_id = $ligadoAlPedido WHERE id = $orden");

// 4. General pagado: sólo debe aparecer en el modo detallado.
$generalPagado = $gasto([
    'concepto' => 'T47 Gasto general pagado',
    'subtotal' => 800, 'iva' => 128, 'estado_pago' => 'pagado',
]);

// 5. Compartido 50/50: al elegir un negocio sólo entra su mitad.
$compartido = $gasto([
    'concepto' => 'T47 Gasto compartido',
    'subtotal' => 1000, 'iva' => 0, 'compartido' => 1,
]);
$reparto($compartido, [1 => 50, 2 => 50]);

// 6. Compartido sin reparto: se trata como general.
$sinReparto = $gasto([
    'concepto' => 'T47 Compartido sin reparto',
    'subtotal' => 300, 'iva' => 0, 'compartido' => 1,
]);

/* --- Comprobaciones ------------------------------------------------------- */

echo "1. Simple con los dos negocios\n";
$r = AnalisisFinanciero::resumen($anio, $mes, [1, 2], 'venta', false, true, 'simple', false);
$conceptos = array_column($r['gastos']['filas'], 'concepto');
$prueba('incluye el gasto general pendiente', in_array('T47 Gastos generales pendientes', $conceptos, true), implode(' | ', $conceptos));
$prueba('incluye el gasto de proveedor sin pedido', in_array('T47 Gasto de proveedor sin pedido', $conceptos, true), implode(' | ', $conceptos));
$prueba('incluye el gasto compartido completo (50 + 50)', in_array('T47 Gasto compartido', $conceptos, true));
$prueba('incluye el compartido sin reparto como general', in_array('T47 Compartido sin reparto', $conceptos, true));
$prueba('NO incluye el gasto ligado al pedido', !in_array('T47 Gasto ligado al pedido', $conceptos, true), implode(' | ', $conceptos));
$prueba('NO incluye el gasto pagado en Simple', !in_array('T47 Gasto general pagado', $conceptos, true));
$esperado = 1000 + 160 + 500 + 80 + 1000 + 300;
$prueba('total pendiente = 3,040', abs((float) $r['gastos']['con_iva'] - $esperado) < 0.01, (string) $r['gastos']['con_iva']);

echo "2. Simple con un solo negocio\n";
$r1 = AnalisisFinanciero::resumen($anio, $mes, [1], 'venta', false, true, 'simple', false);
$prueba('el general entra completo', abs((float) $r1['gastos']['con_iva'] - (1000 + 160 + 500 + 80 + 500 + 300)) < 0.01, (string) $r1['gastos']['con_iva']);
$compartido1 = array_values(array_filter($r1['gastos']['filas'], static fn ($f) => $f['concepto'] === 'T47 Gasto compartido'));
$prueba('el compartido entra al 50 %', $compartido1 && abs((float) $compartido1[0]['con_iva'] - 500) < 0.01,
    $compartido1 ? (string) $compartido1[0]['con_iva'] : 'no aparece');

echo "3. Detallado\n";
$d = AnalisisFinanciero::resumen($anio, $mes, [1, 2], 'venta', false, true, 'detallado', false);
$pagados = array_column($d['gastos_pagados']['filas'], 'concepto');
$prueba('el gasto general pagado aparece en gastos pagados', in_array('T47 Gasto general pagado', $pagados, true), implode(' | ', $pagados));
$prueba('gastos pagados = 928', abs((float) $d['gastos_pagados']['con_iva'] - 928) < 0.01, (string) $d['gastos_pagados']['con_iva']);
$prueba('los pendientes no se mezclan con los pagados', !in_array('T47 Gastos generales pendientes', $pagados, true));
$prueba('resultado = 0 ventas - 928 pagados - 3,040 pendientes', abs((float) $d['balance']['resultado'] - (0 - 928 - 3040)) < 0.01, (string) $d['balance']['resultado']);

echo "4. Sin doble conteo del pedido\n";
$p = AnalisisFinanciero::proveedoresPendientes([1, 2], 'venta');
$filaPedido = array_values(array_filter($p['filas'], static fn ($f) => $f['folio'] === 'T47-OC-1'));
$prueba('el pedido ligado sigue en proveedores por 2,320', $filaPedido && abs((float) $filaPedido[0]['con_iva'] - 2320) < 0.01,
    $filaPedido ? (string) $filaPedido[0]['con_iva'] : 'no aparece');
$prueba('el gasto ligado no aparece en los gastos pendientes', !in_array('T47 Gasto ligado al pedido', $conceptos, true));

$limpiar();
echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
