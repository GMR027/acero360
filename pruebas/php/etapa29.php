<?php
/**
 * pruebas/php/etapa29.php — «Ventas y cobros»: dos tablas (facturadas y pendientes / sin factura).
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa29.php
 *
 * Comprueba:
 *   · cada ingreso cae en UNA sola tabla: las facturadas y las pendientes de
 *     facturar van juntas; las marcadas como «No facturada» van aparte;
 *   · las dos tablas suman exactamente el total de ingresos del periodo;
 *   · los totales de cada tabla coinciden con sus filas;
 *   · el filtro de facturación se puede combinar con cualquiera de las dos;
 *   · la vista trae los dos títulos y pagina cada tabla por su lado
 *     («pagina» para la primera, «pagina_sin» para la segunda);
 *   · la plantilla de paginación conserva «pagina» por defecto y acepta otro
 *     nombre de parámetro sin romper el resto de los listados.
 *
 * Los nombres llevan «T29» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Contabilidad;
use App\Ventas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

/** Limpia en orden de dependencias (las llaves foráneas lo exigen). */
$limpiar = function () use ($db) {
    foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) {
        $db->query("DELETE FROM $t");
    }
    $db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
    $db->query('DELETE FROM venta_cambios_entregados');
    $db->query('DELETE FROM venta_devoluciones');
    $db->query('DELETE FROM ventas');
    $db->query('DELETE FROM cotizaciones');
};
$limpiar();
$db->query('UPDATE productos SET stock = 100 WHERE id = 37');

/** Venta de prueba (hoy, producto 37). */
$venta = static function (int $iva) use ($db, $uno, $cliente, $usuario, $hoy): int {
    Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => $iva, 'descuento_pct' => 0],
        [37 => ['cantidad' => 1, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
    return (int) $uno('SELECT MAX(id) FROM ventas');
};
$ingresoDe = static fn (int $ventaId): int => (int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaId");

echo "Dos tablas\n";
$vConIva = $venta(1);        // con IVA → «Pendiente de facturar»
$vSinIva = $venta(0);        // sin IVA → «No facturada»
$iCon = Contabilidad::buscarIngreso($ingresoDe($vConIva));
$iSin = Contabilidad::buscarIngreso($ingresoDe($vSinIva));
$prueba('la venta con IVA empieza como pendiente de facturar', $iCon['estado_facturacion'] === 'sin_clasificar');
$prueba('la venta sin IVA empieza como no facturada', $iSin['estado_facturacion'] === 'no_facturada');

$anio = (int) date('Y');
$mes = (int) date('n');
$filtros = ['anio' => $anio, 'mes' => $mes];
$conFactura = $filtros + ['facturacion_grupo' => 'con_factura'];
$sinFactura = $filtros + ['facturacion_grupo' => 'sin_factura'];
$ids = static fn (array $f): array => array_map('intval', array_column(Contabilidad::listarIngresos($f, 200, 0), 'id'));

$idsCon = $ids($conFactura);
$idsSin = $ids($sinFactura);
$prueba('la venta con IVA cae en la tabla de facturables', in_array((int) $iCon['id'], $idsCon, true), json_encode($idsCon));
$prueba('la venta sin IVA cae en la tabla de «sin factura»', in_array((int) $iSin['id'], $idsSin, true), json_encode($idsSin));
$prueba('ninguna venta cae en las dos tablas', !array_intersect($idsCon, $idsSin));
$prueba('las dos tablas cubren todos los ingresos del periodo', count($idsCon) + count($idsSin) === Contabilidad::contarIngresos($filtros),
    count($idsCon) . '+' . count($idsSin) . ' vs ' . Contabilidad::contarIngresos($filtros));
$prueba('la tabla de facturables no muestra ninguna «No facturada»', !in_array('no_facturada', array_column(Contabilidad::listarIngresos($conFactura, 200, 0), 'estado_facturacion'), true));
$prueba('la tabla de «sin factura» sólo muestra esas', array_values(array_unique(array_column(Contabilidad::listarIngresos($sinFactura, 200, 0), 'estado_facturacion'))) === ['no_facturada']);

echo "Totales por tabla\n";
$totCon = Contabilidad::totalesIngresos($conFactura);
$totSin = Contabilidad::totalesIngresos($sinFactura);
$tot = Contabilidad::totalesIngresos($filtros);
$prueba('el conteo de cada tabla cuadra con sus filas', $totCon['n'] === count($idsCon) && $totSin['n'] === count($idsSin));
$prueba('los totales de las dos suman el total del periodo',
    abs(($totCon['total'] + $totSin['total']) - $tot['total']) < 0.005, ($totCon['total'] + $totSin['total']) . ' vs ' . $tot['total']);

echo "Filtro de facturación combinado\n";
$prueba('«Pendiente de facturar» dentro de la tabla de facturables',
    in_array((int) $iCon['id'], $ids($conFactura + ['facturacion' => 'sin_clasificar']), true));
$prueba('«Facturada» dentro de la tabla de facturables no trae la pendiente',
    !in_array((int) $iCon['id'], $ids($conFactura + ['facturacion' => 'facturada']), true));
$prueba('elegir «No facturada» vacía la tabla de facturables', Contabilidad::contarIngresos($conFactura + ['facturacion' => 'no_facturada']) === 0);
$prueba('y llena la de «sin factura»', in_array((int) $iSin['id'], $ids($sinFactura + ['facturacion' => 'no_facturada']), true));

echo "Pantallas y paginación\n";
$vista = (string) file_get_contents(__DIR__ . '/../../views/admin/contabilidad/ingresos/index.php');
$prueba('la vista nombra las dos tablas', str_contains($vista, 'Ventas con factura o pendientes de facturar')
    && str_contains($vista, 'Ventas indicadas como sin factura (no se facturarán)'));
$prueba('la vista usa los dos grupos y pagina cada tabla por su lado', substr_count($vista, 'facturacion_grupo') === 2
    && str_contains($vista, "'pagina_sin'") && str_contains($vista, "'parametro' => 'pagina_sin'"));
$plantilla = (string) file_get_contents(__DIR__ . '/../../templates/paginacion.php');
$prueba('la plantilla de paginación acepta otro parámetro', str_contains($plantilla, "\$parametro = \$parametro ?? 'pagina'"));

$_GET['pagina'] = '3';
$_GET['pagina_sin'] = '2';
$p = paginacion(15, 100);
$ps = paginacion(15, 100, 'pagina_sin');
$prueba('la primera tabla sigue paginando con «pagina»', $p['paginaActual'] === 3 && $p['offset'] === 30);
$prueba('la segunda usa «pagina_sin» sin pisarse', $ps['paginaActual'] === 2 && $ps['offset'] === 15);
unset($_GET['pagina'], $_GET['pagina_sin']);
$prueba('sin parámetro empieza en la 1', paginacion(15, 100)['paginaActual'] === 1);

$limpiar();

echo $fallos ? "\nResultado: $fallos fallos\n" : "\nResultado: TODO OK\n";
exit($fallos ? 1 : 0);
