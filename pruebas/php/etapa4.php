<?php
/**
 * pruebas/php/etapa4.php — preparación de pedidos y entregas.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa4.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Entregas, Cotizaciones, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$stock = fn (int $id) => (int) $uno("SELECT stock FROM productos WHERE id = $id");
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$clientes = array_column($db->query('SELECT id FROM clientes LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'id');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

// Estado limpio
$db->query('DELETE FROM entregas'); $db->query('DELETE FROM preparaciones');
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones');
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

$venta = function (int $cliente, array $lineas) use ($usuario, $hoy) {
    Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'descuento_pct' => 0], $lineas, $usuario);
    return Ventas::$ultimoId;
};
$L = fn (int $c) => ['cantidad' => $c, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1];

$vA = $venta((int) $clientes[0], [37 => $L(5), 36 => $L(1)]);
$vB = $venta((int) $clientes[1], [37 => $L(2)]);

// Cotización sin vender: no debe aparecer
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, total)
            VALUES ('FOL-E4-0001', 1, {$clientes[0]}, $usuario, '$hoy', 'pendiente', 100, 116)");
$cotPend = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($cotPend, 37, 9, 100, 900)");
// Cotización vendida: sí debe aparecer (como venta)
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, total)
            VALUES ('FOL-E4-0002', 1, {$clientes[1]}, $usuario, '$hoy', 'pendiente', 100, 116)");
$cotV = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($cotV, 36, 3, 100, 300)");
Cotizaciones::cambiarEstado($cotV, 'vendido', $usuario);
$vC = (int) $uno("SELECT id FROM ventas WHERE cotizacion_id = $cotV");

echo "Ventas disponibles\n";
$ids = array_map(fn ($v) => (int) $v['id'], Entregas::ventasPendientes([], 50, 0));
$prueba('incluye ventas directas y de cotización vendida', in_array($vA, $ids) && in_array($vB, $ids) && in_array($vC, $ids));
$prueba('no incluye cotizaciones sin vender', count($ids) === 3, (string) count($ids));

echo "Preparación con productos agrupados\n";
$s37 = $stock(37);
$prueba('crear preparación con A y B', Entregas::crearPreparacion([$vA, $vB], 'ruta norte', $usuario), implode('|', Entregas::getErrores()));
$prep = Entregas::$ultimoId;
$prueba('folio PREP-AAAA-NNNN', (bool) preg_match('/^PREP-\d{4}-\d{4}$/', Entregas::buscarPreparacion($prep)['folio']));
$ventas = Entregas::ventasDePreparacion($prep);
$grupos = array_column(Entregas::productosAgrupados($ventas), null, 'producto_id');
$prueba('producto 37 agrupado: 5 + 2 = 7', $grupos[37]['total'] === 7, (string) ($grupos[37]['total'] ?? '-'));
$prueba('indica las 2 ventas de origen', count($grupos[37]['origenes']) === 2);
$prueba('producto 36 aparece con 1', $grupos[36]['total'] === 1);
$prueba('la preparación NO mueve inventario', $stock(37) === $s37);

echo "Aviso de venta duplicada\n";
$prueba('agregar la venta A a otra preparación activa se advierte', !Entregas::crearPreparacion([$vA, $vC], '', $usuario));
$prueba('→ devuelve el conflicto con la preparación', count(Entregas::$conflictos) === 1 && Entregas::$conflictos[0]['venta'] !== '');
Entregas::getErrores();
$prueba('→ no creó nada', Entregas::contarPreparaciones() === 1);
$prueba('con confirmación explícita sí se crea', Entregas::crearPreparacion([$vA, $vC], '', $usuario, true));
$prep2 = Entregas::$ultimoId;

echo "Estados\n";
$prueba('pendiente → en preparación', Entregas::cambiarEstado($prep, 'en_preparacion', $usuario));
$prueba('→ preparado → en reparto', Entregas::cambiarEstado($prep, 'preparada', $usuario) && Entregas::cambiarEstado($prep, 'en_reparto', $usuario));
$prueba('«entregado» a mano se rechaza si hay pendientes', !Entregas::cambiarEstado($prep, 'entregada', $usuario));
Entregas::getErrores();

echo "Entrega parcial y total (sin tocar inventario)\n";
$token = bin2hex(random_bytes(16));
$s37 = $stock(37); $s36 = $stock(36);
$prueba('entrega parcial: 3 de las 5 piezas de A', Entregas::registrarEntrega($vA, $prep, [37 => 3], $hoy, $token, '', $usuario), implode('|', Entregas::getErrores()));
$prueba('inventario intacto al entregar', $stock(37) === $s37 && $stock(36) === $s36);
$prueba('mismo token no duplica', !Entregas::registrarEntrega($vA, $prep, [37 => 3], $hoy, $token, '', $usuario));
Entregas::getErrores();
$p = Entregas::pendientesDeVentas([$vA])[$vA];
$prueba('pendiente de 37 en A = 2 y de 36 = 1', $p[37]['pendiente'] === 2 && $p[36]['pendiente'] === 1);
$prueba('no se entrega más de lo pendiente', !Entregas::registrarEntrega($vA, $prep, [37 => 3], $hoy, bin2hex(random_bytes(16)), '', $usuario));
Entregas::getErrores();
$grupos = array_column(Entregas::productosAgrupados(Entregas::ventasDePreparacion($prep)), null, 'producto_id');
$prueba('el agrupado refleja lo pendiente (2 + 2 = 4)', $grupos[37]['total'] === 4, (string) $grupos[37]['total']);
$prueba('estado en reparto', Entregas::buscarPreparacion($prep)['estado'] === 'en_reparto');
$prueba('no se puede cancelar con entregas', !Entregas::cambiarEstado($prep, 'cancelada', $usuario));
Entregas::getErrores();

Entregas::registrarEntrega($vA, $prep, [37 => 2, 36 => 1], $hoy, bin2hex(random_bytes(16)), '', $usuario);
$prueba('A completa: ya no aparece como pendiente', !in_array($vA, array_map(fn ($v) => (int) $v['id'], Entregas::ventasPendientes([], 50, 0))));
$prueba('B pendiente aún; preparación sigue en reparto', Entregas::buscarPreparacion($prep)['estado'] === 'en_reparto');
Entregas::registrarEntrega($vB, $prep, [37 => 2], $hoy, bin2hex(random_bytes(16)), '', $usuario);
$prueba('al completar todas, la preparación pasa a entregada', Entregas::buscarPreparacion($prep)['estado'] === 'entregada');
$prueba('inventario sigue intacto', $stock(37) === $s37 && $stock(36) === $s36);

echo "Por cliente\n";
$porCliente = Entregas::pendientesPorCliente();
$prueba('solo queda el cliente de la venta de cotización', count($porCliente) === 1 && $porCliente[0]['piezas'] === 3, json_encode(array_column($porCliente, 'piezas')));
$prueba('cancelar una preparación sin entregas', Entregas::cambiarEstado($prep2, 'cancelada', $usuario));


echo "Devolución cierra la entrega\n";
$vE = $venta((int) $clientes[0], [37 => $L(4)]);
$prueba('venta nueva aparece por entregar', in_array($vE, array_map(fn ($v) => (int) $v['id'], Entregas::ventasPendientes([], 50, 0))));
Entregas::crearPreparacion([$vE], '', $usuario);
$prepE = Entregas::$ultimoId;
Entregas::registrarEntrega($vE, $prepE, [37 => 1], $hoy, bin2hex(random_bytes(16)), '', $usuario);
$prueba('con 1 de 4 entregada sigue pendiente (3)', Entregas::pendientesDeVentas([$vE])[$vE][37]['pendiente'] === 3);
$prueba('devolver 1 pieza registra la devolución', App\Inventario::devolverDeVenta($vE, [37 => 1], 'Cliente rechazó', $usuario), implode('|', App\Inventario::getErrores()));
$prueba('la devolución cierra la entrega (pendiente 0)', Entregas::pendientesDeVentas([$vE])[$vE][37]['pendiente'] === 0);
$prueba('ya no aparece por entregar', !in_array($vE, array_map(fn ($v) => (int) $v['id'], Entregas::ventasPendientes([], 50, 0))));
$prueba('la venta sigue registrada (sigue siendo ingreso)', $uno("SELECT estado FROM ventas WHERE id = $vE") === 'registrada');
$prueba('su preparación pasa a entregada', Entregas::buscarPreparacion($prepE)['estado'] === 'entregada');
$prueba('ya no se puede registrar entrega', !Entregas::registrarEntrega($vE, $prepE, [37 => 1], $hoy, bin2hex(random_bytes(16)), '', $usuario));
Entregas::getErrores();
$prueba('ya no se puede preparar de nuevo', !Entregas::crearPreparacion([$vE], '', $usuario));
Entregas::getErrores();

echo "Concurrencia: dos entregas simultáneas de la misma pieza\n";
$vD = $venta((int) $clientes[0], [37 => $L(2)]);
$raiz = dirname(__DIR__, 2);
$hijo = fn (string $t) => "require '$raiz/includes/app.php'; @session_start(); App\\Entregas::registrarEntrega($vD, null, [37 => 2], '$hoy', '$t', '', $usuario);";
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open(['php', '-r', $hijo(bin2hex(random_bytes(16)))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub, $raiz, ['DB_NAME' => 'cotizaciones_test'] + getenv());
}
foreach ($procs as $p) { proc_close($p); }
$entregado = (int) $uno("SELECT COALESCE(SUM(er.cantidad), 0) FROM entrega_renglones er JOIN entregas e ON e.id = er.entrega_id WHERE e.venta_id = $vD");
$prueba('4 intentos simultáneos entregan 2 piezas, no más', $entregado === 2, (string) $entregado);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
