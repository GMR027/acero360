<?php
/**
 * pruebas/php/etapa42.php — Preliminar básico de impuestos (simulador del documento Fiscal 2026).
 *
 * Verifica el simulador independiente del MotorFiscal, alimentado con las
 * facturas reales de la base:
 *
 *   1. VENTAS: sólo ingresos vigentes facturados, con su mes fiscal (la fecha
 *      del CFDI si existe, si no la de la venta). Fuera las no facturadas y
 *      las canceladas, aunque sean del año.
 *   2. COMPRAS: sólo gastos vigentes con comprobante fiscal. Fuera los sin
 *      comprobante y los cancelados.
 *   3. IMPORTES REALES: bases con el subtotal real (con descuento), IVA real
 *      por fila (16 %, 8 % y 0 %: las exentas entran con IVA 0, no dividido
 *      entre 1.16), ISR plano del 32 % sobre la utilidad del mes e
 *      impuestos = IVA + ISR (mes negativo permitido, como en el documento).
 *   4. AÑO: la suma de los 12 meses, no un recálculo sobre el total anual.
 *   5. LISTADOS: clientes y proveedores/compras del mes con sus totales.
 *   6. PANTALLA Y PDF: la pestaña /admin/fiscal/preliminar existe (chip, ruta,
 *      exportar) y la plantilla del PDF compone bytes reales de PDF con dompdf.
 *
 * No se toca el MotorFiscal ni la pestaña Impuestos: sólo se consume lo que
 * ya existe. Las ventas y gastos de prueba llevan el prefijo T42 y se limpian.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa42.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\PreliminarFiscal;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$empresa = (int) $uno('SELECT id FROM empresas LIMIT 1');
$anio = 2026;

/* -------------------------------------------------------------------- *
 * LIMPIEZA (idempotente): todo lo de esta etapa lleva el prefijo T42
 * ------------------------------------------------------------------ */
$limpiar = static function () use ($db) {
    $db->query("DELETE FROM ingresos WHERE folio LIKE 'T42-%'");
    $db->query("DELETE FROM gastos WHERE concepto LIKE 'T42%'");
    $db->query("DELETE FROM clientes WHERE nombre LIKE 'T42%'");
    $db->query("DELETE FROM contribuyentes WHERE rfc = 'T42PF0101AA1'");
};
$limpiar();

/* Contribuyente propio para la prueba: el de la base trae datos reales de 2026
 * y aquí se quieren importes EXACTOS, sin depender de lo que haya capturado. */
$db->query("INSERT INTO contribuyentes (rfc, razon_social, tipo_persona, regimen, activo) VALUES ('T42PF0101AA1', 'T42 Preliminar SA', 'fisica', 'Actividad empresarial', 1)");
$contrib = (int) $db->insert_id;

echo "Siembra (ventas facturadas, exentas, sin facturar y canceladas; gastos con y sin comprobante)\n";
$db->query("INSERT INTO clientes (nombre, nombre_empresa) VALUES ('T42 Contacto', 'T42 Cliente SA')");
$clienteEmpresa = (int) $db->insert_id;
$db->query("INSERT INTO clientes (nombre) VALUES ('T42 Persona')");
$clientePersona = (int) $db->insert_id;

$ingreso = static function (string $folio, string $fecha, string $fact, string $estado, int $cliente,
                            float $subtotal, float $descuento, float $iva, ?string $fechaFac) use ($db, $contrib, $empresa) {
    $db->query("INSERT INTO ingresos (contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto,
                subtotal, descuento, iva, total, estado, estado_facturacion, fecha_facturacion)
            VALUES ($contrib, $empresa, $cliente, '$fecha', '$folio', 'T42', $subtotal, $descuento, $iva,
                " . ($subtotal - $descuento + $iva) . ", '$estado', '$fact'" . ($fechaFac ? ", '$fechaFac'" : ', NULL') . ')');
};
$gasto = static function (string $fecha, string $concepto, float $subtotal, float $iva, int $comprobante, string $estado) use ($db, $contrib, $usuario) {
    $db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id,
                subtotal, iva_tasa, iva, total, estado_pago, comprobante_fiscal, revision_fiscal, estado, usuario_id)
            VALUES ($contrib, '$fecha', 'T42 Proveedor', '$concepto', 'servicio', NULL,
                $subtotal, 16.00, $iva, " . ($subtotal + $iva) . ", 'pagado', $comprobante, 'validado', '$estado', $usuario)");
};

/* Enero: una venta facturada el 15-ene (1 000 + 160 IVA). */
$ingreso('T42-V1', '2026-01-15', 'facturada', 'vigente', $clienteEmpresa, 1000, 0, 160, '2026-01-15');
/* Enero: venta NO facturada y venta facturada CANCELADA → ninguna entra. */
$ingreso('T42-V2', '2026-01-20', 'no_facturada', 'vigente', $clientePersona, 500, 0, 80, null);
$ingreso('T42-V3', '2026-01-22', 'facturada', 'cancelado', $clientePersona, 400, 0, 64, '2026-01-22');
/* Venta del 28-ene facturada el 2-feb: cuenta en FEBRERO (mes del CFDI), con descuento. */
$ingreso('T42-V4', '2026-01-28', 'facturada', 'vigente', $clienteEmpresa, 900, 100, 144, '2026-02-02');
/* Marzo: venta facturada EXENTA (IVA 0): entra con su IVA real 0. */
$ingreso('T42-V5', '2026-03-10', 'facturada', 'vigente', $clientePersona, 200, 0, 0, '2026-03-10');
/* 2027: fuera del año probado. */
$ingreso('T42-V6', '2027-01-10', 'facturada', 'vigente', $clienteEmpresa, 999, 0, 159.84, '2027-01-10');

/* Gastos: enero con comprobante (500 + 80) · sin comprobante (fuera) · cancelado (fuera). */
$gasto('2026-01-20', 'T42 gasto facturado enero', 500, 80, 1, 'vigente');
$gasto('2026-01-21', 'T42 gasto SIN comprobante', 300, 48, 0, 'vigente');
$gasto('2026-01-22', 'T42 gasto cancelado', 200, 32, 1, 'cancelado');
/* Febrero: gasto exento con comprobante (IVA 0). Marzo: gasto con IVA 8 %. */
$gasto('2026-02-05', 'T42 gasto exento febrero', 100, 0, 1, 'vigente');
$gasto('2026-03-05', 'T42 gasto marzo 8%', 100, 8, 1, 'vigente');

/* -------------------------------------------------------------------- *
 * 1. EL CÁLCULO POR MES (importes reales, no ÷1.16)
 * ------------------------------------------------------------------ */
$r = PreliminarFiscal::anual($contrib, $anio);

$prueba('estructura: los 12 meses y las claves del documento',
    isset($r['meses'][1], $r['meses'][12], $r['total'])
    && count($r['meses']) === 12
    && array_key_exists('ventas_base', $r['meses'][1]) && array_key_exists('impuestos', $r['meses'][1]));

$ene = $r['meses'][1];
$prueba('enero: sólo la venta facturada vigente (la no facturada y la cancelada no entran)',
    abs($ene['ventas'] - 1160.0) < 0.005 && (int) $ene['ventas_n'] === 1,
    json_encode($ene));
$prueba('enero: base real 1 000 e IVA facturado 160 (subtotal real, no ÷1.16)',
    abs($ene['ventas_base'] - 1000.0) < 0.005 && abs($ene['iva_facturado'] - 160.0) < 0.005);
$prueba('enero: sólo el gasto con comprobante (sin comprobante y cancelado no entran)',
    abs($ene['gastos'] - 580.0) < 0.005 && (int) $ene['gastos_n'] === 1 && abs($ene['gastos_base'] - 500.0) < 0.005);
$prueba('enero: IVA por pagar 80 = 160 − 80 · ISR 160 = 32 % de (1 000 − 500) · impuestos 240',
    abs($ene['iva_por_pagar'] - 80.0) < 0.005 && abs($ene['isr'] - 160.0) < 0.005 && abs($ene['impuestos'] - 240.0) < 0.005,
    json_encode($ene));

$feb = $r['meses'][2];
$prueba('febrero: la venta del 28-ene cuenta AQUÍ (mes del CFDI 02-feb) y respeta su descuento',
    abs($feb['ventas'] - 944.0) < 0.005 && abs($feb['ventas_base'] - 800.0) < 0.005 && (int) $feb['ventas_n'] === 1,
    json_encode($feb));
$prueba('febrero: gasto exento con comprobante → base 100, IVA acreditable 0 (entra, no se divide entre 1.16)',
    abs($feb['gastos'] - 100.0) < 0.005 && abs($feb['gastos_base'] - 100.0) < 0.005 && abs($feb['iva_acreditado'] - 0.0) < 0.005);
$prueba('febrero: IVA 144 · ISR 224 = 32 % de (800 − 100) · impuestos 368',
    abs($feb['iva_por_pagar'] - 144.0) < 0.005 && abs($feb['isr'] - 224.0) < 0.005 && abs($feb['impuestos'] - 368.0) < 0.005,
    json_encode($feb));

$mar = $r['meses'][3];
$prueba('marzo: la venta exenta entra con IVA 0 (base 200) y el gasto acredita IVA 8 real',
    abs($mar['iva_facturado'] - 0.0) < 0.005 && abs($mar['ventas_base'] - 200.0) < 0.005 && abs($mar['iva_acreditado'] - 8.0) < 0.005,
    json_encode($mar));
$prueba('marzo: IVA por pagar −8 (puede salir negativo, como en el documento) e impuestos 24',
    abs($mar['iva_por_pagar'] - (-8.0)) < 0.005 && abs($mar['impuestos'] - 24.0) < 0.005,
    json_encode($mar));

/* 2027 no entra en el año 2026 y los meses vacíos quedan en 0. */
$prueba('la venta de 2027 no entra y los meses sin facturas quedan en 0',
    abs($r['meses'][4]['ventas'] - 0.0) < 0.005 && abs($r['total']['ventas'] - 2304.0) < 0.005);

/* -------------------------------------------------------------------- *
 * 2. EL AÑO: SUMA DE LOS 12 MESES (como la fila TOTAL IMPUESTOS del documento)
 * ------------------------------------------------------------------ */
$sumaMeses = 0.0;
$sumaIsr = 0.0;
foreach ($r['meses'] as $mes => $fila) {
    $sumaMeses += $fila['impuestos'];
    $sumaIsr += $fila['isr'];
}
$prueba('año = suma de los 12 meses (240 + 368 + 24 = 632), no un recálculo sobre el total',
    abs($r['total']['impuestos'] - 632.0) < 0.005 && abs($r['total']['impuestos'] - $sumaMeses) < 0.005,
    json_encode($r['total']));
$prueba('año: IVA 216 · ISR 416 · ventas 2 304 · gastos 788',
    abs($r['total']['iva_por_pagar'] - 216.0) < 0.005 && abs($r['total']['isr'] - 416.0) < 0.005
    && abs($r['total']['isr'] - $sumaIsr) < 0.005 && abs($r['total']['ventas'] - 2304.0) < 0.005
    && abs($r['total']['gastos'] - 788.0) < 0.005, json_encode($r['total']));

/* -------------------------------------------------------------------- *
 * 3. LISTADOS DEL MES (clientes y proveedores/compras)
 * ------------------------------------------------------------------ */
$cliEne = PreliminarFiscal::clientes($contrib, $anio, 1);
$prueba('clientes de enero: una fila (la facturada vigente), con nombre de empresa y totales exactos',
    count($cliEne['filas']) === 1 && $cliEne['filas'][0]['cliente'] === 'T42 Cliente SA'
    && abs((float) $cliEne['totales']['sin_iva'] - 1000.0) < 0.005 && abs((float) $cliEne['totales']['con_iva'] - 1160.0) < 0.005,
    json_encode($cliEne, JSON_UNESCAPED_UNICODE));

$cliFeb = PreliminarFiscal::clientes($contrib, $anio, 2);
$prueba('clientes de febrero: la del 28-ene por su fecha de CFDI, con el descuento aplicado',
    count($cliFeb['filas']) === 1 && abs((float) $cliFeb['totales']['sin_iva'] - 800.0) < 0.005
    && abs((float) $cliFeb['totales']['iva'] - 144.0) < 0.005, json_encode($cliFeb));

$comEne = PreliminarFiscal::compras($contrib, $anio, 1);
$prueba('compras de enero: sólo el gasto con comprobante (sin comprobante y cancelado fuera)',
    count($comEne['filas']) === 1 && $comEne['filas'][0]['beneficiario'] === 'T42 Proveedor'
    && abs((float) $comEne['totales']['con_iva'] - 580.0) < 0.005, json_encode($comEne, JSON_UNESCAPED_UNICODE));

$comMar = PreliminarFiscal::compras($contrib, $anio, 3);
$prueba('compras de marzo: el de IVA 8 % entra con su IVA real',
    count($comMar['filas']) === 1 && abs((float) $comMar['totales']['iva'] - 8.0) < 0.005);

$cliVacio = PreliminarFiscal::clientes($contrib, $anio, 6);
$prueba('mes sin facturas: listados vacíos con totales en 0 (no errores)',
    $cliVacio['filas'] === [] && (int) $cliVacio['totales']['n'] === 0 && abs((float) $cliVacio['totales']['con_iva']) < 0.005);

/* -------------------------------------------------------------------- *
 * 4. PANTALLA: chip, ruta y exportación (sólo lectura de los archivos)
 * ------------------------------------------------------------------ */
$raiz = dirname(__DIR__, 2);
$tabs = (string) file_get_contents($raiz . '/templates/tabsFiscal.php');
$prueba('chip «Preliminar básico» en las pestañas fiscales con su ruta',
    str_contains($tabs, "'preliminar'") && str_contains($tabs, '/admin/fiscal/preliminar') && str_contains($tabs, 'Preliminar básico'));
$rutas = (string) file_get_contents($raiz . '/index.php');
$prueba('ruta GET /admin/fiscal/preliminar registrada (soloAdmin)',
    str_contains($rutas, "'/admin/fiscal/preliminar'") && str_contains($rutas, 'views/admin/fiscal/preliminar.php'));
$vista = (string) file_get_contents($raiz . '/views/admin/fiscal/preliminar.php');
$prueba('la vista trae la tabla fiscal y el botón de Exportar PDF arriba a la derecha',
    str_contains($vista, 'IMPUESTOS POR PAGAR') && str_contains($vista, 'tipo=preliminar') && str_contains($vista, "'acciones'"));
$exportar = (string) file_get_contents($raiz . '/views/admin/fiscal/exportar.php');
$prueba('exportar acepta tipo=preliminar y llama al PDF del preliminar',
    str_contains($exportar, "'preliminar'") && str_contains($exportar, 'descargarPreliminarFiscal'));
$prueba('Documentos::descargarPreliminarFiscal y la plantilla del PDF existen',
    method_exists(\App\Documentos::class, 'descargarPreliminarFiscal')
    && is_file($raiz . '/templates/pdfFiscalPreliminar.php'));
$app = (string) file_get_contents($raiz . '/includes/app.php');
$prueba('la clase nueva está conectada a la BD (setDB en includes/app.php)',
    str_contains($app, 'App\\PreliminarFiscal::class'));

/* -------------------------------------------------------------------- *
 * 5. EL PDF: la plantilla compone bytes reales de PDF con dompdf
 * ------------------------------------------------------------------ */
ob_start();
template('pdfFiscalPreliminar', [
    'titulo' => 'Preliminar básico de impuestos · 2026', 'anio' => $anio, 'mes' => 1,
    'r' => $r, 'clientes' => $cliEne, 'compras' => $comEne, 'aviso' => 'Aviso de prueba T42.',
]);
$html = (string) ob_get_clean();
$prueba('la plantilla del PDF trae las tres secciones y a los T42',
    str_contains($html, 'IMPUESTOS POR PAGAR') && str_contains($html, 'T42 Cliente SA')
    && str_contains($html, 'T42 Proveedor') && str_contains($html, 'Aviso de prueba T42.')
    && substr_count($html, '<h2') === 3);

$componer = new ReflectionMethod(\App\Documentos::class, 'componer');
$componer->setAccessible(true);
$bytes = $componer->invoke(null, $html, 'el preliminar (prueba T42)', 'landscape');
$prueba('dompdf compone el PDF horizontal del preliminar (bytes %PDF reales, no vacíos)',
    is_string($bytes) && substr($bytes, 0, 4) === '%PDF' && strlen($bytes) > 2000,
    is_string($bytes) ? 'len=' . strlen($bytes) : 'null');

/* -------------------------------------------------------------------- *
 * 6. LIMPIEZA FINAL Y RESUMEN
 * ------------------------------------------------------------------ */
$limpiar();
$prueba('limpieza: no queda nada con prefijo T42',
    (int) $uno("SELECT COUNT(*) FROM ingresos WHERE folio LIKE 'T42-%'") === 0
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE concepto LIKE 'T42-%'") === 0
    && (int) $uno("SELECT COUNT(*) FROM clientes WHERE nombre LIKE 'T42%'") === 0);

echo $fallos === 0 ? "\nTODO OK: preliminar básico verificado con las facturas reales.\n"
    : "\nFALLOS: $fallos (revisa las líneas FALLA de arriba).\n";
exit($fallos === 0 ? 0 : 1);
