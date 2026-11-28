<?php
/**
 * pruebas/php/etapa61.php — Proyectos de entrega y compras por cliente.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa61.php
 *
 * Comprueba:
 *   • un proyecto guarda partidas por producto;
 *   • una venta vinculada suma lo vendido y sus entregas suman lo entregado;
 *   • lo pendiente se calcula contra el compromiso y contra lo vendido;
 *   • no se puede quitar una partida con ventas o entregas;
 *   • al desvincular, el avance vuelve a cero;
 *   • el reporte de compras del cliente suma ventas, piezas e importes.
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
use App\Entregas;
use App\Productos;
use App\ProyectosEntrega;
use App\Ventas;
use App\VentasCliente;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios ORDER BY id LIMIT 1');
$_SESSION['id'] = $usuario;
$empresa = (int) $uno('SELECT id FROM empresas ORDER BY id LIMIT 1');
$hoy = date('Y-m-d');

$cliente = new Clientes(['nombre' => 'T61 Cliente', 'email' => 't61@ejemplo.test']);
$prueba('crear cliente', $cliente->crear(), implode(' ', Clientes::getErrores()));
$clienteId = (int) $cliente->id;

$producto = new Productos([
    'empresa_id' => $empresa, 'negocios' => [$empresa], 'nombre' => 'T61 Producto',
    'sku' => 'T61-' . random_int(1000, 9999), 'precio' => 100, 'activo' => 1,
]);
$prueba('crear producto', $producto->crear(), implode(' ', Productos::getErrores()));
$productoId = (int) $producto->id;
$db->query("UPDATE productos SET stock = 20 WHERE id = $productoId");

$datosProyecto = [
    'cliente_id' => $clienteId, 'empresa_id' => $empresa,
    'nombre' => 'T61 Proyecto', 'descripcion' => 'Prueba',
    'fecha_inicio' => $hoy, 'fecha_compromiso' => date('Y-m-d', strtotime('+30 days')),
    'estado' => 'activo', 'notas' => '',
];
$partidas = [[
    'producto_id' => $productoId, 'cantidad_comprometida' => 10,
    'precio_unitario' => null, 'notas' => '',
]];

echo "1. Proyecto y partidas\n";
$prueba('crear proyecto', ProyectosEntrega::crear($datosProyecto, $partidas, $usuario), implode(' ', ProyectosEntrega::getErrores()));
$proyectoId = (int) ProyectosEntrega::$ultimoId;
$proyecto = ProyectosEntrega::buscarID($proyectoId);
$prueba('folio PRY', $proyecto !== null && str_starts_with($proyecto->folio, 'PRY-'));
$prueba('una partida', count(ProyectosEntrega::partidas($proyectoId)) === 1);
$prueba('validación rechaza proyecto sin partidas', !ProyectosEntrega::crear($datosProyecto, [], $usuario));
ProyectosEntrega::getErrores();

echo "2. Venta vinculada y entregas\n";
$datosVenta = [
    'empresa_id' => $empresa, 'cliente_id' => $clienteId, 'fecha' => $hoy,
    'aplica_iva' => 1, 'descuento_pct' => 0,
    'condiciones_envio' => '', 'condiciones_compra' => '', 'comentarios' => 'T61 venta',
];
$lineas = [$productoId => ['cantidad' => 3, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => true]];
$prueba('crear venta', Ventas::crear($datosVenta, $lineas, $usuario), implode(' ', Ventas::getErrores()));
$ventaId = (int) Ventas::$ultimoId;
$prueba('vincular venta', ProyectosEntrega::vincularVenta($proyectoId, $ventaId, $usuario), implode(' ', ProyectosEntrega::getErrores()));

$avance = ProyectosEntrega::progreso($proyectoId);
$prueba('comprometido 10', (int) $avance['totales']['comprometido'] === 10);
$prueba('vendido 3', (int) $avance['totales']['vendido'] === 3);
$prueba('entregado 0', (int) $avance['totales']['entregado'] === 0);
$prueba('por entregar 10', (int) $avance['totales']['por_entregar'] === 10);
$prueba('por vender 7', (int) $avance['totales']['por_vender'] === 7);

$token = bin2hex(random_bytes(16));
$prueba('registrar entrega de 2', Entregas::registrarEntrega(
    $ventaId, null, [$productoId => 2], $hoy, $token, 'T61 entrega', $usuario
), implode(' ', Entregas::getErrores()));

$avance = ProyectosEntrega::progreso($proyectoId);
$prueba('entregado 2', (int) $avance['totales']['entregado'] === 2);
$prueba('por entregar 8', (int) $avance['totales']['por_entregar'] === 8);
$prueba('avance 20 %', abs((float) $avance['totales']['avance'] - 20.0) < 0.1);
$prueba('venta con 1 pendiente', (int) $avance['totales']['vendido'] - (int) $avance['totales']['entregado'] === 1);

echo "3. Reglas de edición\n";
$partidasNuevas = [[
    'producto_id' => $productoId, 'cantidad_comprometida' => 10,
    'precio_unitario' => '123.45', 'notas' => 'T61 actualizada',
]];
$prueba('actualizar cantidad/precio', ProyectosEntrega::actualizar($proyectoId, $datosProyecto, $partidasNuevas, $usuario), implode(' ', ProyectosEntrega::getErrores()));
$db->query("INSERT INTO productos (empresa_id,nombre,sku,unidad,precio,stock,activo,creado_en)
            VALUES ($empresa,'T61 Otro','T61-OTRO','pza',50,5,1,NOW())");
$otroProducto = (int) $db->insert_id;
$db->query("INSERT INTO producto_empresa (producto_id,empresa_id) VALUES ($otroProducto,$empresa)");
$partidasSinProducto = [[
    'producto_id' => $otroProducto, 'cantidad_comprometida' => 1,
    'precio_unitario' => null, 'notas' => '',
]];
$prueba('no quita partida con movimiento', !ProyectosEntrega::actualizar($proyectoId, $datosProyecto, $partidasSinProducto, $usuario));
ProyectosEntrega::getErrores();

echo "4. Reporte de compras del cliente\n";
$resumen = VentasCliente::resumen($clienteId, $hoy, $hoy);
$prueba('una venta', (int) $resumen['ventas'] === 1);
$prueba('tres piezas', (int) $resumen['piezas'] === 3);
$prueba('total con IVA', abs((float) $resumen['total'] - 348.0) < 0.01);
$productos = VentasCliente::productos($clienteId, $hoy, $hoy);
$prueba('producto en el reporte', count($productos) === 1 && (int) $productos[0]['piezas'] === 3);

echo "5. Desvincular y limpiar\n";
$prueba('desvincular venta', ProyectosEntrega::desvincularVenta($proyectoId, $ventaId, $usuario));
$avance = ProyectosEntrega::progreso($proyectoId);
$prueba('vuelve a cero lo vendido', (int) $avance['totales']['vendido'] === 0);
$prueba('eliminar proyecto', ProyectosEntrega::eliminar($proyectoId, $usuario), implode(' ', ProyectosEntrega::getErrores()));

$db->query("DELETE FROM entrega_renglones WHERE entrega_id IN (SELECT id FROM entregas WHERE venta_id = $ventaId)");
$db->query("DELETE FROM entregas WHERE venta_id = $ventaId");
$db->query("DELETE FROM movimientos_inventario WHERE venta_id = $ventaId");
$db->query("DELETE FROM venta_detalles WHERE venta_id = $ventaId");
$db->query("DELETE FROM ventas WHERE id = $ventaId");
$db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE cliente_id = $clienteId)");
$db->query("DELETE FROM ingresos WHERE cliente_id = $clienteId");
$db->query("DELETE FROM producto_empresa WHERE producto_id IN ($productoId, $otroProducto)");
$db->query("DELETE FROM productos WHERE id IN ($productoId, $otroProducto)");
$db->query("DELETE FROM clientes WHERE id = $clienteId");

echo $fallos === 0 ? "TODO OK\n" : "FALLOS: $fallos\n";
exit($fallos === 0 ? 0 : 1);
