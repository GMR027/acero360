<?php
/**
 * pruebas/php/etapa3.php — ventas unificadas, inventario único y devoluciones.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa3.php
 *
 * Parte de una BD ya migrada: revierte 003, siembra una cotización vendida
 * "antigua" y vuelve a migrar para comprobar el respaldo de datos.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Cotizaciones, Ventas, Inventario};

$raiz = dirname(__DIR__, 2);
$fallos = 0;
$prueba = function (string $nombre, bool $ok, string $detalle = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $nombre . ($ok || $detalle === '' ? '' : " → $detalle") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$stock = fn (int $id) => (int) $uno("SELECT stock FROM productos WHERE id = $id");
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

// ---- limpieza de corridas anteriores y estado conocido ---------------------
$db->query("DELETE FROM movimientos_inventario WHERE notas LIKE '%[e3]%' OR venta_id IS NOT NULL OR cotizacion_id IS NOT NULL OR motivo = 'correccion'");
foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones'] as $tabla) { $db->query("DELETE FROM $tabla"); }
$db->query('DELETE FROM documentos'); $db->query('DELETE FROM cobros'); $db->query('DELETE FROM ingresos');
$db->query('DELETE FROM entregas');
$db->query('DELETE FROM preparaciones');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas');
$db->query('DELETE FROM cotizaciones');
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

echo "Respaldo de datos (migración 003 sobre una cotización vendida antigua)\n";
passthru('mysql -uroot cotizaciones_test < ' . escapeshellarg("$raiz/mysql/migraciones/003_reversion.sql"));
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, descuento, aplica_iva, iva, total)
            VALUES ('FOL-2025-0001', 1, $cliente, $usuario, '2025-03-10', 'vendido', 100, 0, 1, 16, 116)");
$cotAntigua = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
            VALUES ($cotAntigua, 36, 2, 50, 0, 100)");
$db->query("UPDATE productos SET stock = 98 WHERE id = 36");
$db->query("INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, saldo, cotizacion_id, usuario_id, notas)
            VALUES (36, 'salida', 2, 98, $cotAntigua, $usuario, 'antigua [e3]')");
exec('php ' . escapeshellarg("$raiz/scripts/migrar.php") . ' cotizaciones_test 2>&1', $salida);
$prueba('migración 003 aplicada', str_contains(implode("\n", $salida), '003_ventas_unificadas'));
$prueba('la cotización vendida recibió su venta', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cotAntigua") === 1);
$prueba('folio de venta VEN-2025-0001', $uno("SELECT folio FROM ventas WHERE cotizacion_id = $cotAntigua") === 'VEN-2025-0001');
$prueba('renglones copiados', (int) $uno("SELECT SUM(d.cantidad) FROM venta_detalles d JOIN ventas v ON v.id = d.venta_id WHERE v.cotizacion_id = $cotAntigua") === 2);
$prueba('movimiento antiguo ligado a la venta', (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE cotizacion_id = $cotAntigua AND venta_id IS NOT NULL") === 1);
$prueba('el stock no se tocó al migrar', $stock(36) === 98);

echo "Cotización → venta sin duplicar\n";
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, descuento, aplica_iva, iva, total)
            VALUES ('FOL-E3-0001', 1, $cliente, $usuario, '$hoy', 'pendiente', 300, 0, 1, 48, 348)");
$cot = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
            VALUES ($cot, 37, 3, 100, 0, 300)");
$base = $stock(37);
$prueba('cotización pendiente no descuenta', $stock(37) === $base);
$prueba('marcar vendida', Cotizaciones::cambiarEstado($cot, 'vendido', $usuario), implode('|', Cotizaciones::getErrores()));
$prueba('descuenta 3 una sola vez', $stock(37) === $base - 3, (string) $stock(37));
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$prueba('repetir "vendida" no descuenta de nuevo', $stock(37) === $base - 3);
$prueba('exactamente 1 venta para la cotización', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cot") === 1);
$ventaId = (int) $uno("SELECT id FROM ventas WHERE cotizacion_id = $cot");
$prueba('movimiento ligado a la venta', (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $ventaId AND motivo = 'venta'") === 1);

$ganancias = Cotizaciones::ganancias(1);
$prueba('ganancias cuentan cada operación una vez (116 + 348)', abs($ganancias['ganancias'] - 464.0) < 0.001, (string) $ganancias['ganancias']);
$prueba('historial sin duplicados', Ventas::contarHistorial([]) === 2, (string) Ventas::contarHistorial([]));

echo "Editar una cotización vendida\n";
$ok = Cotizaciones::actualizar(
    ['id' => $cot, 'empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'vigencia_dias' => 15,
     'aplica_iva' => 1, 'estado' => 'vendido', 'descuento_pct' => 0],
    [37 => ['cantidad' => 5, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]]
);
$prueba('actualizar cotización vendida', $ok, implode('|', Cotizaciones::getErrores()));
$prueba('stock refleja 5 (no 8)', $stock(37) === $base - 5, (string) $stock(37));
$prueba('misma venta, nuevo total 580', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cot") === 1
    && $uno("SELECT total FROM ventas WHERE id = $ventaId") === '580.00', $uno("SELECT total FROM ventas WHERE id = $ventaId"));

echo "Devolución de cliente\n";
$prueba('devolver más de lo vendido se rechaza', !Inventario::devolverDeVenta($ventaId, [37 => 6], 'prueba e3', $usuario));
Inventario::getErrores();
$prueba('devolución sin motivo se acepta (el motivo es opcional)', Inventario::devolverDeVenta($ventaId, [37 => 1], '', $usuario), implode('|', Inventario::getErrores()));
$prueba('devolver 1 más (2 en total)', Inventario::devolverDeVenta($ventaId, [37 => 1], 'Pieza defectuosa', $usuario), implode('|', Inventario::getErrores()));
$prueba('stock +2', $stock(37) === $base - 3, (string) $stock(37));
$prueba('no se puede devolver más del saldo (quedan 3)', !Inventario::devolverDeVenta($ventaId, [37 => 4], 'otra vez', $usuario));
Inventario::getErrores();
$prueba('devolvibles: 5 vendidas, 2 devueltas', Inventario::devolvibles($ventaId)[37] === ['vendido' => 5, 'devuelto' => 2, 'disponible' => 3]);

echo "Corrección con motivo\n";
$prueba('corrección sin motivo se rechaza', !Inventario::corregir(37, 50, '', $usuario));
Inventario::getErrores();
$prueba('corrección con motivo', Inventario::corregir(37, $stock(37) + 1, 'Conteo físico', $usuario));
$prueba('queda con motivo «correccion»', (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE producto_id = 37 AND motivo = 'correccion'") === 1);
$db->query("UPDATE productos SET stock = " . ($base - 5 + 2) . " WHERE id = 37"); // quita la corrección de prueba

echo "Revertir y volver a vender\n";
$antes = $stock(37);
$prueba('quitar de vendido', Cotizaciones::cambiarEstado($cot, 'pendiente', $usuario));
$prueba('devuelve el stock restante de la venta', $stock(37) === $antes + 5, (string) $stock(37));
$prueba('la venta queda cancelada, no borrada', $uno("SELECT estado FROM ventas WHERE id = $ventaId") === 'cancelada');
$prueba('ya no cuenta en ganancias', abs(Cotizaciones::ganancias(1)['ganancias'] - 116.0) < 0.001);
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$prueba('al revender se reutiliza la misma venta (1 sola)', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cot") === 1
    && $uno("SELECT estado FROM ventas WHERE id = $ventaId") === 'registrada');

echo "Venta directa\n";
$s36 = $stock(36);
$ok = Ventas::crear(
    ['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'descuento_pct' => 0],
    [36 => ['cantidad' => 4, 'precio' => 50, 'descuento_pct' => 0, 'no_recordar' => 1]],
    $usuario
);
$prueba('venta directa creada', $ok, implode('|', Ventas::getErrores()));
$prueba('descuenta 4 una vez', $stock(36) === $s36 - 4, (string) $stock(36));
$prueba('sin cotización de origen', $uno('SELECT cotizacion_id FROM ventas ORDER BY id DESC LIMIT 1') === null);
$prueba('editar una venta de cotización está bloqueado', !Ventas::actualizar(
    ['id' => $ventaId, 'empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'estado' => 'registrada'],
    [37 => ['cantidad' => 1, 'precio' => 1, 'descuento_pct' => 0, 'no_recordar' => 1]],
    $usuario
));
Ventas::getErrores();

echo "Eliminar la cotización vendida\n";
$antes = $stock(37);
$prueba('eliminar cotización', Cotizaciones::eliminar($cot));
$prueba('el stock regresa', $stock(37) === $antes + 5, (string) $stock(37));
$prueba('la venta se conserva cancelada y sin cotización', $uno("SELECT estado FROM ventas WHERE id = $ventaId") === 'cancelada'
    && $uno("SELECT cotizacion_id FROM ventas WHERE id = $ventaId") === null);

echo "Concurrencia: dos peticiones marcan la misma cotización como vendida\n";
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, descuento, aplica_iva, iva, total)
            VALUES ('FOL-E3-0002', 1, $cliente, $usuario, '$hoy', 'pendiente', 100, 0, 1, 16, 116)");
$cot2 = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
            VALUES ($cot2, 37, 2, 50, 0, 100)");
$antes = $stock(37);
$hijo = "require '$raiz/includes/app.php'; @session_start(); \$_SESSION['id'] = $usuario; usleep(random_int(0, 20000)); App\\Cotizaciones::cambiarEstado($cot2, 'vendido', $usuario);";
$procesos = [];
for ($i = 0; $i < 4; $i++) {
    $procesos[] = proc_open(['php', '-r', $hijo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, $raiz, ['DB_NAME' => 'cotizaciones_test'] + getenv());
}
foreach ($procesos as $p) { proc_close($p); }
$prueba('4 peticiones simultáneas → 1 sola venta', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cot2") === 1,
    (string) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $cot2"));
$prueba('→ descuento único de 2 piezas', $stock(37) === $antes - 2, (string) ($antes - $stock(37)));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
