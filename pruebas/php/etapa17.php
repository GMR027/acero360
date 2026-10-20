<?php
/**
 * pruebas/php/etapa17.php — Papelera (enviar, restaurar, purgar a los 90 días) y UUID fiscal único en la base.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa17.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Cotizaciones, Papelera, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) {
    $db->query("DELETE FROM $t");
}
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones');
$db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones'); $db->query('DELETE FROM papelera');
$db->query('UPDATE productos SET stock = 100 WHERE id = 36');

echo "== Papelera: venta ==\n";
$ventaId = (function () use ($db, $cliente, $usuario, $hoy) {
    $folio = Ventas::siguienteFolio();
    $db->query("INSERT INTO ventas (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total)
                VALUES ('$folio', 1, $cliente, $usuario, '$hoy', 'registrada', 100, 1, 16, 116)");
    $id = (int) $db->insert_id;
    $db->query("INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($id, 36, 2, 50, 100)");
    \App\Inventario::descontar(Ventas::detalles($id), 0, $usuario, 'prueba', $id);
    \App\Contabilidad::sincronizarIngreso($id, $usuario);
    return $id;
})();
$folioVenta = $uno("SELECT folio FROM ventas WHERE id = $ventaId");
$stockAntes = (int) $uno('SELECT stock FROM productos WHERE id = 36');
$prueba('la venta descontó 2 piezas', $stockAntes === 98, (string) $stockAntes);

$prueba('eliminar la venta funciona', Ventas::eliminar($ventaId, $usuario), implode(' ', Ventas::getErrores()));
$prueba('la venta ya no está en ventas', (int) $uno("SELECT COUNT(*) FROM ventas WHERE id = $ventaId") === 0);
$prueba('el stock volvió a 100', (int) $uno('SELECT stock FROM productos WHERE id = 36') === 100);
$prueba('quedó una copia en la papelera', (int) $uno("SELECT COUNT(*) FROM papelera WHERE tipo = 'venta' AND referencia_id = $ventaId") === 1);
$item = $db->query("SELECT * FROM papelera WHERE tipo = 'venta'")->fetch_assoc();
$prueba('vence en ~90 días', (int) $uno("SELECT DATEDIFF(vence_en, NOW()) FROM papelera WHERE id = {$item['id']}") >= 89);
$prueba('la copia conserva el estado original (registrada)', str_contains($item['datos'], '"estado":"registrada"'));

$prueba('restaurar la venta funciona', Papelera::restaurar((int) $item['id'], $usuario), implode(' ', Papelera::getErrores()));
$prueba('la venta volvió con su folio', $uno("SELECT folio FROM ventas WHERE id = $ventaId") === $folioVenta);
$prueba('con su renglón', (int) $uno("SELECT COUNT(*) FROM venta_detalles WHERE venta_id = $ventaId") === 1);
$prueba('el stock se descontó otra vez (98)', (int) $uno('SELECT stock FROM productos WHERE id = 36') === 98);
$prueba('tiene ingreso contable vigente', (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $ventaId AND estado = 'vigente'") === 1);
$prueba('la papelera quedó vacía', (int) $uno('SELECT COUNT(*) FROM papelera') === 0);

echo "== Papelera: folio ocupado ==\n";
Ventas::eliminar($ventaId, $usuario);
$otra = $db->query("INSERT INTO ventas (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total)
                    VALUES ('$folioVenta', 1, $cliente, $usuario, '$hoy', 'cancelada', 1, 0, 0, 1)");
$item = $db->query("SELECT id FROM papelera")->fetch_assoc();
$prueba('restaura con folio nuevo si el suyo lo usa otra', Papelera::restaurar((int) $item['id'], $usuario), implode(' ', Papelera::getErrores()));
$prueba('el folio restaurado es distinto', $uno("SELECT folio FROM ventas WHERE id = $ventaId") !== $folioVenta);

echo "== Papelera: cotización ==\n";
$folioC = Cotizaciones::siguienteFolio();
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total)
            VALUES ('$folioC', 1, $cliente, $usuario, '$hoy', 'pendiente', 100, 0, 0, 100)");
$cotId = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($cotId, 36, 1, 100, 100)");
$prueba('eliminar la cotización funciona', Cotizaciones::eliminar($cotId), implode(' ', Cotizaciones::getErrores()));
$item = $db->query("SELECT id FROM papelera WHERE tipo = 'cotizacion'")->fetch_assoc();
$prueba('la cotización está en la papelera', (bool) $item);
$prueba('restaurar la cotización funciona', Papelera::restaurar((int) $item['id'], $usuario), implode(' ', Papelera::getErrores()));
$prueba('volvió con su renglón', (int) $uno("SELECT COUNT(*) FROM cotizacion_detalles WHERE cotizacion_id = $cotId") === 1);

echo "== Papelera: purga a los 90 días ==\n";
Cotizaciones::eliminar($cotId);
$db->query("UPDATE papelera SET vence_en = DATE_SUB(NOW(), INTERVAL 1 DAY)");
$prueba('se borra lo vencido', Papelera::purgarVencidos() === 1);
$prueba('la papelera queda vacía', (int) $uno('SELECT COUNT(*) FROM papelera') === 0);

echo "== UUID fiscal único ==\n";
$uuid = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
$ing = (int) $uno("SELECT id FROM ingresos LIMIT 1");
$db->query("UPDATE ingresos SET uuid_fiscal = '$uuid' WHERE id = $ing");
$db->query("CREATE TEMPORARY TABLE copia_ingreso AS SELECT * FROM ingresos WHERE id = $ing");
$db->query("UPDATE copia_ingreso SET id = id + 100000, venta_id = NULL, folio = 'DUP-1'");
try {
    $db->query("INSERT INTO ingresos SELECT * FROM copia_ingreso");
    $prueba('la base rechaza un UUID repetido en ingresos', false);
} catch (mysqli_sql_exception $e) {
    $prueba('la base rechaza un UUID repetido en ingresos', $e->getCode() === 1062, $e->getMessage());
}

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
