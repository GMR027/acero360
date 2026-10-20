<?php
/**
 * pruebas/php/etapa15.php — Niveles de prioridad (urgente/alta/normal/informativa) con fechas y estados reales.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa15.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Contabilidad, Seguimiento};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$empresa = (int) $uno('SELECT id FROM empresas LIMIT 1');
$contrib = Contabilidad::contribuyentePrincipal();
$_SESSION['id'] = $usuario;
$aviso = function (string $clave) { foreach (Seguimiento::avisos() as $a) { if ($a['clave'] === $clave) { return $a; } } return null; };
$nivel = fn (string $clave) => $aviso($clave)['prioridad'] ?? '(sin aviso)';

echo "Niveles\n";
$prueba('hay 4 niveles con etiqueta e icono', array_keys(Seguimiento::NIVELES) === ['urgente', 'alta', 'normal', 'informativa']
    && !array_filter(Seguimiento::NIVELES, fn ($n) => count($n) !== 3 || in_array('', $n, true)));

echo "Productos y novedades\n";
$db->query("INSERT INTO productos (empresa_id, nombre, activo, stock, stock_minimo, precio) VALUES ($empresa, '[P15] agotado', 1, 0, 5, 1), ($empresa, '[P15] bajo', 1, 3, 5, 1)");
$agotado = $db->insert_id; $bajo = $agotado + 1;
$prueba('sin existencias → urgente', $nivel("stock:$agotado") === 'urgente', $nivel("stock:$agotado"));
$prueba('stock bajo pero con piezas → normal', $nivel("stock:$bajo") === 'normal', $nivel("stock:$bajo"));
$prueba('novedad → informativa', $nivel('novedad:' . Seguimiento::NOVEDADES[0][0]) === 'informativa');

echo "Cotizaciones: vencimiento = fecha + vigencia\n";
$cot = function (string $folio, int $diasAtras, int $vigencia) use ($db, $empresa, $cliente, $usuario) {
    $f = date('Y-m-d', strtotime("-$diasAtras days"));
    $db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total, vigencia_dias)
                VALUES ('$folio', $empresa, $cliente, $usuario, '$f', 'pendiente', 100, 0, 0, 100, $vigencia)");
    return $db->insert_id;
};
$vencida = $cot('P15-V', 20, 15); $porVencer = $cot('P15-X', 14, 15); $holgada = $cot('P15-H', 2, 15);
$prueba('vigencia vencida → alta', $nivel("cotizacion:$vencida") === 'alta');
$prueba('vence mañana → alta', $nivel("cotizacion:$porVencer") === 'alta');
$prueba('con margen → normal', $nivel("cotizacion:$holgada") === 'normal');
$prueba('el detalle dice la fecha real de vencimiento', str_contains($aviso("cotizacion:$vencida")['detalle'], 'vigencia vencida hace 5'));

echo "Cobro y facturación son independientes\n";
$ingreso = function (string $folio, string $fact) use ($db, $contrib, $empresa, $cliente) {
    $f = date('Y-m-d', strtotime('-40 days'));
    $db->query("INSERT INTO ingresos (contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto, subtotal, iva, total, estado, estado_facturacion)
                VALUES ($contrib, $empresa, $cliente, '$f', '$folio', 'P15', 1000, 0, 1000, 'vigente', '$fact')");
    return $db->insert_id;
};
$cobradaPendFact = $ingreso('P15-A', 'sin_clasificar');
$prueba('registrar cobro completo', Contabilidad::registrarCobro($cobradaPendFact, date('Y-m-d'), '1000', 'Efectivo', '', $usuario));
$sinCobrarPendFact = $ingreso('P15-B', 'sin_clasificar');
$facturadaSinCobrar = $ingreso('P15-C', 'facturada');
$prueba('cobrada y pendiente de facturar → factura alta', $nivel("factura:$cobradaPendFact") === 'alta');
$prueba('  y ya no hay aviso de cobro', $aviso("cobro:$cobradaPendFact") === null);
$prueba('sin cobrar y pendiente de facturar → factura normal (40 días no inventan vencimiento)', $nivel("factura:$sinCobrarPendFact") === 'normal');
$prueba('  y su cobro sigue normal', $nivel("cobro:$sinCobrarPendFact") === 'normal');
$prueba('facturada y sin cobrar → cobro normal, sin aviso de factura', $nivel("cobro:$facturadaSinCobrar") === 'normal' && $aviso("factura:$facturadaSinCobrar") === null);
$prueba('el aviso de factura menciona el estado de cobro', str_contains($aviso("factura:$cobradaPendFact")['detalle'], 'ya cobrada'));

echo "Sin duplicados\n";
$claves = array_column(Seguimiento::avisos(), 'clave');
$prueba('claves únicas', count($claves) === count(array_unique($claves)));
$prueba('recalcular da las mismas claves', $claves === array_column(Seguimiento::avisos(), 'clave'));

echo "Botones\n";
$prueba('cada tipo de aviso tiene texto de botón específico', Seguimiento::etiquetaAbrir('factura:1') !== 'Abrir registro' && Seguimiento::etiquetaAbrir('entrega:1') === 'Ver entrega pendiente');

$db->query("DELETE FROM cobros WHERE ingreso_id IN ($cobradaPendFact)");
$db->query("DELETE FROM ingresos WHERE concepto = 'P15'");
$db->query("DELETE FROM cotizaciones WHERE folio LIKE 'P15-%'");
$db->query("DELETE FROM productos WHERE nombre LIKE '[P15]%'");
echo $fallos ? "\n$fallos FALLA(S)\n" : "\nTODO OK\n";
exit($fallos ? 1 : 0);
