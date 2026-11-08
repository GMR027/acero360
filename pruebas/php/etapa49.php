<?php
/**
 * pruebas/php/etapa49.php — Factura ya registrada: estado, datos y XML.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa49.php
 *
 * Cubre el caso que dejaba errores sin salida en «Facturación y cobro»:
 *   · una venta facturada con CFDI ya no muestra errores de emisión;
 *   · el método/forma pueden quedar vacíos en una factura ya emitida;
 *   · un XML completa el ingreso y la ficha del cliente sin sobrescribir;
 *   · la reemisión sigue bloqueada.
 *
 * Usa clientes, ventas y un periodo lejanos (2032-06) con prefijo «T49» para
 * no tocar datos reales.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Clientes;
use App\Contabilidad;
use App\Ventas;
use App\VentasFiscal;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$contiene = static function (array $lista, string $fragmento): bool {
    foreach ($lista as $elemento) {
        if (str_contains((string) $elemento, $fragmento)) {
            return true;
        }
    }
    return false;
};

$usuario = (int) $db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_row()[0];
$fecha   = '2032-06-15';
$misVentas = [];

/* La validación exige el CP del emisor. La base de pruebas puede no tenerlo
 * cargado en la empresa 1: se pone uno temporal y se restaura al final. */
$cpEmpresaAntes = (string) $db->query('SELECT COALESCE(cp, "") FROM empresas WHERE id = 1')->fetch_row()[0];
$db->query("UPDATE empresas SET cp = '06000' WHERE id = 1");
register_shutdown_function(static function () use ($db, $cpEmpresaAntes) {
    $cp = $db->real_escape_string($cpEmpresaAntes);
    $db->query("UPDATE empresas SET cp = '$cp' WHERE id = 1");
});

$limpiar = function () use ($db, &$misVentas) {
    /* Se limpia por clientes T49, no sólo por las ventas de esta corrida: así
     * una ejecución interrumpida no deja filas que rompan la siguiente. */
    $clientesT49 = array_column(
        $db->query("SELECT id FROM clientes WHERE nombre_empresa LIKE 'T49 %'")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    if ($clientesT49) {
        $inClientes = implode(',', array_map('intval', $clientesT49));
        $ventasT49 = array_column(
            $db->query("SELECT id FROM ventas WHERE cliente_id IN ($inClientes)")->fetch_all(MYSQLI_ASSOC),
            'id'
        );
        if ($ventasT49) {
            $in = implode(',', array_map('intval', $ventasT49));
            $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
            $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
            $db->query("DELETE FROM ingresos WHERE venta_id IN ($in)");
            $db->query("DELETE FROM venta_detalles WHERE venta_id IN ($in)");
            $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN ($in)");
            $db->query("DELETE FROM ventas WHERE id IN ($in)");
        }
        $db->query("DELETE FROM ingresos WHERE cliente_id IN ($inClientes)");
        $db->query("DELETE FROM clientes WHERE id IN ($inClientes)");
    }
    $misVentas = [];
};
$limpiar();
$db->query('UPDATE productos SET stock = 100 WHERE id = 37');

$cliente = static function (string $sufijo, string $rfc, string $cp = '', string $regimen = '', string $uso = '') {
    $c = new Clientes([
        'nombre' => 'T49 Contacto ' . $sufijo,
        'nombre_empresa' => 'T49 Receptor ' . $sufijo,
        'rfc' => $rfc,
        'codigo_postal' => $cp,
        'regimen_fiscal' => $regimen,
        'uso_cfdi' => $uso,
    ]);
    $c->validar();
    if (!$c->crear()) {
        throw new RuntimeException('No se pudo crear el cliente T49: ' . implode(' · ', Clientes::getErrores()));
    }
    return (int) $c->id;
};
$venta = static function (int $clienteId) use ($usuario, $fecha, &$misVentas): int {
    if (!Ventas::crear(
        ['empresa_id' => 1, 'cliente_id' => $clienteId, 'fecha' => $fecha,
         'aplica_iva' => 1, 'descuento_pct' => 0, 'condiciones_compra' => ''],
        [37 => ['cantidad' => 2, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]],
        $usuario
    )) {
        throw new RuntimeException('No se pudo crear la venta T49: ' . implode(' · ', Ventas::getErrores()));
    }
    $misVentas[] = (int) Ventas::$ultimoId;
    return (int) Ventas::$ultimoId;
};

/* RFC del negocio emisor para el XML simulado. */
$rfcPropio = (string) $db->query(
    "SELECT COALESCE(NULLIF(e.rfc, ''), ct.rfc, '') FROM empresas e
      LEFT JOIN contribuyentes ct ON ct.id = COALESCE(e.contribuyente_id,
           (SELECT MIN(id) FROM contribuyentes WHERE activo = 1))
      WHERE e.id = 1 LIMIT 1"
)->fetch_row()[0];
$rfcPropio = VentasFiscal::normalizarRfc($rfcPropio);

echo "1. Normalización de los datos del CFDI\n";
$normal = VentasFiscal::datosDeCfdi([
    'uuid' => 'fa490000-0000-4000-8000-000000000049',
    'tipo' => 'I', 'fecha' => '2032-06-15T10:30:00', 'total' => '232.00',
    'emisor_rfc' => strtolower($rfcPropio), 'receptor_rfc' => 'aaa490101aaa',
    'receptor_cp' => '06600', 'receptor_regimen' => '601', 'uso_cfdi' => 'g03',
    'metodo_pago' => 'ppd', 'forma_pago' => '28', 'folio_comercial' => 'F49-1',
]);
$prueba('normaliza RFC, método, uso y UUID',
    $normal['rfc'] === 'AAA490101AAA' && $normal['metodo_pago'] === 'PPD'
    && $normal['uso_cfdi'] === 'G03' && $normal['uuid'] === 'FA490000-0000-4000-8000-000000000049');
$prueba('unifica la forma de pago 28 con 05', $normal['forma_pago'] === '05');
$prueba('recorta la fecha a Y-m-d', $normal['fecha'] === '2032-06-15');

echo "2. Venta pendiente que recibe su XML\n";
$clienteId = $cliente('A', 'AAA490101AAA');
$ventaId   = $venta($clienteId);
$ingresoId = (int) $db->query("SELECT id FROM ingresos WHERE venta_id = $ventaId")->fetch_row()[0];

$rev = VentasFiscal::validar($ventaId, true);
$prueba('sin método de pago no se puede emitir',
    $rev['puede_facturar'] === false && $contiene($rev['errores'], 'Falta el método de pago'));
$prueba('guardarPago vacío en una venta pendiente se rechaza',
    VentasFiscal::guardarPago($ventaId, '', '', $usuario) === false);

$cfdi = [
    'uuid' => 'FA490000-0000-4000-8000-000000000049', 'tipo' => 'I',
    'fecha' => $fecha . 'T10:30:00', 'total' => '232.00',
    'emisor_rfc' => $rfcPropio, 'receptor_rfc' => 'AAA490101AAA',
    'receptor_cp' => '06600', 'receptor_regimen' => '601', 'uso_cfdi' => 'G03',
    'metodo_pago' => 'PPD', 'forma_pago' => '99', 'folio_comercial' => 'F49-1',
];
$res = VentasFiscal::completarDesdeCfdi($ventaId, $cfdi, $usuario);
$prueba('el XML se aplica sin errores', $res['errores'] === [], implode(' · ', $res['errores']));
$prueba('se completan método, forma, uso y tipo',
    in_array('metodo_pago', $res['aplicados'], true)
    && in_array('forma_pago', $res['aplicados'], true)
    && in_array('uso_cfdi', $res['aplicados'], true)
    && in_array('tipo_cfdi', $res['aplicados'], true), implode(', ', $res['aplicados']));
$prueba('se completan CP, régimen y uso del cliente',
    in_array('codigo_postal', $res['aplicados'], true)
    && in_array('regimen_fiscal', $res['aplicados'], true)
    && in_array('uso_cfdi', $res['aplicados'], true));

$fila = $db->query("SELECT metodo_pago, forma_pago, uso_cfdi, tipo_cfdi FROM ingresos WHERE id = $ingresoId")->fetch_assoc();
$prueba('el ingreso queda con PPD/99/G03/I',
    $fila['metodo_pago'] === 'PPD' && $fila['forma_pago'] === '99'
    && $fila['uso_cfdi'] === 'G03' && $fila['tipo_cfdi'] === 'I', json_encode($fila));
$cli = $db->query("SELECT codigo_postal, regimen_fiscal, uso_cfdi FROM clientes WHERE id = $clienteId")->fetch_assoc();
$prueba('la ficha del cliente queda completa',
    $cli['codigo_postal'] === '06600' && $cli['regimen_fiscal'] === '601' && $cli['uso_cfdi'] === 'G03', json_encode($cli));

$rev2 = VentasFiscal::validar($ventaId, true);
$prueba('con el XML la validación de emisión queda aprobada',
    $rev2['puede_facturar'] === true, implode(' · ', $rev2['errores']));

echo "3. Venta ya facturada: estado y corrección\n";
$uuid = 'FA490000-0000-4000-8000-000000000049';
$prueba('se marca como facturada (confirmación manual)',
    Contabilidad::actualizarFacturacion($ingresoId, 'facturada', 'F49-1', $uuid, $fecha, $usuario, true),
    implode(' · ', Contabilidad::getErrores()));

$revFact = VentasFiscal::validar($ventaId);
$prueba('una facturada con CFDI no devuelve errores en pantalla',
    $revFact['puede_facturar'] === true && $revFact['errores'] === [], implode(' · ', $revFact['errores']));
$prueba('el estado del CFDI se informa como correcto',
    $contiene(array_column($revFact['items'], 'detalle'), 'ya está facturada con el CFDI'));
$prueba('la reemisión sigue bloqueada',
    (function () use ($ventaId, $usuario, $contiene): bool {
        $r = VentasFiscal::emitir($ventaId, false, '', $usuario);
        return !$r['ok'] && $contiene($r['errores'], 'ya tiene un CFDI válido');
    })());
$prueba('en una facturada se permite guardar sin método',
    VentasFiscal::guardarPago($ventaId, '', '', $usuario, true) === true);
$fila = $db->query("SELECT metodo_pago FROM ingresos WHERE id = $ingresoId")->fetch_row()[0];
$prueba('guardar sin método no borra el que ya estaba', $fila === 'PPD');

echo "4. No se sobrescriben datos existentes\n";
$clienteB = $cliente('B', 'BBB490101BBB', '12345', '601', 'G03');
$ventaB   = $venta($clienteB);
$ingresoB = (int) $db->query("SELECT id FROM ingresos WHERE venta_id = $ventaB")->fetch_row()[0];
VentasFiscal::guardarPago($ventaB, 'PUE', '03', $usuario);
$cfdiB = $cfdi;
$cfdiB['uuid'] = 'FA490000-0000-4000-8000-000000000050';
$cfdiB['receptor_rfc'] = 'BBB490101BBB';
$cfdiB['receptor_cp'] = '99999';
$cfdiB['receptor_regimen'] = '605';
$cfdiB['uso_cfdi'] = 'G01';
$cfdiB['metodo_pago'] = 'PPD';
$cfdiB['forma_pago'] = '99';

$resB = VentasFiscal::completarDesdeCfdi($ventaB, $cfdiB, $usuario);
$prueba('las diferencias se informan como discrepancias', count($resB['discrepancias']) >= 3,
    implode(' | ', $resB['discrepancias']));
$filaB = $db->query("SELECT metodo_pago, forma_pago FROM ingresos WHERE id = $ingresoB")->fetch_assoc();
$prueba('no se pisa el método/forma que ya tenía el ingreso',
    $filaB['metodo_pago'] === 'PUE' && $filaB['forma_pago'] === '03', json_encode($filaB));
$cliB = $db->query("SELECT codigo_postal, regimen_fiscal, uso_cfdi FROM clientes WHERE id = $clienteB")->fetch_assoc();
$prueba('no se pisa la ficha fiscal del cliente',
    $cliB['codigo_postal'] === '12345' && $cliB['regimen_fiscal'] === '601' && $cliB['uso_cfdi'] === 'G03', json_encode($cliB));

$limpiar();
echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
