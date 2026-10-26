<?php
/**
 * pruebas/php/etapa10.php — regresión transversal: cotizaciones (PDF, precio especial, descuentos e IVA) y
 * reportes de inventario (exportaciones, historial de movimientos, fotografías mensuales).
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa10.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Cotizaciones, Documentos, Exportador, InventarioReportes, Inventario, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones', 'inventario_corte_detalles', 'inventario_cortes'] as $t) { $db->query("DELETE FROM $t"); }
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL OR recepcion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones'); $db->query('DELETE FROM precios_cliente');
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$_SESSION['id'] = $usuario;
$db->query('UPDATE productos SET stock = 100, costo_actual = 40, stock_minimo = NULL WHERE id IN (36, 37)');
$db->query('UPDATE productos SET stock_minimo = 120 WHERE id = 36');
$precioBase37 = $uno('SELECT precio FROM productos WHERE id = 37');

echo "Cotización: PDF, precio especial, descuentos e IVA\n";
$lineas = [37 => ['cantidad' => 4, 'precio' => 90.00, 'descuento_pct' => 10, 'no_recordar' => 0], 36 => ['cantidad' => 2, 'precio' => 50.00, 'descuento_pct' => 0, 'no_recordar' => 1]];
$t = Cotizaciones::calcular($lineas, 5, true);
// 4×90 = 360 − 10 % (36) = 324; 2×50 = 100 → subtotal 460, desc. renglón 36; base 424 − 5 % global (21.20) = 402.80; IVA 16 % = 64.45; total 467.25
$prueba('cálculo: subtotal 460.00, descuentos 57.20, IVA 64.45, total 467.25', $t['subtotal'] === 460.0 && $t['descuento'] === 57.2 && $t['iva'] === 64.45 && $t['total'] === 467.25, json_encode($t));
$sinIva = Cotizaciones::calcular($lineas, 5, false);
$prueba('sin IVA: total 402.80', $sinIva['iva'] === 0.0 && $sinIva['total'] === 402.8);
$ok = Cotizaciones::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => date('Y-m-d'), 'vigencia_dias' => 15, 'aplica_iva' => 1, 'estado' => 'pendiente', 'descuento_pct' => 5], $lineas, $usuario);
$cot = (int) $uno('SELECT MAX(id) FROM cotizaciones');
$prueba('crea la cotización', $ok && $cot > 0, implode('|', Cotizaciones::getErrores()));
$c = Cotizaciones::buscarID($cot);
$prueba('guarda subtotal, descuento, IVA y total calculados', (float) $c->total === 467.25 && (float) $c->iva === 64.45);
$prueba('el precio especial NO altera el precio base del catálogo', $uno('SELECT precio FROM productos WHERE id = 37') === $precioBase37);
$prueba('el precio especial se recuerda para ese cliente (y el marcado «no recordar» no)', $uno("SELECT precio FROM precios_cliente WHERE cliente_id = $cliente AND producto_id = 37") === '90.00'
    && (int) $uno("SELECT COUNT(*) FROM precios_cliente WHERE cliente_id = $cliente AND producto_id = 36") === 0);
$pdf = Documentos::pdf($c, Cotizaciones::detalles($cot));
$prueba('exporta la cotización a PDF', is_string($pdf) && str_starts_with($pdf, '%PDF-') && strlen($pdf) > 2000);
$antes = $uno('SELECT SUM(stock) FROM productos');
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$prueba('al venderla el inventario baja una sola vez (6 piezas)', (int) $antes - (int) $uno('SELECT SUM(stock) FROM productos') === 6);

echo "Exportación del inventario\n";
[$enc, $filas] = InventarioReportes::filasActual(InventarioReportes::actual(['estado' => 'todos']));
$prueba('exporta todos los productos con costo, precio y valor', count($filas) === (int) $uno('SELECT COUNT(*) FROM productos') && count($enc) === 12);
$f36 = array_values(array_filter($filas, fn ($f) => $f[0] === $uno('SELECT sku FROM productos WHERE id = 36')))[0];
$prueba('producto bajo el mínimo marcado: «Bajo el mínimo»', $f36[6] === 'Bajo el mínimo', json_encode($f36));
$prueba('valor al costo = existencias × costo', $f36[9] === round($f36[4] * 40, 2));
[, $soloMinimo] = InventarioReportes::filasActual(InventarioReportes::actual(['estado' => 'minimo']));
// La base puede tener otros productos con mínimo definido (datos reales): se
// compara contra la consulta, no contra un número fijo.
$enMinimo = (int) $uno("SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock_minimo IS NOT NULL AND stock <= stock_minimo");
$skusMinimo = array_map(static fn ($f) => $f[0], $soloMinimo);
$prueba('filtro «bajo el mínimo»: sólo productos en o bajo su mínimo (incluye el de la prueba)',
    count($soloMinimo) === $enMinimo && in_array($uno('SELECT sku FROM productos WHERE id = 36'), $skusMinimo, true),
    'filtro=' . count($soloMinimo) . ' bd=' . $enMinimo);
[, $neg2] = InventarioReportes::filasActual(InventarioReportes::actual(['empresa' => 2]));
$prueba('filtro por negocio', count($neg2) === (int) $uno('SELECT COUNT(DISTINCT producto_id) FROM producto_empresa WHERE empresa_id = 2'));
[, $busca] = InventarioReportes::filasActual(InventarioReportes::actual(['q' => 'RA-3']));
$prueba('filtro por búsqueda', count($busca) >= 1 && count($busca) < count($filas));
$csv = Exportador::csv($enc, $filas);
$prueba('CSV con BOM y encabezados', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'Valor al costo'));

echo "Historial de movimientos\n";
$total = InventarioReportes::contarMovimientos([]);
$prueba('hay movimientos (salida por la venta)', $total >= 2);
$ventas = InventarioReportes::movimientos(['motivo' => 'venta'], 50, 0);
$prueba('filtra por motivo «venta»', count($ventas) >= 2 && !array_filter($ventas, fn ($m) => $m['motivo'] !== 'venta'));
$prueba('cada movimiento trae el folio de su venta de origen', $ventas[0]['venta_folio'] !== null);
$prueba('filtra por producto', count(InventarioReportes::movimientos(['producto' => 37], 50, 0)) >= 1);
$prueba('filtra por rango de fechas (futuro → vacío)', InventarioReportes::contarMovimientos(['desde' => date('Y-m-d', strtotime('+10 days'))]) === 0);
[$encM, $filasM] = InventarioReportes::filasMovimientos(['motivo' => 'venta']);
$prueba('filas de exportación: salida con cantidad negativa y saldo', $filasM[0][5] < 0 && $filasM[0][6] !== '' && count($encM) === 10);

echo "Fotografías mensuales\n";
$mes = (int) date('n'); $anio = (int) date('Y');
$stockAntes = $uno('SELECT SUM(stock) FROM productos');
$prueba('genera la fotografía del mes', InventarioReportes::generarCorte($anio, $mes, $usuario), implode('|', InventarioReportes::getErrores()));
$corte = InventarioReportes::cortes()[0];
$prueba('guarda productos, piezas y valor', (int) $corte['productos'] === (int) $uno('SELECT COUNT(*) FROM productos') && (int) $corte['piezas'] === (int) $stockAntes);
$prueba('generarla NO modifica el inventario', $uno('SELECT SUM(stock) FROM productos') === $stockAntes);
$prueba('una fotografía no se reemplaza', !InventarioReportes::generarCorte($anio, $mes, $usuario) && str_contains(implode(' ', InventarioReportes::getErrores()), 'Ya existe'));
$prueba('no se toma un mes futuro', !InventarioReportes::generarCorte($anio + 1, 1, $usuario)); InventarioReportes::getErrores();
$db->query('UPDATE productos SET stock = stock + 7 WHERE id = 37');
$cmp = array_column(InventarioReportes::comparar((int) $corte['id']), null, 'producto_id');
$prueba('la comparación muestra +7 en el producto 37 y 0 en el resto', $cmp[37]['diferencia'] === 7 && $cmp[36]['diferencia'] === 0 && $cmp[37]['antes'] + 7 === $cmp[37]['ahora']);
$prueba('la fotografía conserva las existencias del momento (no cambian)', (int) $uno("SELECT stock FROM inventario_corte_detalles WHERE corte_id = {$corte['id']} AND producto_id = 37") === $cmp[37]['antes']);
$db->query("INSERT INTO productos (empresa_id, nombre, sku, precio, stock) VALUES (1, 'Producto nuevo E10', 'E10-1', 10, 5)");
$nuevoId = (int) $db->insert_id;
$prueba('un producto creado después aparece como «No existía»', array_column(InventarioReportes::comparar((int) $corte['id']), null, 'producto_id')[$nuevoId]['antes'] === null);
$db->query("DELETE FROM productos WHERE id = $nuevoId");
$prueba('una fotografía tomada tras el cierre del mes se marca como tardía', InventarioReportes::esTardia(['anio' => $anio - 1, 'mes' => 1, 'generado_en' => date('Y-m-d H:i:s')]) && !InventarioReportes::esTardia(['anio' => $anio, 'mes' => $mes, 'generado_en' => date('Y-m-d H:i:s')]));
$db->query('UPDATE productos SET stock = stock - 7 WHERE id = 37');

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
