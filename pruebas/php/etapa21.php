<?php
/**
 * pruebas/php/etapa21.php — IVA de la compra: base gravable por partida, tasa explícita, redondeo y estados de factura.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa21.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\OrdenesCompra as OC;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$limpiar = function () use ($db) {
    $db->query("DELETE FROM ordenes_compra WHERE referencia LIKE 'T21-%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T21 %'");
};
$limpiar();
$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T21 Proveedor', 1)");
$prov = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T21 Proveedor'");

$l = fn (string $d, $cant, string $costo, int $iva) => ['descripcion' => $d, 'cantidad' => (string) $cant, 'costo' => $costo, 'unidad' => 'pza', 'controla' => 0, 'aplica_iva' => $iva, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0];
$cab = fn (string $ref, array $extra = []) => ['tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => $ref] + $extra;

echo "Cálculo\n";
$prueba('solo las partidas gravadas suman a la base', OC::ivaDe([$l('a', 2, '100.00', 1), $l('b', 1, '500.00', 0)], '16') === '32.00');
$prueba('sin partidas gravadas el IVA es 0', OC::ivaDe([$l('a', 1, '100.00', 0)], '16') === '0.00');
$prueba('la tasa no está fija en 16 (8 %)', OC::ivaDe([$l('a', 1, '100.00', 1)], '8') === '8.00');
$prueba('redondeo al centavo (mitad hacia arriba)', OC::ivaDe([$l('a', 1, '0.03', 1)], '16') === '0.00' && OC::ivaDe([$l('a', 1, '0.05', 1)], '16') === '0.01' && OC::ivaDe([$l('a', 3, '33.33', 1)], '16') === '16.00');
$t = OC::tasaIvaVigente($hoy);
$prueba('la tasa viene del catálogo fiscal activo (o es null si no hay)', $t === null || is_numeric($t));
$prueba('sin catálogo activo para el ejercicio no se supone tasa', OC::tasaIvaVigente('1999-01-01') === null);

echo "Guardado\n";
$ok = OC::crear($cab('T21-A', ['iva' => '', 'iva_tasa' => '16']), [$l('gravada', 2, '100.00', 1), $l('exenta', 1, '500.00', 0)], $usuario);
$a = OC::buscarID(OC::$ultimoId);
$prueba('IVA vacío + tasa: se calcula solo de lo gravado', $ok && $a['iva'] === '32.00' && $a['iva_tasa'] === '16.00' && OC::resumen((int) $a['id'])['total'] == 732.0);
$marcas = array_column($db->query("SELECT aplica_iva FROM orden_compra_partidas WHERE orden_id = {$a['id']} ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'aplica_iva');
$prueba('cada partida conserva si es gravada', $marcas === ['1', '0']);
$prueba('lineasDeOrden devuelve la marca', array_column(OC::lineasDeOrden((int) $a['id']), 'aplica_iva') === [1, 0]);

$ok = OC::crear($cab('T21-B', ['iva' => '30.00', 'iva_tasa' => '16']), [$l('x', 1, '200.00', 1)], $usuario);
$b = OC::buscarID(OC::$ultimoId);
$prueba('un IVA capturado (el de la factura) se respeta', $ok && $b['iva'] === '30.00');

$ok = OC::crear($cab('T21-C', ['iva' => '', 'iva_tasa' => '']), [$l('x', 1, '200.00', 1)], $usuario);
$c = OC::buscarID(OC::$ultimoId);
$prueba('sin tasa ni IVA: «no especificado» (null), no se supone 16', $ok && $c['iva'] === null && $c['iva_tasa'] === null);

echo "Estados de facturación (que la recepción no los cambia se prueba en etapa18)\n";
$ok = OC::crear($cab('T21-D', ['iva' => '16.00', 'estado_cfdi' => 'pendiente_cfdi']), [$l('mat', 5, '20.00', 1) + [], ], $usuario);
$d = (int) OC::$ultimoId;
$antes = OC::resumen($d);
$prueba('estado inicial: CFDI pendiente y pago pendiente', $antes['estado_cfdi'] === 'pendiente_cfdi' && $antes['estado_pago'] === 'pendiente');
$sinFactura = OC::crear($cab('T21-E', ['estado_cfdi' => 'no_facturada']), [$l('x', 1, '10.00', 0)], $usuario);
$prueba('«Sin factura» es un estado válido', $sinFactura && OC::buscarID(OC::$ultimoId)['estado_cfdi'] === 'no_facturada');
$prueba('«Con CFDI» no se puede fijar a mano', !OC::crear($cab('T21-F', ['estado_cfdi' => 'con_cfdi']), [$l('x', 1, '10.00', 0)], $usuario));
OC::getErrores();

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
