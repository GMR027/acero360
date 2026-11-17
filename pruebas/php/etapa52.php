<?php
/**
 * pruebas/php/etapa52.php — Pedidos de clientes y contraseña del portal.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa52.php
 *
 * Comprueba: alta de pedido con los importes de Cotizaciones::calcular,
 * «marcar como cotización», «marcar como venta» desde un pedido cotizado (la
 * cotización queda vendida y hay UNA sola venta), venta directa desde un pedido
 * pendiente (descuenta inventario), que repetir la acción no duplica, la
 * cancelación y el hash bcrypt de la contraseña del portal.
 *
 * Los datos llevan «T52». Las ventas que nacen aquí se quedan en la base de
 * prueba (se recrea en cada corrida de ejecutar_todo.sh).
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
use App\Cotizaciones;
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
$empresa = (int) $uno('SELECT id FROM empresas WHERE activa = 1 ORDER BY id LIMIT 1');
$hoy = date('Y-m-d');

$db->query("DELETE FROM pedidos WHERE comentarios LIKE 'T52%'");

/* --- Datos ---------------------------------------------------------------- */
$cliente = new Clientes(['nombre' => 'T52 Cliente', 'nombre_empresa' => 'T52 SA', 'email' => 't52@ejemplo.test',
    'portal_nueva' => 'secreta-t52']);
$prueba('cliente válido con contraseña de portal', $cliente->validar() === [], implode(' ', Clientes::getErrores()));
$cliente->crear();
$clienteId = (int) $cliente->id;

$producto = new Productos(['empresa_id' => $empresa, 'negocios' => [$empresa], 'nombre' => 'T52 Producto',
    'precio' => 100, 'activo' => 1]);
$producto->crear();
$productoId = (int) $producto->id;
$db->query("UPDATE productos SET stock = 10 WHERE id = $productoId");

$datos = ['empresa_id' => $empresa, 'cliente_id' => $clienteId, 'fecha' => $hoy, 'aplica_iva' => 1,
    'descuento_pct' => 10, 'comentarios' => 'T52 pedido'];
$lineas = [$productoId => ['cantidad' => 3, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => true]];

/* --- 1. Portal ------------------------------------------------------------ */
echo "1. Contraseña del portal\n";
$hash = (string) $uno("SELECT portal_contrasena FROM clientes WHERE id = $clienteId");
$prueba('se guarda como hash bcrypt (no texto plano)', str_starts_with($hash, '$2y$') && $hash !== 'secreta-t52', $hash);
$prueba('password_verify acepta la contraseña', password_verify('secreta-t52', $hash));
$editado = Clientes::buscarID($clienteId);
$editado->portal_nueva = '';
$editado->actualizar();
$prueba('vacía conserva la contraseña', (string) $uno("SELECT portal_contrasena FROM clientes WHERE id = $clienteId") === $hash);
$corta = new Clientes(['nombre' => 'T52 b', 'alias' => 'b', 'portal_nueva' => 'corta']);
$prueba('rechaza contraseña corta y sin correo', count($corta->validar()) >= 2, implode(' | ', $corta->validar()));

/* --- 2. Alta -------------------------------------------------------------- */
echo "2. Alta del pedido\n";
$prueba('valida', Pedidos::validar($datos, $lineas) === [], implode(' ', Pedidos::validar($datos, $lineas)));
$prueba('se crea', Pedidos::crear($datos, $lineas, $usuario), implode(' ', Pedidos::getErrores()));
$p1 = Pedidos::buscarID(Pedidos::$ultimoId);
$esperado = Cotizaciones::calcular($lineas, 10, true);
$prueba('folio PED-año', str_starts_with((string) $p1->folio, 'PED-' . date('Y') . '-'), (string) $p1->folio);
$prueba('totales = Cotizaciones::calcular (300 − 10% = 270 + IVA = 313.20)',
    (float) $p1->total === $esperado['total'] && (float) $p1->total === 313.2, (string) $p1->total);
$prueba('estado pendiente', $p1->estado === 'pendiente');

/* --- 3. Cotización y luego venta ---------------------------------------- */
echo "3. Pedido → cotización → venta\n";
$prueba('marcar como cotización', Pedidos::marcarCotizacion((int) $p1->id, $usuario), implode(' ', Pedidos::getErrores()));
$p1 = Pedidos::buscarID((int) $p1->id);
$prueba('queda cotizado y enlazado', $p1->estado === 'cotizado' && (int) $p1->cotizacion_id > 0);
$prueba('la cotización tiene el mismo total', (float) $uno('SELECT total FROM cotizaciones WHERE id = ' . (int) $p1->cotizacion_id) === 313.2);
$prueba('repetir «cotización» no crea otra', !Pedidos::marcarCotizacion((int) $p1->id, $usuario));
Pedidos::getErrores();

$stockAntes = (int) $uno("SELECT stock FROM productos WHERE id = $productoId");
$prueba('marcar como venta', Pedidos::marcarVenta((int) $p1->id, $usuario), implode(' ', Pedidos::getErrores()));
$p1 = Pedidos::buscarID((int) $p1->id);
$prueba('queda vendido con venta enlazada', $p1->estado === 'vendido' && (int) $p1->venta_id > 0);
$prueba('la cotización quedó vendida', $uno('SELECT estado FROM cotizaciones WHERE id = ' . (int) $p1->cotizacion_id) === 'vendido');
$prueba('existe UNA sola venta de esa cotización',
    (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = " . (int) $p1->cotizacion_id . " AND estado = 'registrada'") === 1);
$prueba('descontó 3 del inventario', (int) $uno("SELECT stock FROM productos WHERE id = $productoId") === $stockAntes - 3);
$prueba('repetir «venta» no duplica', !Pedidos::marcarVenta((int) $p1->id, $usuario));
Pedidos::getErrores();

/* --- 4. Venta directa ---------------------------------------------------- */
echo "4. Pedido pendiente → venta directa\n";
$datos['comentarios'] = 'T52 directo';
Pedidos::crear($datos, $lineas, $usuario);
$p2 = Pedidos::$ultimoId;
$stockAntes = (int) $uno("SELECT stock FROM productos WHERE id = $productoId");
$prueba('marcar como venta', Pedidos::marcarVenta($p2, $usuario), implode(' ', Pedidos::getErrores()));
$venta = (int) $uno("SELECT venta_id FROM pedidos WHERE id = $p2");
$prueba('venta directa enlazada y sin cotización', $venta > 0 && $uno("SELECT cotizacion_id FROM pedidos WHERE id = $p2") === null);
$prueba('venta con el total del pedido', (float) $uno("SELECT total FROM ventas WHERE id = $venta") === 313.2);
$prueba('descontó 3 del inventario', (int) $uno("SELECT stock FROM productos WHERE id = $productoId") === $stockAntes - 3);

/* --- 5. Cancelar y editar ------------------------------------------------ */
echo "5. Cancelar\n";
$datos['comentarios'] = 'T52 cancelar';
Pedidos::crear($datos, $lineas, $usuario);
$p3 = Pedidos::$ultimoId;
$prueba('se cancela', Pedidos::cancelar($p3, $usuario));
$prueba('un cancelado no se edita', !Pedidos::actualizar($p3, $datos, $lineas));
$prueba('un cancelado no se vende', !Pedidos::marcarVenta($p3, $usuario));
Pedidos::getErrores();

/* --- 6. Sincronización con el Histórico ---------------------------------- */
echo "6. Cotización vendida desde el Histórico\n";
$datos['comentarios'] = 'T52 sincronizar';
Pedidos::crear($datos, $lineas, $usuario);
$p4 = Pedidos::$ultimoId;
Pedidos::marcarCotizacion($p4, $usuario);
$cot = (int) $uno("SELECT cotizacion_id FROM pedidos WHERE id = $p4");
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
Pedidos::sincronizarConVentas();
$prueba('el pedido pasa a vendido con su venta', $uno("SELECT estado FROM pedidos WHERE id = $p4") === 'vendido'
    && (int) $uno("SELECT venta_id FROM pedidos WHERE id = $p4") > 0);

$db->query("DELETE FROM pedidos WHERE comentarios LIKE 'T52%'");
echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
