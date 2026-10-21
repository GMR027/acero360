<?php
/**
 * pruebas/php/etapa22.php — Escenarios obligatorios de la ampliación de compras (alta de semiterminados, costo automático,
 * varias partidas, historial, recepción parcial, costos diferenciados, saldo) con verificación de persistencia en la BD.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa22.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{OrdenesCompra as OC, PagosCompra, ProductosProveedor as PP};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$token = fn () => bin2hex(random_bytes(16));
$errores = fn () => implode('|', array_merge(OC::getErrores(), PP::getErrores(), PagosCompra::getErrores()));

$limpiar = function () use ($db) {
    $ids = array_column($db->query("SELECT id FROM ordenes_compra WHERE referencia LIKE 'T22-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($ids) {
        $l = implode(',', array_map('intval', $ids));
        $db->query("DELETE FROM movimientos_por_terminar WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN ($l))");
        $db->query("DELETE FROM pagos_compra WHERE orden_id IN ($l)");
        $db->query("DELETE FROM recepciones WHERE orden_id IN ($l)");
        $db->query("DELETE FROM ordenes_compra WHERE id IN ($l)");
    }
    $db->query("DELETE FROM proveedor_productos WHERE nombre LIKE 'T22 %'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T22 %'");
    $db->query("DELETE FROM productos WHERE sku LIKE 'T22-%'");
};
$limpiar();
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$db->query("INSERT INTO productos (empresa_id, nombre, sku, precio, costo_actual) VALUES ($empresa, 'T22 Propio', 'T22-1', 900, 333.33)");
$propio = (int) $uno("SELECT id FROM productos WHERE sku = 'T22-1'");

echo "Prueba 1. Alta de semiterminados\n";
$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T22 Proveedor', 1)");
$prov = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T22 Proveedor'");
$ok1 = PP::crear(['proveedor_id' => $prov, 'nombre' => 'T22 Armazón', 'codigo' => 'T22-A', 'costo_unitario' => '120.50'], $usuario);
$a = PP::$ultimoId;
$ok2 = PP::crear(['proveedor_id' => $prov, 'nombre' => 'T22 Base', 'codigo' => 'T22-B', 'costo_unitario' => '80.00'], $usuario);
$b = PP::$ultimoId;
$prueba('se crean dos semiterminados del proveedor', $ok1 && $ok2, $errores());
$prueba('ambos están en su catálogo (BD)', (int) $uno("SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = $prov AND activo = 1") === 2);
PP::relacionar($a, $propio, $usuario);
PP::relacionar($b, $propio, $usuario);

echo "Prueba 2-3. Selección con costo automático y varias partidas\n";
$lin = fn (int $cat, $cant) => ['proveedor_producto_id' => $cat, 'producto_id' => 0, 'cantidad' => (string) $cant, 'costo' => '', 'descripcion' => '', 'unidad' => '', 'controla' => 1, 'aplica_iva' => 1, 'material_id' => 0, 'material_nuevo' => ''];
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T22-1', 'iva_tasa' => '16'], [$lin($a, 10), $lin($b, 3)], $usuario);
$id = (int) OC::$ultimoId;
$prueba('la compra se guarda', $ok, $errores());
// Segunda conexión independiente: lo guardado ya está confirmado en la BD (no depende de la sesión que lo creó).
$otra = new mysqli(getenv('DB_HOST') ?: 'localhost', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', 'cotizaciones_test', 3306);
$filas = $otra->query("SELECT codigo, descripcion, costo_esperado, importe_esperado FROM orden_compra_partidas WHERE orden_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$prueba('otra conexión ve las 2 partidas persistidas', count($filas) === 2 && $filas[0]['codigo'] === 'T22-A' && $filas[1]['importe_esperado'] === '240.00');
$otra->close();
$ps = OC::partidas($id);
$prueba('código, nombre y costo se recuperaron solos', $ps[0]['codigo'] === 'T22-A' && $ps[0]['descripcion'] === 'T22 Armazón' && (float) $ps[0]['costo_esperado'] === 120.5
    && $ps[1]['codigo'] === 'T22-B' && (float) $ps[1]['costo_esperado'] === 80.0);
$prueba('importes por partida (1205.00 y 240.00)', (float) $ps[0]['importe_esperado'] === 1205.0 && (float) $ps[1]['importe_esperado'] === 240.0);
$r = OC::resumen($id);
$prueba('subtotal 1445.00, IVA 231.20, total 1676.20', $r['subtotal'] == 1445.0 && $r['iva'] == 231.2 && $r['total'] == 1676.2, json_encode($r));
$prueba('el producto propio relacionado se asoció solo', (int) $ps[0]['producto_id'] === $propio);

echo "Prueba 4. Historial\n";
PP::actualizar($a, ['proveedor_id' => $prov, 'nombre' => 'T22 Armazón v2', 'codigo' => 'T22-A2', 'costo_unitario' => '999.00', 'activo' => 1], $usuario);
$ps = OC::partidas($id);
$prueba('la compra conserva nombre, código y costo originales', $ps[0]['descripcion'] === 'T22 Armazón' && $ps[0]['codigo'] === 'T22-A' && (float) $ps[0]['costo_esperado'] === 120.5 && OC::resumen($id)['total'] == 1676.2);

echo "Prueba 5-6. Recepción parcial y costos\n";
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T22-2', 'iva' => '0'], [$lin($b, 10)], $usuario);
$R = (int) OC::$ultimoId;
$pid = (int) OC::partidas($R)[0]['id'];
$prueba('la partida muestra el costo del proveedor (80), no el interno (333.33)', (float) OC::partidas($R)[0]['costo_esperado'] === 80.0 && (float) $uno("SELECT costo_actual FROM productos WHERE id = $propio") === 333.33);
$prueba('compra de 10, recibir 6', OC::recibir($R, $hoy, [$pid => ['cantidad' => 6, 'costo' => '80.00']], $token(), false, '', $usuario), $errores());
$p = OC::partidas($R)[0];
$prueba('6 recibidas y 4 pendientes', $p['recibida'] === 6 && $p['pendiente'] === 4 && OC::buscarID($R)['estado'] === 'parcial', $errores());
$t = $token();
$prueba('recibir las 4 restantes', OC::recibir($R, $hoy, [$pid => ['cantidad' => 4, 'costo' => '80.00']], $t, false, '', $usuario), $errores());
$p = OC::partidas($R)[0];
$prueba('partida completa: 10 recibidas, 0 pendientes, compra completa', $p['recibida'] === 10 && $p['pendiente'] === 0 && OC::buscarID($R)['estado'] === 'completa');
$prueba('reenviar la misma recepción no duplica', !OC::recibir($R, $hoy, [$pid => ['cantidad' => 4, 'costo' => '80.00']], $t, false, '', $usuario) && OC::partidas($R)[0]['recibida'] === 10);
OC::getErrores();
$prueba('el costo interno del producto propio no cambió', (float) $uno("SELECT costo_actual FROM productos WHERE id = $propio") === 333.33);

echo "Prueba 7. Saldo pendiente\n";
$s = fn () => OC::resumen($id);
$prueba('saldo inicial = total (1676.20); recibir no lo toca', $s()['saldo'] == 1676.2 && $s()['pagado'] == 0.0 && $s()['estado_pago'] === 'pendiente');
$prueba('pago parcial de 676.20', PagosCompra::registrar($id, $hoy, '676.20', 'Transferencia', 'R1', $token(), $usuario), $errores());
$prueba('saldo restante 1000.00 · parcial', $s()['saldo'] == 1000.0 && $s()['estado_pago'] === 'parcial');
$prueba('pago final de 1000.00', PagosCompra::registrar($id, $hoy, '1000.00', 'Efectivo', '', $token(), $usuario), $errores());
$prueba('saldo 0 · pagada', $s()['saldo'] == 0.0 && $s()['estado_pago'] === 'pagada');
$prueba('el saldo sale de los pagos de la BD', (float) $uno("SELECT SUM(importe) FROM pagos_compra WHERE orden_id = $id AND estado = 'vigente'") === 1676.2);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
