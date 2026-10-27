<?php
/**
 * pruebas/php/etapa46.php — Resumen financiero del proveedor con sus tres fuentes.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa46.php
 *
 * Comprueba que Proveedores::resumenFinanciero() suma compras nuevas, entregas
 * anteriores y gastos del proveedor, sin contar dos veces el gasto que ya está
 * ligado a una compra y sin contar compras canceladas.
 *
 * Los datos llevan «T46» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Proveedores;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios ORDER BY id LIMIT 1');
$contribuyente = (int) $uno('SELECT id FROM contribuyentes ORDER BY id LIMIT 1');
$hoy = date('Y-m-d');

/** Borra todo lo que crea esta etapa. */
$limpiar = function () use ($db) {
    $proveedores = array_column(
        $db->query("SELECT id FROM proveedores WHERE nombre = 'T46 Proveedor resumen'")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    if (!$proveedores) {
        return;
    }
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
    $db->query("DELETE FROM proveedor_entradas WHERE proveedor_id IN ($ids)");
    $db->query("DELETE FROM proveedores WHERE id IN ($ids)");
};
$limpiar();

/* --- Datos de la etapa ---------------------------------------------------- */
$db->query(
    "INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo)
     VALUES ('T46 Proveedor resumen', 'T46XAXX010101', 'Material', 1)"
);
$proveedor = (int) $db->insert_id;

/* 1) Compra nueva: $2,000 + $320 de IVA; con CFDI y un pago de $500. */
$db->query(
    "INSERT INTO ordenes_compra
        (folio, tipo, proveedor_id, fecha_solicitud, referencia, contribuyente_id,
         estado, total_esperado, estado_cfdi, iva, usuario_id)
     VALUES
        ('T46-OC-1', 'insumo', $proveedor, '$hoy', 'T46-COMPRA', $contribuyente,
         'completa', 2000.00, 'con_cfdi', 320.00, $usuario)"
);
$orden = (int) $db->insert_id;
$db->query(
    "INSERT INTO pagos_compra (orden_id, fecha, importe, metodo, usuario_id)
     VALUES ($orden, '$hoy', 500.00, 'Efectivo', $usuario)"
);

/* 2) Entrega anterior: $1,000 ya pagada. No tiene factura. */
$db->query(
    "INSERT INTO proveedor_entradas
        (proveedor_id, fecha, numero_pedido, categoria, monto, pagado, descripcion, foto, notas, usuario_id)
     VALUES
        ($proveedor, '$hoy', 'T46-ENT', 0, 1000.00, 1, 'Entrega T46', '', '', $usuario)"
);

/* 3) Gasto suelto del proveedor: $500, sin comprobante y pendiente de pago. */
$db->query(
    "INSERT INTO gastos
        (contribuyente_id, fecha, proveedor_id, beneficiario_tipo, beneficiario, concepto,
         naturaleza, subtotal, iva_tasa, iva, total, estado_pago, comprobante_fiscal, estado, usuario_id)
     VALUES
        ($contribuyente, '$hoy', $proveedor, 'proveedor', 'T46 Proveedor resumen', 'Gasto T46',
         'gasto', 500.00, 0.00, 0.00, 500.00, 'pendiente', 0, 'vigente', $usuario)"
);
$gasto = (int) $db->insert_id;

/* 4) Gasto YA ligado a la compra: no debe sumarse otra vez. */
$db->query(
    "INSERT INTO gastos
        (contribuyente_id, fecha, proveedor_id, beneficiario_tipo, beneficiario, concepto,
         naturaleza, subtotal, iva_tasa, iva, total, estado_pago, comprobante_fiscal, estado, usuario_id)
     VALUES
        ($contribuyente, '$hoy', $proveedor, 'proveedor', 'T46 Proveedor resumen', 'Gasto de la compra T46',
         'compra', 2000.00, 16.00, 320.00, 2320.00, 'pendiente', 1, 'vigente', $usuario)"
);
$gastoLigado = (int) $db->insert_id;
$db->query("UPDATE ordenes_compra SET gasto_id = $gastoLigado WHERE id = $orden");

/* 5) Compra cancelada: no debe contar. */
$db->query(
    "INSERT INTO ordenes_compra
        (folio, tipo, proveedor_id, fecha_solicitud, referencia, contribuyente_id,
         estado, total_esperado, estado_cfdi, iva, usuario_id)
     VALUES
        ('T46-OC-2', 'insumo', $proveedor, '$hoy', 'T46-CANCELADA', $contribuyente,
         'cancelada', 999.00, 'pendiente_cfdi', 0.00, $usuario)"
);

/* --- Comprobaciones ------------------------------------------------------- */
echo "1. Resumen con las tres fuentes\n";
$r = Proveedores::resumenFinanciero($proveedor);
$prueba('cuenta 1 compra, 1 entrega y 1 gasto', $r['compras'] === 1 && $r['entradas'] === 1 && $r['gastos'] === 1 && $r['registros'] === 3,
    json_encode($r));
$prueba('comprado = 1,000 + 2,320 + 500 = 3,820', $r['comprado'] === 3820.0, (string) $r['comprado']);
$prueba('facturado = sólo la compra con CFDI (2,320)', $r['facturado'] === 2320.0, (string) $r['facturado']);
$prueba('pagado = entrega pagada (1,000) + pago de la compra (500)', $r['pagado'] === 1500.0, (string) $r['pagado']);
$prueba('saldo = comprado menos pagado (2,320)', $r['saldo'] === 2320.0, (string) $r['saldo']);
$prueba('pendiente de documentar = gasto sin comprobante (500)', $r['pendiente_documentar'] === 500.0, (string) $r['pendiente_documentar']);

echo "2. Sin datos\n";
$vacio = Proveedores::resumenFinanciero(0);
$prueba('proveedor sin registros devuelve ceros', $vacio['registros'] === 0 && $vacio['comprado'] === 0.0 && $vacio['saldo'] === 0.0,
    json_encode($vacio));

$limpiar();
echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
