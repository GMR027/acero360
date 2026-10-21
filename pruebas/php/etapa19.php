<?php
/**
 * pruebas/php/etapa19.php — Catálogo de productos semiterminados por proveedor y su relación con productos propios.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa19.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\ProductosProveedor as PP;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$errores = fn () => implode('|', PP::getErrores());

$limpiar = function () use ($db) {
    $db->query("DELETE FROM proveedor_productos WHERE nombre LIKE 'T19 %'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T19 %'");
    $db->query("DELETE FROM productos WHERE sku LIKE 'T19-%'");
};
$limpiar();

$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T19 Agustín', 1), ('T19 Otro proveedor', 1), ('T19 Inactivo', 0)");
$pA = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T19 Agustín'");
$pB = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T19 Otro proveedor'");
$pI = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T19 Inactivo'");
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$db->query("INSERT INTO productos (empresa_id, nombre, sku, precio, costo_actual) VALUES ($empresa, 'T19 Canastilla propia', 'T19-1', 300, 0), ($empresa, 'T19 Reja propia', 'T19-2', 500, 0)");
$prodCanastilla = (int) $uno("SELECT id FROM productos WHERE sku = 'T19-1'");
$prodReja = (int) $uno("SELECT id FROM productos WHERE sku = 'T19-2'");

echo "Alta y consulta\n";
$ok = PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Armazón sin pintar', 'descripcion' => 'Acero calibre 14', 'codigo' => 'AG-100', 'costo_unitario' => '120.50', 'activo' => 1], $usuario);
$a1 = PP::$ultimoId;
$prueba('crear un semiterminado', $ok && $a1 > 0, $errores());
$r = PP::buscarID($a1);
$prueba('guarda proveedor, nombre, descripción, código, costo y estado', $r['proveedor_nombre'] === 'T19 Agustín' && $r['codigo'] === 'AG-100' && $r['costo_unitario'] === '120.50' && (int) $r['activo'] === 1 && $r['descripcion'] === 'Acero calibre 14');
$prueba('un proveedor puede tener varios', PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Base soldada', 'codigo' => 'AG-101', 'costo_unitario' => '80', 'activo' => 1], $usuario) && PP::contar(['proveedor' => $pA, 'q' => 'T19 ']) === 2, $errores());
$a2 = PP::$ultimoId;

echo "Mismo artículo con distinto costo según el proveedor\n";
$ok = PP::crear(['proveedor_id' => $pB, 'nombre' => 'T19 Armazón sin pintar', 'codigo' => 'AG-100', 'costo_unitario' => '135', 'activo' => 1], $usuario);
$b1 = PP::$ultimoId;
$prueba('otro proveedor puede tener el mismo nombre y código con otro costo', $ok && PP::buscarID($b1)['costo_unitario'] === '135.00' && PP::buscarID($a1)['costo_unitario'] === '120.50', $errores());

echo "Sin duplicados innecesarios\n";
$prueba('mismo nombre en el mismo proveedor se rechaza', !PP::crear(['proveedor_id' => $pA, 'nombre' => 't19 armazón SIN pintar', 'costo_unitario' => '1'], $usuario));
$e = $errores();
$prueba('el aviso dice cuál ya existe', str_contains($e, 'T19 Armazón sin pintar'), $e);
$prueba('mismo código en el mismo proveedor se rechaza', !PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Otro nombre', 'codigo' => 'AG-100', 'costo_unitario' => '1'], $usuario));
$prueba('el aviso dice quién usa el código', str_contains($errores(), 'T19 Armazón sin pintar'));
$prueba('dos productos sin código no chocan entre sí', PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Sin código 1', 'costo_unitario' => '1'], $usuario) && PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Sin código 2', 'costo_unitario' => '1'], $usuario), $errores());
$prueba('proveedor inexistente', !PP::crear(['proveedor_id' => 99999999, 'nombre' => 'T19 X', 'costo_unitario' => '1'], $usuario));
PP::getErrores();
$prueba('no se agregan productos a un proveedor inactivo', !PP::crear(['proveedor_id' => $pI, 'nombre' => 'T19 Y', 'costo_unitario' => '1'], $usuario));
PP::getErrores();

echo "Validaciones\n";
foreach ([['nombre vacío', ['nombre' => '', 'costo_unitario' => '1']], ['costo negativo', ['nombre' => 'T19 N', 'costo_unitario' => '-1']],
          ['costo no numérico', ['nombre' => 'T19 N', 'costo_unitario' => 'abc']], ['costo vacío', ['nombre' => 'T19 N', 'costo_unitario' => '']],
          ['código demasiado largo', ['nombre' => 'T19 N', 'codigo' => str_repeat('x', 41), 'costo_unitario' => '1']]] as [$etiqueta, $extra]) {
    $prueba("rechaza $etiqueta", !PP::crear(['proveedor_id' => $pA] + $extra, $usuario));
    PP::getErrores();
}
$prueba('acepta costo cero y coma decimal', PP::crear(['proveedor_id' => $pA, 'nombre' => 'T19 Gratis', 'costo_unitario' => '0,5'], $usuario) && PP::buscarID(PP::$ultimoId)['costo_unitario'] === '0.50', $errores());

echo "Edición y bitácora de costos\n";
$prueba('editar', PP::actualizar($a1, ['proveedor_id' => $pA, 'nombre' => 'T19 Armazón sin pintar', 'descripcion' => 'Calibre 14 reforzado', 'codigo' => 'AG-100', 'costo_unitario' => '130', 'activo' => 1], $usuario), $errores());
$prueba('el cambio de costo queda auditado con antes y después',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad='proveedor_producto' AND entidad_id=$a1 AND accion='cambiar_costo' AND detalle LIKE '%120.50%' AND detalle LIKE '%130.00%'") === 1);
$prueba('editar sin chocar consigo mismo (mismo nombre y código)', PP::actualizar($a1, ['proveedor_id' => $pA, 'nombre' => 'T19 Armazón sin pintar', 'codigo' => 'AG-100', 'costo_unitario' => '130', 'activo' => 1], $usuario), $errores());
$prueba('editar a un nombre ya usado por otro del proveedor se rechaza', !PP::actualizar($a1, ['proveedor_id' => $pA, 'nombre' => 'T19 Base soldada', 'costo_unitario' => '1', 'activo' => 1], $usuario));
PP::getErrores();

echo "Desactivar (no se borra)\n";
$prueba('desactivar', PP::cambiarEstado($a2, false, $usuario) && (int) PP::buscarID($a2)['activo'] === 0, $errores());
$sel = array_column(PP::paraSelector($pA), 'id');
$prueba('un inactivo no se ofrece en compras nuevas', !in_array($a2, array_map('intval', $sel), true) && in_array($a1, array_map('intval', $sel), true));
$prueba('sigue en el historial y se filtra como inactivo', PP::contar(['proveedor' => $pA, 'estado' => 'inactivos']) === 1 && PP::buscarID($a2) !== null);
$prueba('reactivar', PP::cambiarEstado($a2, true, $usuario) && (int) PP::buscarID($a2)['activo'] === 1);
$db->query("UPDATE proveedores SET activo = 0 WHERE id = $pB");
$prueba('no se reactiva si el proveedor está desactivado', PP::cambiarEstado($b1, false, $usuario) && !PP::cambiarEstado($b1, true, $usuario));
PP::getErrores();
$db->query("UPDATE proveedores SET activo = 1 WHERE id = $pB");
$prueba('el proveedor con productos no se puede eliminar del directorio', !(App\Proveedores::buscarID($pA)->eliminar()));
App\Proveedores::getErrores();

echo "Relación con los productos propios\n";
$prueba('relacionar un semiterminado con un producto propio', PP::relacionar($a1, $prodCanastilla, $usuario), $errores());
$prueba('repetir la relación no duplica', PP::relacionar($a1, $prodCanastilla, $usuario) && count(PP::productosRelacionados($a1)) === 1);
$prueba('un semiterminado de otro proveedor puede relacionarse con el MISMO producto propio', PP::relacionar($b1, $prodCanastilla, $usuario));
$deProd = PP::deProducto($prodCanastilla);
$prueba('desde el producto propio se ve qué semiterminado de qué proveedor y a qué costo',
    count($deProd) === 2 && $deProd[0]['proveedor_nombre'] !== $deProd[1]['proveedor_nombre'] && in_array('130.00', array_column($deProd, 'costo_unitario'), true) && in_array('135.00', array_column($deProd, 'costo_unitario'), true));
$prueba('un producto propio puede usar varios semiterminados', PP::relacionar($a2, $prodCanastilla, $usuario) && count(PP::deProducto($prodCanastilla)) === 3);
$prueba('un semiterminado puede servir a varios productos propios', PP::relacionar($a1, $prodReja, $usuario) && count(PP::productosRelacionados($a1)) === 2);
$prueba('no confunde identidades: el semiterminado y el producto propio conservan su código y costo',
    PP::buscarID($a1)['codigo'] === 'AG-100' && (string) $uno("SELECT sku FROM productos WHERE id = $prodCanastilla") === 'T19-1'
    && (string) $uno("SELECT costo_actual FROM productos WHERE id = $prodCanastilla") === '0.00');
$prueba('rechaza relacionar con un producto que no existe', !PP::relacionar($a1, 99999999, $usuario));
PP::getErrores();
$prueba('filtra semiterminados con/sin relación', PP::contar(['proveedor' => $pA, 'q' => 'T19 ', 'relacion' => 'sin']) >= 1 && PP::contar(['proveedor' => $pA, 'relacion' => 'con']) === 2);
$prueba('filtra por producto propio', PP::contar(['producto' => $prodReja]) === 1);
$prueba('desrelacionar', PP::desrelacionar($a1, $prodReja, $usuario) && PP::contar(['producto' => $prodReja]) === 0);
$db->query("DELETE FROM productos WHERE id = $prodReja");
$prueba('si se borra el producto propio, la relación se va pero el semiterminado queda', PP::buscarID($a1) !== null && count(PP::productosRelacionados($a1)) === 1);

echo "Compras históricas no se alteran al cambiar el costo del catálogo\n";
$db->query("INSERT INTO ordenes_compra (folio, tipo, proveedor_id, fecha_solicitud, contribuyente_id, total_esperado, usuario_id, referencia) VALUES ('T19-OC-1', 'semiterminado', $pA, CURDATE(), 1, 120.50, $usuario, 'T19-X')");
$oc = (int) $db->insert_id;
$db->query("INSERT INTO orden_compra_partidas (orden_id, producto_id, descripcion, cantidad_solicitada, costo_esperado, importe_esperado) VALUES ($oc, $prodCanastilla, 'T19 Armazón sin pintar', 1, 120.50, 120.50)");
PP::actualizar($a1, ['proveedor_id' => $pA, 'nombre' => 'T19 Armazón sin pintar', 'codigo' => 'AG-100', 'costo_unitario' => '999', 'activo' => 1], $usuario);
$prueba('la partida de una compra anterior conserva su costo', (string) $uno("SELECT costo_esperado FROM orden_compra_partidas WHERE orden_id = $oc") === '120.50');
$db->query("DELETE FROM ordenes_compra WHERE id = $oc");

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
