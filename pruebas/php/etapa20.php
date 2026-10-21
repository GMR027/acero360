<?php
/**
 * pruebas/php/etapa20.php — Compra de semiterminados desde el catálogo del proveedor: nombre, código y costo
 * recuperados, importes, copia histórica del costo y permisos del proveedor.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa20.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{OrdenesCompra as OC, ProductosProveedor as PP};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$errores = fn () => implode('|', array_merge(OC::getErrores(), PP::getErrores()));

$limpiar = function () use ($db) {
    $ids = array_column($db->query("SELECT id FROM ordenes_compra WHERE referencia LIKE 'T20-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($ids) {
        $l = implode(',', array_map('intval', $ids));
        $db->query("DELETE FROM movimientos_por_terminar WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN ($l))");
        $db->query("DELETE FROM recepciones WHERE orden_id IN ($l)");
        $db->query("DELETE FROM ordenes_compra WHERE id IN ($l)");
    }
    $db->query("DELETE FROM proveedor_productos WHERE nombre LIKE 'T20 %'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T20 %'");
    $db->query("DELETE FROM productos WHERE sku LIKE 'T20-%'");
};
$limpiar();

$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T20 Agustín', 1), ('T20 Otro', 1)");
$prov = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T20 Agustín'");
$otro = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T20 Otro'");
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$db->query("INSERT INTO productos (empresa_id, nombre, sku, precio, costo_actual) VALUES ($empresa, 'T20 Canastilla propia', 'T20-1', 300, 0), ($empresa, 'T20 Reja propia', 'T20-2', 500, 0)");
$propia1 = (int) $uno("SELECT id FROM productos WHERE sku = 'T20-1'");
$propia2 = (int) $uno("SELECT id FROM productos WHERE sku = 'T20-2'");
$nuevo = function (int $proveedor, string $nombre, string $codigo, string $costo) use ($usuario) {
    PP::crear(['proveedor_id' => $proveedor, 'nombre' => $nombre, 'codigo' => $codigo, 'costo_unitario' => $costo], $usuario);
    return PP::$ultimoId;
};
$armazon = $nuevo($prov, 'T20 Armazón', 'AG-1', '120.50');
$base = $nuevo($prov, 'T20 Base', 'AG-2', '80.00');
$inactivo = $nuevo($prov, 'T20 Descontinuado', 'AG-3', '10.00');
PP::cambiarEstado($inactivo, false, $usuario);
$ajeno = $nuevo($otro, 'T20 Ajeno', 'OT-1', '5.00');
PP::relacionar($armazon, $propia1, $usuario);

echo "Solo se ofrece el catálogo del proveedor elegido, activo\n";
$ids = array_map('intval', array_column(PP::paraSelector($prov), 'id'));
$prueba('el selector ofrece los activos de ese proveedor', in_array($armazon, $ids, true) && in_array($base, $ids, true));
$prueba('no ofrece los inactivos ni los de otro proveedor', !in_array($inactivo, $ids, true) && !in_array($ajeno, $ids, true));

echo "Recuperación automática de nombre, código y costo\n";
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-A'],
    [['proveedor_producto_id' => $armazon, 'cantidad' => '10', 'costo' => ''],
     ['proveedor_producto_id' => $base, 'cantidad' => '3', 'costo' => '', 'descripcion' => 'texto que debe ignorarse']], $usuario);
$A = OC::$ultimoId;
$prueba('crear la compra sin escribir ningún costo', $ok && $A > 0, $errores());
$p = OC::partidas($A);
$prueba('toma nombre, código y costo del catálogo', $p[0]['descripcion'] === 'T20 Armazón' && $p[0]['codigo'] === 'AG-1' && $p[0]['costo_esperado'] === '120.50' && $p[0]['costo_catalogo'] === '120.50');
$prueba('el nombre viene del catálogo, no de lo que mande el formulario', $p[1]['descripcion'] === 'T20 Base' && $p[1]['codigo'] === 'AG-2');
$prueba('importe de partida = cantidad × costo (10 × 120.50 y 3 × 80)', $p[0]['importe_esperado'] === '1205.00' && $p[1]['importe_esperado'] === '240.00');
$prueba('total de la compra = suma de importes (1445.00)', OC::buscarID($A)['total_esperado'] === '1445.00');
$prueba('la partida queda ligada al producto del catálogo', (int) $p[0]['proveedor_producto_id'] === $armazon);
$prueba('se asocia sola al único producto propio relacionado', (int) $p[0]['producto_id'] === $propia1 && $p[1]['producto_id'] === null);

echo "Totales con IVA\n";
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-B', 'iva' => '231.20'],
    [['proveedor_producto_id' => $armazon, 'cantidad' => '10', 'costo' => '']], $usuario);
$B = OC::$ultimoId;
$res = OC::resumen($B);
$prueba('total = subtotal + IVA capturado (1205 + 231.20)', $ok && $res['subtotal'] == 1205.0 && $res['iva'] == 231.2 && $res['total'] == 1436.2, $errores());

echo "Copia histórica del costo\n";
PP::actualizar($armazon, ['proveedor_id' => $prov, 'nombre' => 'T20 Armazón', 'codigo' => 'AG-1', 'costo_unitario' => '150', 'activo' => 1], $usuario);
$p = OC::partidas($A);
$prueba('cambiar el costo del catálogo no altera la compra anterior', $p[0]['costo_esperado'] === '120.50' && $p[0]['importe_esperado'] === '1205.00' && OC::buscarID($A)['total_esperado'] === '1445.00');
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-C'],
    [['proveedor_producto_id' => $armazon, 'cantidad' => '2', 'costo' => '']], $usuario);
$C = OC::$ultimoId;
$prueba('una compra nueva toma el costo vigente (150)', $ok && OC::partidas($C)[0]['costo_esperado'] === '150.00');
$lineas = OC::lineasDeOrden($A);
$lineas[0]['cantidad'] = '12';
$ok = OC::actualizar($A, ['id' => $A, 'tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-A'], $lineas, $usuario);
$p = OC::partidas($A);
$prueba('editar la cantidad conserva el costo con el que se compró (no vuelve a leer el catálogo)', $ok && $p[0]['costo_esperado'] === '120.50' && $p[0]['costo_catalogo'] === '120.50' && $p[0]['importe_esperado'] === '1446.00', $errores());

echo "Costo modificado a mano\n";
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-D'],
    [['proveedor_producto_id' => $base, 'cantidad' => '5', 'costo' => '75.00']], $usuario);
$D = OC::$ultimoId;
$p = OC::partidas($D)[0];
$prueba('se respeta el precio final capturado (75) y se conserva el del catálogo (80)', $ok && $p['costo_esperado'] === '75.00' && $p['costo_catalogo'] === '80.00' && $p['importe_esperado'] === '375.00', $errores());
$prueba('la partida queda marcada como costo modificado', $p['costo_modificado'] === true);
$prueba('queda en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad='orden_compra' AND entidad_id=$D AND accion='costo_modificado'") === 1);
$prueba('una partida con el costo del catálogo no se marca', OC::partidas($A)[0]['costo_modificado'] === false);
$prueba('un costo manual inválido se rechaza', !OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-X'],
    [['proveedor_producto_id' => $base, 'cantidad' => '1', 'costo' => '-3']], $usuario));
OC::getErrores();

echo "Reglas del proveedor y del estado\n";
$prueba('un producto de otro proveedor se rechaza', !OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-X'],
    [['proveedor_producto_id' => $ajeno, 'cantidad' => '1', 'costo' => '']], $usuario));
$prueba('el aviso lo explica', str_contains($errores(), 'no es de este proveedor'));
$prueba('un producto inactivo no se acepta en compras nuevas', !OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-X'],
    [['proveedor_producto_id' => $inactivo, 'cantidad' => '1', 'costo' => '']], $usuario));
$prueba('el aviso dice que está inactivo', str_contains($errores(), 'inactivo'));
$ok = OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-E'],
    [['proveedor_producto_id' => $base, 'cantidad' => '1', 'costo' => '']], $usuario);
$E = OC::$ultimoId;
PP::cambiarEstado($base, false, $usuario);
$lineas = OC::lineasDeOrden($E);
$lineas[0]['cantidad'] = '4';
$prueba('si se desactiva después, la compra existente se puede corregir', $ok && OC::actualizar($E, ['id' => $E, 'tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-E'], $lineas, $usuario)
    && OC::partidas($E)[0]['importe_esperado'] === '320.00', $errores());
$prueba('y el historial conserva nombre y código del producto inactivo', OC::partidas($E)[0]['descripcion'] === 'T20 Base' && OC::partidas($E)[0]['codigo'] === 'AG-2');
$lineas = OC::lineasDeOrden($E);
$prueba('al cambiar de proveedor los productos del anterior ya no valen', !OC::actualizar($E, ['id' => $E, 'tipo' => 'semiterminado', 'proveedor_id' => $otro, 'fecha_solicitud' => $hoy], $lineas, $usuario));
OC::getErrores();
$prueba('el catálogo no se usa en compras de insumos', !OC::crear(['tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-X'],
    [['proveedor_producto_id' => $armazon, 'descripcion' => 'x', 'cantidad' => '1', 'costo' => '1', 'controla' => 0]], $usuario));
OC::getErrores();
$prueba('una línea fuera del catálogo sigue siendo posible (nombre y costo a mano)', OC::crear(['tipo' => 'semiterminado', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T20-F'],
    [['proveedor_producto_id' => 0, 'descripcion' => 'Pieza especial', 'cantidad' => '1', 'costo' => '9.99']], $usuario), $errores());
$prueba('esa línea no tiene referencia al catálogo', OC::partidas(OC::$ultimoId)[0]['proveedor_producto_id'] === null);

echo "Recepción desde el catálogo\n";
$P = OC::partidas($D);
$prueba('al relacionar el producto propio, ya se recibe', OC::relacionarProducto((int) $P[0]['id'], $propia2, $usuario)
    && OC::recibir($D, $hoy, [(int) $P[0]['id'] => ['cantidad' => 2, 'costo' => $P[0]['costo_esperado']]], bin2hex(random_bytes(16)), false, '', $usuario), $errores());
$pp = OC::partidas($D)[0];
$prueba('lo recibido conserva el costo histórico (75) aunque el catálogo diga 80', $pp['costo_final'] === 75.0 && $pp['recibida'] === 2 && $pp['pendiente'] === 3);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
