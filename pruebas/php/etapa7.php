<?php
/**
 * pruebas/php/etapa7.php — estadísticas históricas (montos esperados calculados a mano).
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa7.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Contabilidad, Cotizaciones, Estadisticas, Exportador, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) { $db->query("DELETE FROM $t"); }
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones');
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$clientes = array_column($db->query('SELECT id FROM clientes LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'id');
[$c1, $c2] = [(int) $clientes[0], (int) $clientes[1]];
$_SESSION['id'] = $usuario;
$db->query('UPDATE productos SET stock = 10000 WHERE id IN (36, 37)');

$L = fn (int $c, float $p) => ['cantidad' => $c, 'precio' => $p, 'descuento_pct' => 0, 'no_recordar' => 1];
$venta = function (string $fecha, int $cliente, int $empresa, array $lineas) use ($usuario, $db) {
    Ventas::crear(['empresa_id' => $empresa, 'cliente_id' => $cliente, 'fecha' => $fecha, 'aplica_iva' => 1, 'descuento_pct' => 0], $lineas, $usuario);
    return (int) $db->query('SELECT id FROM ingresos ORDER BY id DESC LIMIT 1')->fetch_row()[0];
};
$a = $venta('2025-01-10', $c1, 1, [37 => $L(2, 500)]);   // 1,000 + 160 = 1,160
$b = $venta('2025-01-20', $c2, 2, [36 => $L(1, 300)]);   //   300 +  48 =   348
$c = $venta('2026-01-05', $c1, 1, [37 => $L(3, 500)]);   // 1,500 + 240 = 1,740
$d = $venta('2026-01-25', $c1, 1, [36 => $L(2, 300)]);   //   600 +  96 =   696
$e = $venta('2026-04-15', $c2, 2, [36 => $L(4, 300)]);   // 1,200 + 192 = 1,392 (T2 2026)

Contabilidad::actualizarFacturacion($a, 'facturada', 'A1', '', '2025-01-11', $usuario, true);
Contabilidad::actualizarFacturacion($c, 'facturada', 'A2', '', '2026-01-06', $usuario, true);
Contabilidad::registrarCobro($a, '2025-01-15', 500, 'Efectivo', '', $usuario);
Contabilidad::registrarCobro($c, '2026-02-02', 1740, 'Transferencia', '', $usuario);   // cobra en FEBRERO

echo "Comparación mes a mes (enero 2026 contra enero 2025)\n";
$cmp = Estadisticas::comparar('mes', 1, 2026, 1);
[$y25, $y26] = $cmp;
$prueba('enero 2025: $1,508.00 en 2 operaciones y 3 piezas', $y25['realizadas'] === ['importe' => 150800, 'operaciones' => 2, 'unidades' => 3], json_encode($y25['realizadas']));
$prueba('enero 2026: $2,436.00 en 2 operaciones y 5 piezas', $y26['realizadas'] === ['importe' => 243600, 'operaciones' => 2, 'unidades' => 5], json_encode($y26['realizadas']));
$prueba('variación absoluta +$928.00 y porcentual +61.54 %', $y26['variacion'] === ['absoluta' => 92800, 'porcentual' => 61.54], json_encode($y26['variacion']));
$prueba('ambos periodos están completos', $y25['completo'] && $y26['completo']);
$prueba('realizadas, facturadas y cobradas NO son lo mismo: 2026 → 2,436 / 1,740 / 0', $y26['facturadas']['importe'] === 174000 && $y26['cobros']['importe'] === 0, json_encode([$y26['facturadas'], $y26['cobros']]));
$prueba('2025 → 1,508 / 1,160 / 500', $y25['facturadas']['importe'] === 116000 && $y25['cobros']['importe'] === 50000);
$sinIva = Estadisticas::comparar('mes', 1, 2026, 1, [], 'subtotal');
$prueba('sin IVA: $1,300 contra $2,100', $sinIva[0]['realizadas']['importe'] === 130000 && $sinIva[1]['realizadas']['importe'] === 210000);
$feb = Estadisticas::comparar('mes', 2, 2026, 0)[0];
$prueba('el cobro cae en febrero (cobros $1,740), no en enero', $feb['cobros']['importe'] === 174000 && $feb['realizadas']['importe'] === 0);

echo "Trimestre, semestre y año\n";
$t1 = Estadisticas::comparar('trimestre', 1, 2026, 1);
$prueba('T1: 2026 = 2,436 y 2025 = 1,508', $t1[1]['realizadas']['importe'] === 243600 && $t1[0]['realizadas']['importe'] === 150800);
$t2 = Estadisticas::comparar('trimestre', 2, 2026, 1);
$prueba('T2 2026 = $1,392.00 (solo la venta de abril)', $t2[1]['realizadas']['importe'] === 139200 && $t2[0]['realizadas']['importe'] === 0);
$prueba('contra una base en cero el % es nulo (no se inventa)', $t2[1]['variacion']['porcentual'] === null && $t2[1]['variacion']['absoluta'] === 139200);
$s1 = Estadisticas::comparar('semestre', 1, 2026, 1);
$prueba('primer semestre 2026 = 2,436 + 1,392 = 3,828', $s1[1]['realizadas']['importe'] === 382800, (string) $s1[1]['realizadas']['importe']);
$anio = Estadisticas::comparar('anio', 1, 2026, 1, [], 'total', '2026-10-19');
$prueba('año 2026 está INCOMPLETO y 2025 completo', !$anio[1]['completo'] && $anio[0]['completo'] && $anio[1]['corte'] === '2026-10-19');
$prueba('año 2026 acumulado 3,828; año 2025 completo 1,508', $anio[1]['realizadas']['importe'] === 382800 && $anio[0]['realizadas']['importe'] === 150800);
$prueba('compara además contra el mismo avance de 2025 (al 19-oct): 1,508', ($anio[1]['previo_mismo_avance']['importe'] ?? -1) === 150800
    && $anio[1]['variacion_mismo_avance']['absoluta'] === 232000, json_encode($anio[1]['variacion_mismo_avance'] ?? null));
$futuro = Estadisticas::comparar('mes', 12, 2026, 0, [], 'total', '2026-10-19')[0];
$prueba('un mes futuro se marca y queda en cero', $futuro['futuro'] && !$futuro['completo'] && $futuro['realizadas']['importe'] === 0);
$mesParcial = Estadisticas::comparar('mes', 10, 2026, 1, [], 'total', '2026-10-19');
$prueba('octubre 2026 es parcial (corte 19-oct) y compara con octubre 2025 hasta el 19', !$mesParcial[1]['completo'] && $mesParcial[1]['corte'] === '2026-10-19' && isset($mesParcial[1]['previo_mismo_avance']));

echo "Filtros\n";
$neg2 = Estadisticas::comparar('mes', 1, 2026, 1, ['empresa' => 2]);
$prueba('solo el negocio 2: 2025 = $348.00 y 2026 = $0', $neg2[0]['realizadas']['importe'] === 34800 && $neg2[1]['realizadas']['importe'] === 0);
$cli = Estadisticas::comparar('mes', 1, 2026, 1, ['cliente' => $c1]);
$prueba('solo el cliente 1: 2025 = 1,160 y 2026 = 2,436', $cli[0]['realizadas']['importe'] === 116000 && $cli[1]['realizadas']['importe'] === 243600);
$prod = Estadisticas::comparar('mes', 1, 2026, 1, ['producto' => 37]);
$prueba('solo el producto 37 (renglones sin IVA): 2025 = 1,000 · 2 pzas; 2026 = 1,500 · 3 pzas',
    $prod[0]['realizadas'] === ['importe' => 100000, 'operaciones' => 1, 'unidades' => 2] && $prod[1]['realizadas'] === ['importe' => 150000, 'operaciones' => 1, 'unidades' => 3], json_encode([$prod[0]['realizadas'], $prod[1]['realizadas']]));
$prueba('con producto, facturadas y cobros no aplican (no se reparten)', $prod[1]['facturadas']['importe'] === 0 && $prod[1]['cobros']['importe'] === 0);

echo "Series por día, mes, trimestre, semestre y año\n";
$mes = Estadisticas::serie('realizadas', 'mes', '2025-01-01', '2026-12-31');
$prueba('por mes: 2025-01, 2026-01 y 2026-04', array_keys($mes) === ['2025-01', '2026-01', '2026-04'], json_encode(array_keys($mes)));
$dia = Estadisticas::serie('realizadas', 'dia', '2026-01-01', '2026-01-31');
$prueba('por día: 5 y 25 de enero', array_keys($dia) === ['2026-01-05', '2026-01-25'] && $dia['2026-01-25']['importe'] === 69600);
$tri = Estadisticas::serie('realizadas', 'trimestre', '2026-01-01', '2026-12-31');
$prueba('por trimestre: 2026-T1 y 2026-T2', array_keys($tri) === ['2026-T1', '2026-T2']);
$sem = Estadisticas::serie('realizadas', 'semestre', '2025-01-01', '2026-12-31');
$prueba('por semestre: 2025-S1 y 2026-S1', array_keys($sem) === ['2025-S1', '2026-S1']);
$anios = Estadisticas::serie('realizadas', 'anio', '2025-01-01', '2026-12-31');
$prueba('por año: 2025 y 2026', array_map('strval', array_keys($anios)) === ['2025', '2026'] && $anios['2026']['importe'] === 382800);
$prueba('la suma de los meses = la suma del año (no se duplica nada)', array_sum(array_column($mes, 'importe')) === array_sum(array_column($anios, 'importe')));

echo "Cotizaciones convertidas en venta: una sola vez\n";
$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, iva, total) VALUES ('FOL-E7-0001', 1, $c2, $usuario, '2026-05-10', 'pendiente', 500, 80, 580)");
$cot = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($cot, 37, 1, 500, 500)");
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$may = Estadisticas::comparar('mes', 5, 2026, 0)[0];
$prueba('cotización vendida (marcada 2 veces) = 1 operación, $580.00', $may['realizadas'] === ['importe' => 58000, 'operaciones' => 1, 'unidades' => 1], json_encode($may['realizadas']));
Cotizaciones::cambiarEstado($cot, 'pendiente', $usuario);
$prueba('si deja de estar vendida, sale de las estadísticas', Estadisticas::comparar('mes', 5, 2026, 0)[0]['realizadas']['importe'] === 0);

echo "Rankings\n";
$prod2 = Estadisticas::topProductos('2026-01-01', '2026-01-31');
$prueba('producto más vendido en enero 2026: 37 con 3 pzas y $1,500', $prod2[0]['producto_id'] === 37 && $prod2[0]['unidades'] === 3 && $prod2[0]['importe'] === 150000 && $prod2[1]['producto_id'] === 36);
$prodAnio = Estadisticas::topProductos('2026-01-01', '2026-12-31');
$prueba('los productos salen separados por negocio y con su código (SKU)', $prodAnio[0]['negocio'] === 'Soluciones en Acero' && $prodAnio[0]['sku'] === 'RA-3'
    && $prodAnio[2]['empresa_id'] === 2 && $prodAnio[2]['producto_id'] === 36 && $prodAnio[2]['importe'] === 120000, json_encode($prodAnio));
$cli2 = Estadisticas::topClientes('2026-01-01', '2026-12-31');
$prueba('cliente con mayor volumen en 2026: cliente 1 (1,740 + 696 = 2,436)', $cli2[0]['cliente_id'] === $c1 && $cli2[0]['importe'] === 243600 && $cli2[0]['operaciones'] === 2);
$prueba('los clientes salen separados por negocio', $cli2[0]['negocio'] === 'Soluciones en Acero' && $cli2[1]['empresa_id'] === 2 && $cli2[1]['cliente_id'] === $c2, json_encode($cli2));
$menor = \App\Analisis::menorMovimiento('2026-01-01', '2026-12-31');
$prueba('menor movimiento también separa por negocio y trae el código', $menor[0]['negocio'] === 'Soluciones en Acero' && $menor[0]['sku'] === 'RA-1' && end($menor)['empresa_id'] === 2, json_encode($menor));
$mat = Estadisticas::porAnios('producto', 2025, 2026);
$fila37 = array_values(array_filter($mat['filas'], fn ($f) => str_contains($f['nombre'], 'RA-3')))[0] ?? null;
$prueba('producto por varios años: RA-3 = 1,000 (2025) y 1,500 (2026)', $fila37 && $fila37['por_anio'] === [2025 => 100000, 2026 => 150000], json_encode($fila37));
$matC = Estadisticas::porAnios('cliente', 2025, 2026);
$prueba('cliente por varios años: cliente 1 = 1,160 (2025) y 2,436 (2026)', ($matC['filas'][0]['por_anio'] ?? []) === [2025 => 116000, 2026 => 243600], json_encode($matC['filas'][0] ?? null));

echo "Tendencia\n";
$prueba('ventas crecientes → al alza', Estadisticas::tendencia([100, 200, 300, 400])['direccion'] === 'alza');
$prueba('ventas decrecientes → a la baja', Estadisticas::tendencia([400, 300, 200, 100])['direccion'] === 'baja');
$prueba('ventas planas → estable', Estadisticas::tendencia([100, 101, 100, 99])['direccion'] === 'estable');
$prueba('pocos datos → sin datos (no se inventa)', Estadisticas::tendencia([100, 200])['direccion'] === 'sin_datos' && Estadisticas::tendencia([0, 0, 0])['direccion'] === 'sin_datos');
$completa = Estadisticas::serieMensualCompleta('realizadas', '2026-01-01', '2026-04-30');
$prueba('la serie mensual completa rellena febrero y marzo con cero', array_keys($completa) === ['2026-01', '2026-02', '2026-03', '2026-04'] && $completa['2026-02']['importe'] === 0);

echo "Solo lectura\n";
$antes = $uno('SELECT CONCAT(COUNT(*), "/", SUM(total)) FROM ventas') . $uno('SELECT SUM(stock) FROM productos');
Estadisticas::comparar('anio', 1, 2026, 3); Estadisticas::topProductos('2025-01-01', '2026-12-31'); Estadisticas::porAnios('cliente', 2024, 2026);
$prueba('consultar estadísticas no cambia ventas ni inventario', $antes === $uno('SELECT CONCAT(COUNT(*), "/", SUM(total)) FROM ventas') . $uno('SELECT SUM(stock) FROM productos'));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
