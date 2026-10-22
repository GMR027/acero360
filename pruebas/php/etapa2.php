<?php
/**
 * pruebas/php/etapa2.php — pruebas de la Etapa 2 (órdenes de compra, inventario,
 * stock negativo, auditoría). Corre contra una BD de PRUEBA, nunca la real:
 *
 *   php scripts/migrar.php cotizaciones_test
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa2.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';

use App\{OrdenesCompra, Inventario, Configuracion};

$fallos = 0;
$prueba = function (string $nombre, bool $ok, string $detalle = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $nombre . ($ok || $detalle === '' ? '' : " → $detalle") . "\n";
    $fallos += $ok ? 0 : 1;
};
$stock = fn (int $id) => (int) $db->query("SELECT stock FROM productos WHERE id = $id")->fetch_row()[0];
$porTerminar = fn (int $id) => (int) $db->query("SELECT stock_por_terminar FROM productos WHERE id = $id")->fetch_row()[0];
$usuario = (int) $db->query('SELECT id FROM usuarios LIMIT 1')->fetch_row()[0];
// Estado limpio (la prueba puede correrse varias veces)
$db->query('DELETE FROM movimientos_inventario WHERE recepcion_id IS NOT NULL');
$db->query('DELETE FROM movimientos_por_terminar');
$db->query('DELETE FROM movimientos_material');
$db->query('DELETE FROM pagos_compra');
$db->query('DELETE FROM recepciones');
$db->query('DELETE FROM ordenes_compra');
$db->query("UPDATE productos SET stock = 10, stock_por_terminar = 0 WHERE id IN (36, 37)");
$proveedorId = (int) $db->query("SELECT id FROM proveedores WHERE activo = 1 ORDER BY id LIMIT 1")->fetch_row()[0];
$hoy = date('Y-m-d');
$token = fn () => bin2hex(random_bytes(16));

echo "Órdenes de compra\n";
$ok = OrdenesCompra::crear(
    ['proveedor_id' => $proveedorId, 'fecha_solicitud' => $hoy, 'observaciones' => ''],
    [['producto_id' => 36, 'cantidad' => 5, 'costo' => '100.10'], ['producto_id' => 37, 'cantidad' => 5, 'costo' => '90.00']],
    $usuario
);
$id = OrdenesCompra::$ultimoId;
$prueba('crear orden', $ok && $id > 0, implode('|', OrdenesCompra::getErrores()));
$prueba('crear NO modifica inventario', $stock(36) === 10 && $stock(37) === 10);
$orden = OrdenesCompra::buscarID($id);
$prueba('folio OC-AAAA-NNNN', (bool) preg_match('/^OC-\d{4}-\d{4}$/', $orden['folio']), $orden['folio']);
$prueba('total esperado 950.50', $orden['total_esperado'] === '950.50', $orden['total_esperado']);

$p = OrdenesCompra::partidas($id);
$prueba('rechaza orden sin partidas', !OrdenesCompra::crear(['proveedor_id' => $proveedorId, 'fecha_solicitud' => $hoy], [], $usuario));
OrdenesCompra::getErrores();

// Recepción parcial: 5 del 36 (costo 105) y 2 del 37
$t = $token();
$r = OrdenesCompra::recibir($id, $hoy, [(int) $p[0]['id'] => ['cantidad' => 5, 'costo' => '105.00'], (int) $p[1]['id'] => ['cantidad' => 2, 'costo' => '90.00']], $t, false, '', $usuario);
$prueba('recepción parcial', $r, implode('|', OrdenesCompra::getErrores()));
$prueba('semiterminado: la existencia vendible NO cambia', $stock(36) === 10 && $stock(37) === 10, $stock(36) . '/' . $stock(37));
$prueba('por terminar suma solo lo recibido', $porTerminar(36) === 5 && $porTerminar(37) === 2, $porTerminar(36) . '/' . $porTerminar(37));
$prueba('estado parcial', OrdenesCompra::buscarID($id)['estado'] === 'parcial');

$p = OrdenesCompra::partidas($id);
$prueba('faltan 3 de la partida 2', $p[1]['pendiente'] === 3);
$prueba('costo final 105.00 y diferencia +24.50', $p[0]['costo_final'] === 105.0 && abs($p[0]['diferencia'] - 24.5) < 0.001, json_encode([$p[0]['costo_final'], $p[0]['diferencia']]));

// Reenvío del mismo formulario (mismo token): no duplica
$r = OrdenesCompra::recibir($id, $hoy, [(int) $p[1]['id'] => ['cantidad' => 3, 'costo' => '90.00']], $t, false, '', $usuario);
$prueba('mismo token no duplica entrada', !$r && $porTerminar(37) === 2 && $stock(37) === 10);
OrdenesCompra::getErrores();

// Cambiar costo_actual no altera el histórico
$db->query('UPDATE productos SET costo_actual = 999 WHERE id = 36');
$prueba('costo histórico intacto tras cambiar costo_actual', OrdenesCompra::partidas($id)[0]['costo_final'] === 105.0);

// Cerrar con faltantes, luego no admite recepciones
$prueba('cerrar con faltantes', OrdenesCompra::cerrar($id, 'Proveedor no surtió', $usuario));
$r = OrdenesCompra::recibir($id, $hoy, [(int) $p[1]['id'] => ['cantidad' => 1, 'costo' => '90.00']], $token(), false, '', $usuario);
$prueba('orden cerrada rechaza recepción', !$r && $porTerminar(37) === 2);
OrdenesCompra::getErrores();
$prueba('reabrir exige motivo', !OrdenesCompra::reabrir($id, '', $usuario));
OrdenesCompra::getErrores();
$prueba('reabrir con motivo', OrdenesCompra::reabrir($id, 'Llegó el faltante', $usuario) && OrdenesCompra::buscarID($id)['estado'] === 'parcial');
$a = (int) $db->query("SELECT COUNT(*) FROM auditoria WHERE entidad='orden_compra' AND entidad_id=$id AND accion IN ('cerrar','reabrir')")->fetch_row()[0];
$prueba('cierre y reapertura auditados', $a === 2, (string) $a);

$r = OrdenesCompra::recibir($id, $hoy, [(int) $p[1]['id'] => ['cantidad' => 3, 'costo' => '90.00']], $token(), true, '', $usuario);
$prueba('segunda recepción completa la orden', $r && OrdenesCompra::buscarID($id)['estado'] === 'completa');
$prueba('semiterminado: actualiza_costo NO toca el costo interno del producto propio', $db->query('SELECT costo_actual FROM productos WHERE id=37')->fetch_row()[0] !== '90.00');
$mov = (int) $db->query("SELECT COUNT(*) FROM movimientos_por_terminar WHERE recepcion_id IS NOT NULL AND tipo='entrada'")->fetch_row()[0];
$prueba('movimientos por terminar con su recepción', $mov === 3, (string) $mov);
$prueba('ninguna recepción tocó la existencia vendible', (int) $db->query("SELECT COUNT(*) FROM movimientos_inventario WHERE recepcion_id IS NOT NULL")->fetch_row()[0] === 0);
$prueba('historial de 2 recepciones', count(OrdenesCompra::recepciones($id)) === 2);

echo "Stock negativo (configuración)\n";
$db->query('UPDATE productos SET stock = 1 WHERE id = 38');
$detalles = [['producto_id' => 38, 'cantidad' => 3]];
$db->begin_transaction();
$permitido = Inventario::descontar($detalles, 0, $usuario, 'prueba', 0);
$db->rollback();
$prueba('permite negativo por defecto', $permitido);
Configuracion::guardar('permitir_stock_negativo', '0', $usuario);
$db->begin_transaction();
$bloqueado = !Inventario::descontar($detalles, 0, $usuario, 'prueba', 0);
$db->rollback();
Inventario::getErrores();
$prueba('bloquea cuando la configuración lo prohíbe', $bloqueado && $stock(38) === 1);
Configuracion::guardar('permitir_stock_negativo', '1', $usuario);

echo "Catálogo\n";
$dupe = false;
try { $db->query("UPDATE productos SET sku = 'RA-3' WHERE id = 36"); } catch (mysqli_sql_exception $e) { $dupe = true; }
$prueba('SKU único', $dupe);
$prueba('producto_empresa respaldó a todos', (int) $db->query('SELECT COUNT(*) FROM producto_empresa')->fetch_row()[0] >= 16);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
