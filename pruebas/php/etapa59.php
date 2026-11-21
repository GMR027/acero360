<?php
/**
 * pruebas/php/etapa59.php — Inventario y baja de pedidos.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa59.php
 *
 * Comprueba que:
 *   • crear un pedido o marcarlo como cotización NO mueve el inventario;
 *   • sólo «marcar como venta» descuenta existencias;
 *   • eliminar un pedido borra sus renglones y no toca el inventario;
 *   • un pedido con venta vinculada no se puede eliminar;
 *   • la cotización de un pedido eliminado se conserva.
 *
 * Los datos llevan «T59» y se limpian al final.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Clientes;
use App\Pedidos;
use App\Productos;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios ORDER BY id LIMIT 1');
$_SESSION['id'] = $usuario;
$empresa = (int) $uno("SELECT id FROM empresas WHERE nombre = 'Soluciones en Acero' ORDER BY id LIMIT 1");
$hoy = date('Y-m-d');

$cliente = new Clientes(['nombre' => 'T59 Cliente', 'email' => 't59@ejemplo.test']);
$cliente->crear();
$clienteId = (int) $cliente->id;

$producto = new Productos([
    'empresa_id' => $empresa, 'negocios' => [$empresa], 'nombre' => 'T59 Producto',
    'precio' => 100, 'activo' => 1,
]);
$producto->crear();
$productoId = (int) $producto->id;
$db->query("UPDATE productos SET stock = 10 WHERE id = $productoId");

$datos = ['empresa_id' => $empresa, 'cliente_id' => $clienteId, 'fecha' => $hoy, 'aplica_iva' => 1,
    'descuento_pct' => 0, 'comentarios' => 'T59 pedido'];
$lineas = [$productoId => ['cantidad' => 3, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => true]];
$stock = static fn () => (int) $uno("SELECT stock FROM productos WHERE id = $productoId");

echo "1. El pedido y la cotización no mueven inventario\n";
$prueba('crear pedido', Pedidos::crear($datos, $lineas, $usuario), implode(' ', Pedidos::getErrores()));
$p1 = Pedidos::buscarID(Pedidos::$ultimoId);
$prueba('stock intacto al crear', $stock() === 10);
$prueba('marcar como cotización', Pedidos::marcarCotizacion((int) $p1->id, $usuario), implode(' ', Pedidos::getErrores()));
$p1 = Pedidos::buscarID((int) $p1->id);
$prueba('stock intacto al cotizar', $stock() === 10);

echo "2. Sólo la venta descuenta inventario\n";
$prueba('marcar como venta', Pedidos::marcarVenta((int) $p1->id, $usuario), implode(' ', Pedidos::getErrores()));
$prueba('stock descontado al vender', $stock() === 7);
$p1 = Pedidos::buscarID((int) $p1->id);
$prueba('pedido vendido y enlazado', $p1->estado === 'vendido' && (int) $p1->venta_id > 0);
$prueba('un pedido vendido no se elimina', !Pedidos::eliminar((int) $p1->id));
Pedidos::getErrores();
$prueba('el pedido vendido sigue existiendo', (int) $uno("SELECT COUNT(*) FROM pedidos WHERE id = " . (int) $p1->id) === 1);

echo "3. Eliminar un pedido pendiente\n";
$datos['comentarios'] = 'T59 pendiente';
Pedidos::crear($datos, $lineas, $usuario);
$p2 = Pedidos::buscarID(Pedidos::$ultimoId);
$prueba('tiene renglones', count(Pedidos::detalles((int) $p2->id)) === 1);
$prueba('se elimina', Pedidos::eliminar((int) $p2->id), implode(' ', Pedidos::getErrores()));
$prueba('desaparece el encabezado', (int) $uno("SELECT COUNT(*) FROM pedidos WHERE id = " . (int) $p2->id) === 0);
$prueba('sus renglones se borran en cascada', (int) $uno("SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = " . (int) $p2->id) === 0);
$prueba('el stock no cambia al eliminar', $stock() === 7);

echo "4. Eliminar un pedido cotizado conserva la cotización\n";
$datos['comentarios'] = 'T59 cotizado';
Pedidos::crear($datos, $lineas, $usuario);
$p3 = Pedidos::buscarID(Pedidos::$ultimoId);
Pedidos::marcarCotizacion((int) $p3->id, $usuario);
$p3 = Pedidos::buscarID((int) $p3->id);
$cot3 = (int) $p3->cotizacion_id;
$prueba('se elimina el pedido cotizado', Pedidos::eliminar((int) $p3->id), implode(' ', Pedidos::getErrores()));
$prueba('la cotización sigue existiendo', (int) $uno("SELECT COUNT(*) FROM cotizaciones WHERE id = $cot3") === 1);

echo "5. Pedido inexistente\n";
$prueba('no se elimina un pedido que no existe', !Pedidos::eliminar(999999999));
Pedidos::getErrores();

/* --- Limpieza ------------------------------------------------------------- */
$db->query("DELETE FROM ingresos WHERE cliente_id = $clienteId");
$db->query("DELETE FROM pedidos WHERE cliente_id = $clienteId");
$db->query("DELETE FROM ventas WHERE cliente_id = $clienteId");
$db->query("DELETE FROM cotizaciones WHERE cliente_id = $clienteId");
$db->query("DELETE FROM precios_cliente WHERE cliente_id = $clienteId");
$db->query("DELETE FROM movimientos_inventario WHERE producto_id = $productoId");
$db->query("DELETE FROM productos WHERE id = $productoId");
$db->query("DELETE FROM clientes WHERE id = $clienteId");

echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
