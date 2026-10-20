<?php
/**
 * pruebas/php/etapa16.php — Devoluciones y cambios: la pieza devuelta suma al inventario, el cambio se descuenta al entregarlo.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa16.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Inventario, Seguimiento, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$stock = fn (int $id) => (int) $uno("SELECT stock FROM productos WHERE id = $id");
$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));
$manana = date('Y-m-d', strtotime('+1 day'));

$db->query('UPDATE productos SET stock = 100 WHERE id = 37');
Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 0, 'descuento_pct' => 0],
    [37 => ['cantidad' => 10, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$venta = (int) $uno('SELECT MAX(id) FROM ventas');
$prueba('vendidas 10: stock 90', $stock(37) === 90, (string) $stock(37));

echo "Registro simple\n";
$prueba('devolver 2 y 2 requieren cambio', Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 2, 'cambio' => 2]], $ayer, '', $usuario), implode('|', Inventario::getErrores()));
$prueba('  las 2 devueltas suman al inventario (92)', $stock(37) === 92, (string) $stock(37));
$prueba('  se registran fecha y usuario, sin motivo', $uno("SELECT CONCAT(fecha, '|', usuario_id, '|', IFNULL(nota, 'NULL')) FROM venta_devoluciones WHERE venta_id = $venta") === "$ayer|$usuario|NULL");
$c = Inventario::cambiosPendientes($venta)[37] ?? null;
$prueba('  la venta debe 2 piezas', $c && $c['pendiente'] === 2 && $c['a_cambiar'] === 2 && $c['entregadas'] === 0);
$prueba('  movimiento de inventario ligado a la venta', (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $venta AND motivo = 'devolucion'") === 1);

echo "Entrega del cambio\n";
$prueba('entregar 1 (parcial)', Inventario::entregarCambio($venta, 37, 1, $hoy, $usuario), implode('|', Inventario::getErrores()));
$prueba('  se descuenta del inventario (91)', $stock(37) === 91, (string) $stock(37));
$prueba('  sigue debiendo 1', Inventario::cambiosPendientes($venta)[37]['pendiente'] === 1);
$prueba('entregar más de lo que se debe → rechazo', !Inventario::entregarCambio($venta, 37, 2, $hoy, $usuario));
Inventario::getErrores();
$prueba('fecha futura → rechazo', !Inventario::entregarCambio($venta, 37, 1, $manana, $usuario));
Inventario::getErrores();
$prueba('entregar la última', Inventario::entregarCambio($venta, 37, 1, $hoy, $usuario) && $stock(37) === 90);
$prueba('  ya no se debe nada', Inventario::cambiosPendientes($venta)[37]['pendiente'] === 0);
$prueba('  y nada más que entregar', !Inventario::entregarCambio($venta, 37, 1, $hoy, $usuario));
Inventario::getErrores();
$prueba('movimientos «cambio» ligados a la venta', (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $venta AND motivo = 'cambio' AND tipo = 'salida'") === 2);

echo "Topes\n";
$prueba('el cambio no puede superar lo devuelto', !Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 1, 'cambio' => 2]], $hoy, '', $usuario));
Inventario::getErrores();
$prueba('no se devuelve más de lo vendido menos lo ya devuelto (quedan 8)', !Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 9, 'cambio' => 0]], $hoy, '', $usuario));
Inventario::getErrores();
$prueba('fecha futura → rechazo', !Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 1, 'cambio' => 0]], $manana, '', $usuario));
Inventario::getErrores();
$prueba('sin piezas → rechazo', !Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 0, 'cambio' => 0]], $hoy, '', $usuario));
Inventario::getErrores();
$prueba('devolver las 8 restantes (solo devolución)', Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 8, 'cambio' => 0]], $hoy, 'Cliente canceló', $usuario));
$prueba('  disponible = 0', Inventario::devolvibles($venta)[37]['disponible'] === 0);
$prueba('  una más → rechazo', !Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 1, 'cambio' => 0]], $hoy, '', $usuario));
Inventario::getErrores();

echo "Stock negativo permitido\n";
$db->query('UPDATE productos SET stock = 0 WHERE id = 37');
Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 0, 'descuento_pct' => 0],
    [37 => ['cantidad' => 1, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$v2 = (int) $uno('SELECT MAX(id) FROM ventas');
Inventario::registrarDevolucion($v2, [37 => ['devuelta' => 1, 'cambio' => 1]], $hoy, '', $usuario);
$db->query('UPDATE productos SET stock = 0 WHERE id = 37');
$prueba('entregar el cambio con stock 0 deja el saldo en -1 (permitido)', Inventario::entregarCambio($v2, 37, 1, $hoy, $usuario) && $stock(37) === -1, (string) $stock(37));

echo "Avisos\n";
Inventario::registrarDevolucion($venta, [37 => ['devuelta' => 0, 'cambio' => 0]], $hoy, '', $usuario); Inventario::getErrores();
$v3 = (int) $uno('SELECT id FROM ventas ORDER BY id DESC LIMIT 1');
$db->query("UPDATE productos SET stock = 50 WHERE id = 37");
Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 0, 'descuento_pct' => 0],
    [37 => ['cantidad' => 3, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$v4 = (int) $uno('SELECT MAX(id) FROM ventas');
Inventario::registrarDevolucion($v4, [37 => ['devuelta' => 1, 'cambio' => 1]], $hoy, '', $usuario);
$aviso = null;
foreach (Seguimiento::avisos() as $a) { if ($a['clave'] === "cambio:$v4") { $aviso = $a; } }
$prueba('la venta con cambio pendiente genera un aviso que abre la venta', $aviso && str_contains($aviso['enlace'], "ventas/detalle?id=$v4") && $aviso['categoria'] === 'entrega');
Inventario::entregarCambio($v4, 37, 1, $hoy, $usuario);
$hay = false; foreach (Seguimiento::avisos() as $a) { $hay = $hay || $a['clave'] === "cambio:$v4"; }
$prueba('al entregar el cambio el aviso desaparece', !$hay);

echo $fallos ? "\n$fallos FALLA(S)\n" : "\nTODO OK\n";
exit($fallos ? 1 : 0);
