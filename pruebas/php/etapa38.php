<?php
/**
 * pruebas/php/etapa38.php — Pruebas de integración y estabilidad (FASE 8).
 *
 * No agrega funcionalidad: sólo verifica invariántes entre módulos y corrige lo que
 * encuentre. Cobertura pedida:
 *   [3]  Una misma factura nunca produce compras, gastos ni ingresos duplicados.
 *   [4]  Cada pago reduce el saldo correcto y CONSERVA SU FECHA (centavo exacto).
 *   [5]  Cada complemento queda relacionado con sus documentos originales (y sin huérfanos).
 *   [7]  Un error de emisión no pierde datos ni genera registros duplicados (reintento idempotente).
 *   [8]  Los periodos cerrados y las cancelaciones se respetan (gastos, cobros, órdenes,
 *        y el gasto cancelado sale del cálculo fiscal).
 *   [9]  Las migraciones se aplican sobre datos existentes SIN PERDERLOS (y las
 *        reversiones vuelven a dejar listo el esquema para `migrar.php` — ver D1).
 *   [10] Archivos XML: malformado rechazado desde la carga; XML bien formado que no es
 *        complemento se rechaza al registrarlo; y CERO avisos PHP (warning/notice/deprecated)
 *        durante toda la suite.
 *   [12] Todo destructivo ocurre sólo en cotizaciones_test y en un SCRATCH descartable
 *        (cotizaciones_mig_f8): producción sólo se lee con mysqldump.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa38.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CompraContable;
use App\ComplementosPago;
use App\ComprasIntel;
use App\Contabilidad;
use App\Gastos;
use App\Lecturas;
use App\MotorFiscal;
use App\OrdenesCompra as OC;
use App\PagosCompra;
use App\Ventas;
use App\VentasFiscal;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;
$contrib = Contabilidad::contribuyentePrincipal();
$raiz = dirname(__DIR__, 2);

/* [10] Cualquier aviso PHP que emita el código mientras lo ejercitamos es un defecto. */
$avisosPhp = [];
set_error_handler(static function (int $nivel, string $msg, string $archivo, int $linea) use (&$avisosPhp): bool {
    if (!(error_reporting() & $nivel)) {
        return true;   // suprimido con @ a propósito
    }
    if ($nivel & (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED | E_RECOVERABLE_ERROR)) {
        $avisosPhp[] = "$msg ($archivo:$linea)";
        return true;
    }
    return false;
});

/* -------------------------------------------------------------------- *
 * ENTORNO
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t38_' . bin2hex(random_bytes(4));
@mkdir($tmp);

$misVentas = [];
$periodoMio = false;
$cpEmpresa = null;
$limpiar = function () use ($db, &$misVentas, &$periodoMio, &$cpEmpresa) {
    $db->query('DELETE FROM complemento_cobros');
    $db->query('DELETE FROM complemento_pagos');
    $db->query('DELETE FROM complemento_doctos');
    $db->query("DELETE c FROM complementos_pago c JOIN lecturas_documentales l ON l.id = c.lectura_id WHERE l.nombre_original LIKE 't38-%'");
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't38-%'");
    $db->query("DELETE FROM pagos_compra WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE 'T38-%')");
    $db->query("DELETE FROM orden_compra_partidas WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE 'T38-%')");
    $db->query("DELETE FROM ordenes_compra WHERE referencia LIKE 'T38-%'");
    $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T38%' OR concepto LIKE '%T38-%')");
    $db->query("DELETE FROM gastos WHERE concepto LIKE 'T38%' OR concepto LIKE '%T38-%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T38 %'");
    // Ventas e ingresos de MIS clientes, por patrón: así una corrida anterior que
    // murió a medias también queda limpia (el FK de ingresos→clientes lo exigiría).
    $ids = "(SELECT id FROM clientes WHERE nombre_empresa LIKE 'T38 %')";
    $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE cliente_id IN $ids)");
    $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE cliente_id IN $ids)");
    $db->query("DELETE FROM ingresos WHERE cliente_id IN $ids");
    $db->query("DELETE FROM venta_detalles WHERE venta_id IN (SELECT id FROM ventas WHERE cliente_id IN $ids)");
    $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN (SELECT id FROM ventas WHERE cliente_id IN $ids)");
    $db->query("DELETE FROM ventas WHERE cliente_id IN $ids");
    if ($misVentas) {
        $in = implode(',', array_map('intval', $misVentas));
        $db->query("DELETE FROM ingresos WHERE venta_id IN ($in)");
        $db->query("DELETE FROM ventas WHERE id IN ($in)");
    }
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T38 %'");
    if ($periodoMio) {
        $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 9 AND estado = 'cerrado'");
        $periodoMio = false;
    }
    if ($cpEmpresa !== null) {
        $cp = $db->real_escape_string((string) $cpEmpresa);
        $db->query("UPDATE empresas SET cp = " . ($cp === '' ? "NULL" : "'$cp'") . " WHERE id = 2");
        $cpEmpresa = null;
    }
    $misVentas = [];
};
$limpiar();
// Resto de una corrida anterior interrumpida (mi periodo de prueba, 2026-09).
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 9 AND estado = 'cerrado'");

$db->query('UPDATE productos SET stock = 500 WHERE id IN (36, 37)');

/** CFDI de compra (emisor = proveedor T38). */
$rfcPropio = trim((string) $uno('SELECT COALESCE(rfc, \'\') FROM contribuyentes WHERE id = ' . $contrib));
$rfcPropio = $rfcPropio !== '' ? $rfcPropio : 'XAXX010101000';
$cfdi = static function (string $uuid, string $emisorRfc, string $emisorNombre) use ($rfcPropio): string {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0"'
        . ' Serie="T" Folio="38" Fecha="2026-08-20T10:00:00" SubTotal="1499.75" Descuento="0.00" Total="1499.75"'
        . ' Moneda="MXN" FormaPago="03" MetodoPago="PPD" TipoDeComprobante="I">'
        . '<cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '"/>'
        . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 38"/>'
        . '<cfdi:Conceptos><cfdi:Concepto NoIdentificacion="T38-1" Cantidad="1" Descripcion="Mercancía T38"'
        . ' ValorUnitario="1499.75" Importe="1499.75"/></cfdi:Conceptos>'
        . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
};
$leer = static function (string $archivo, string $xml) use ($tmp): int {
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => (int) filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'compra', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('lectura: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};
$crearCompra = static function (string $ref, int $prov, string $fecha, string $costo) use ($db, $usuario): int {
    $ok = OC::crear([
        'tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $fecha, 'referencia' => $ref,
        'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => '',
    ], [[
        'descripcion' => 'Mercancía ' . $ref, 'cantidad' => '1', 'costo' => $costo, 'unidad' => 'pza',
        'controla' => 0, 'aplica_iva' => 0, 'producto_id' => 0, 'material_id' => 0,
        'material_nuevo' => '', 'proveedor_producto_id' => 0,
    ]], $usuario);
    if (!$ok) {
        throw new RuntimeException('compra: ' . implode(' · ', OC::getErrores()));
    }
    return (int) OC::$ultimoId;
};
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T38 Proveedor Estable', 'ZZX380101AA1', 'Material', 1)");
$proveedor38 = (int) $db->insert_id;

/* ==================================================================== *
 * [3] UNA MISMA FACTURA NUNCA DUPLICA (Compras ↔ Gastos ↔ Ventas)
 * ================================================================== */
echo "Una misma factura, una sola operación\n";
$uuidCfdi = 'FA380000-0000-4000-8000-000000000001';
$ordenA = $crearCompra('T38-A', $proveedor38, '2026-08-20', '1499.75');
$ordenB = $crearCompra('T38-B', $proveedor38, '2026-08-21', '1499.75');

$okA = ComprasIntel::relacionar($leer('t38-fact-a.xml', $cfdi($uuidCfdi, 'ZZX380101AA1', 'T38 Proveedor Estable')), $ordenA, $usuario);
$prueba('el CFDI crea UN gasto para la compra', $okA && (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = '$uuidCfdi'") === 1,
    implode(' · ', ComprasIntel::getErrores()));

$okB = ComprasIntel::relacionar($leer('t38-fact-b.xml', $cfdi($uuidCfdi, 'ZZX380101AA1', 'T38 Proveedor Estable')), $ordenB, $usuario);
$erroresB = ComprasIntel::getErrores();
$prueba('la MISMA factura no se registra en otra compra (bloqueo por UUID)', !$okB && (bool) array_filter($erroresB, static fn ($e) => str_contains($e, 'ya está registrado')),
    implode(' · ', $erroresB));
$prueba('sigue habiendo UN solo gasto con ese UUID', (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = '$uuidCfdi'") === 1);
$prueba('la compra bloqueada no quedó enlazada a ningún gasto', (int) $uno("SELECT gasto_id FROM ordenes_compra WHERE id = $ordenB") === 0);
ComprasIntel::getErrores();

/* ==================================================================== *
 * [4] CADA PAGO REDUCE EL SALDO CORRECTO Y CONSERVA SU FECHA
 * ================================================================== */
echo "Saldos exactos y fechas conservadas\n";
$db->query("INSERT INTO clientes (nombre, nombre_empresa, rfc, codigo_postal, regimen_fiscal, uso_cfdi)
            VALUES ('T38 Contacto', 'T38 Cliente SA', 'XAXX010101000', '45678', '601', 'G03')");
$cliente38 = (int) $db->insert_id;

$ordenC = $crearCompra('T38-C', $proveedor38, '2026-02-05', '1499.75');
$ordenCRow = OC::buscarID($ordenC);   // totalCompra() recibe la fila de la compra
$gastoC = CompraContable::enviarAContabilidad($ordenC, $usuario);
$db->query("UPDATE gastos SET comprobante_fiscal = 1, revision_fiscal = 'validado' WHERE id = $gastoC");

$prueba('total de la compra en centavos exactos (149975)', PagosCompra::totalCompra($ordenCRow) === 149975,
    (string) PagosCompra::totalCompra($ordenCRow));
$prueba('el gasto de la compra conserva su FECHA DE EMISIÓN (2026-02-05)',
    (string) $uno("SELECT fecha FROM gastos WHERE id = $gastoC") === '2026-02-05');

PagosCompra::registrar($ordenC, '2026-02-11', '500.33', 'Transferencia', 'T38-PAGO-1', bin2hex(random_bytes(16)), $usuario);
PagosCompra::getErrores();
$prueba('pago parcial: saldo = 149975 − 50033 = 99942 (al centavo)',
    PagosCompra::totalCompra($ordenCRow) - PagosCompra::pagado($ordenC) === 99942,
    (string) (PagosCompra::totalCompra($ordenCRow) - PagosCompra::pagado($ordenC)));
$prueba('el primer pago conserva su fecha (2026-02-11) y su referencia',
    (string) $uno("SELECT fecha FROM pagos_compra WHERE orden_id = $ordenC AND referencia = 'T38-PAGO-1'") === '2026-02-11'
    && (string) $uno("SELECT ROUND(importe * 100) FROM pagos_compra WHERE orden_id = $ordenC AND referencia = 'T38-PAGO-1'") === '50033');
$prueba('con pago parcial el gasto sigue «pendiente» y SIN fecha de pago',
    (string) $uno("SELECT estado_pago FROM gastos WHERE id = $gastoC") === 'pendiente'
    && (string) $uno("SELECT COALESCE(fecha_pago, 'null') FROM gastos WHERE id = $gastoC") === 'null');

PagosCompra::registrar($ordenC, '2026-03-03', '999.42', 'Transferencia', 'T38-PAGO-2', bin2hex(random_bytes(16)), $usuario);
PagosCompra::getErrores();
$prueba('pago final: saldo exacto en 0 y la suma de pagos es el total',
    PagosCompra::totalCompra($ordenCRow) - PagosCompra::pagado($ordenC) === 0
    && (int) $uno("SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM pagos_compra WHERE orden_id = $ordenC AND estado = 'vigente'") === 149975);
$prueba('los DOS pagos conservan sus fechas originales',
    (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenC AND estado = 'vigente' AND fecha IN ('2026-02-11', '2026-03-03')") === 2);
$prueba('y el gasto queda pagado con la FECHA EFECTIVA del último pago (2026-03-03)',
    (string) $uno("SELECT estado_pago FROM gastos WHERE id = $gastoC") === 'pagado'
    && (string) $uno("SELECT fecha_pago FROM gastos WHERE id = $gastoC") === '2026-03-03');

// Lado de ventas: cobros con centavos raros, cancelación y restauración exacta del saldo.
$okVenta = Ventas::crear(
    ['empresa_id' => 2, 'cliente_id' => $cliente38, 'fecha' => '2026-02-10', 'aplica_iva' => 1,
        'descuento_pct' => 0, 'condiciones_compra' => ''],
    [37 => ['cantidad' => 1, 'precio' => 333.33, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$prueba('la venta de prueba se crea', $okVenta === true, implode(' · ', Ventas::getErrores()));
$misVentas[] = (int) Ventas::$ultimoId;
$venta38 = (int) Ventas::$ultimoId;
$ingreso38 = (int) $uno("SELECT id FROM ingresos WHERE venta_id = $venta38");
$totalVenta = (int) $uno("SELECT ROUND(total * 100) FROM ingresos WHERE id = $ingreso38");
$cobrado = fn (): int => (int) $uno("SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM cobros WHERE ingreso_id = $ingreso38 AND estado = 'vigente'");

Contabilidad::registrarCobro($ingreso38, '2026-02-14', '100.07', 'Efectivo', 'T38-COBRO-1', $usuario);
Contabilidad::getErrores();
$cobro1 = (int) $uno("SELECT id FROM cobros WHERE ingreso_id = $ingreso38 AND referencia = 'T38-COBRO-1'");
$prueba('cobro parcial: saldo = total − 10007 (al centavo)', $totalVenta - $cobrado() === $totalVenta - 10007 && $cobrado() === 10007,
    'cobrado=' . $cobrado());
$prueba('el cobro quedó con su fecha exacta (2026-02-14)',
    (string) $uno("SELECT fecha FROM cobros WHERE id = $cobro1") === '2026-02-14');

Contabilidad::registrarCobro($ingreso38, '2026-03-06', '50.01', 'Efectivo', 'T38-COBRO-2', $usuario);
Contabilidad::getErrores();
$prueba('tras un segundo cobro el PRIMERO sigue con su fecha intacta',
    (string) $uno("SELECT fecha FROM cobros WHERE id = $cobro1") === '2026-02-14' && $cobrado() === 15008);

$okCancelarCobro = Contabilidad::cancelarCobro($cobro1, 'T38 cancelación de un cobro', $usuario);
$prueba('cancelar un cobro restaura el saldo exacto (5001) y lo deja cancelado',
    $okCancelarCobro === true && $cobrado() === 5001
    && (string) $uno("SELECT estado FROM cobros WHERE id = $cobro1") === 'cancelado'
    && (string) $uno("SELECT fecha FROM cobros WHERE id = $cobro1") === '2026-02-14',
    implode(' · ', Contabilidad::getErrores()));
$prueba('la venta y su ingreso siguen siendo UNO (los cobros nunca crean ingresos)',
    (int) $uno("SELECT COUNT(*) FROM ventas WHERE id = $venta38") === 1
    && (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $venta38") === 1);

/* ==================================================================== *
 * [5] CADA COMPLEMENTO RELACIONADO CON SUS DOCUMENTOS ORIGINALES
 * ================================================================== */
echo "Complemento ligado a sus documentos originales\n";
$uuidFactura = 'FA380000-0000-4000-8000-000000000003';
$ordenD = $crearCompra('T38-D', $proveedor38, '2026-09-02', '1499.75');
$okRelD = ComprasIntel::relacionar($leer('t38-fact-d.xml', $cfdi($uuidFactura, 'ZZX380101AA1', 'T38 Proveedor Estable')), $ordenD, $usuario);
$prueba('la factura D quedó en su compra con su gasto', $okRelD && (int) $uno("SELECT gasto_id FROM ordenes_compra WHERE id = $ordenD") > 0,
    implode(' · ', ComprasIntel::getErrores()));
$gastoD = (int) $uno("SELECT gasto_id FROM ordenes_compra WHERE id = $ordenD");
PagosCompra::registrar($ordenD, '2026-09-05', '400.00', 'Transferencia', 'T38-PAGO-D', bin2hex(random_bytes(16)), $usuario);
PagosCompra::getErrores();
$pagoD = (int) $uno("SELECT id FROM pagos_compra WHERE orden_id = $ordenD AND referencia = 'T38-PAGO-D'");

$rfcPropio38 = (string) $uno('SELECT rfc FROM contribuyentes WHERE id = ' . $contrib);
$compXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' xmlns:pago="http://www.sat.gob.mx/Pagos" Version="4.0" Serie="C" Folio="38" Fecha="2026-10-20T12:00:00"'
    . ' SubTotal="0.00" Total="400.00" Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="P">'
    . '<cfdi:Emisor Rfc="ZZX380101AA1" Nombre="T38 Proveedor Estable" RegimenFiscal="601"/>'
    . '<cfdi:Receptor Rfc="' . $rfcPropio38 . '" Nombre="Negocio etapa 38" UsoCFDI="P01" DomicilioFiscalReceptor="' . $rfcPropio38 . '"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT"'
    . ' Descripcion="Pago" ValorUnitario="0.00" Importe="0.00"/></cfdi:Conceptos>'
    . '<cfdi:Complemento><pago:Pagos Version="1.0"><pago:Pago FechaPago="2026-10-20" MonedaP="MXN" Monto="400.00" FormaPagoP="03">'
    . '<pago:DoctoRelacionado IdDocumento="' . $uuidFactura . '" Serie="T" Folio="38" MonedaDR="MXN" NumParcialidad="1"'
    . ' ImpSaldoAnt="1499.75" ImpPagado="400.00"/></pago:Pago></pago:Pagos></cfdi:Complemento>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="FA380000-0000-4000-8000-00000000000C"/></cfdi:Complemento></cfdi:Comprobante>';
file_put_contents("$tmp/t38-complemento.xml", $compXml);
$lecComp = Lecturas::preparar([
    ['name' => 't38-complemento.xml', 'tmp_name' => "$tmp/t38-complemento.xml", 'size' => (int) filesize("$tmp/t38-complemento.xml"), 'error' => UPLOAD_ERR_OK],
], 'complemento', $usuario, false);
$prueba('el complemento se carga como lectura', $lecComp !== null, implode(' ', Lecturas::getErrores()));
$an = ComplementosPago::analizar((int) $lecComp);
$prueba('reconoce la factura original y su pago existente («vinculado»)',
    $an !== null && $an['doctos'][0]['estado'] === 'vinculado' && (int) $an['doctos'][0]['orden']['id'] === $ordenD,
    $an['doctos'][0]['nota'] ?? '(sin análisis)');
$pagosAntes = (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD");
$okComp = ComplementosPago::registrar((int) $lecComp, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('el complemento se registra', $okComp === true, implode(' · ', ComplementosPago::getErrores()));
$idComp = (int) $uno("SELECT id FROM complementos_pago WHERE uuid = 'FA380000-0000-4000-8000-00000000000C'");
$prueba('sus DoctoRelacionado apuntan a la compra y al gasto originales',
    (int) $uno("SELECT COUNT(*) FROM complemento_doctos WHERE complemento_id = $idComp AND orden_id = $ordenD AND gasto_id = $gastoD AND documento_uuid = '$uuidFactura'") === 1);
$prueba('se liga al pago original SIN crear pagos nuevos',
    (int) $uno("SELECT COUNT(*) FROM complemento_pagos WHERE complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = $idComp) AND pago_id = $pagoD") === 1
    && (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD") === $pagosAntes);
$prueba('el importe declarado cuadra con la suma de sus documentos (400.00)',
    abs((float) $uno("SELECT COALESCE(SUM(importe_pagado), 0) FROM complemento_doctos WHERE complemento_id = $idComp") - 400.00) < 0.005
    && abs((float) $uno("SELECT total FROM complementos_pago WHERE id = $idComp") - 400.00) < 0.005);
$prueba('y el complemento quedó vigente del lado de PROVEEDORES con su DoctoRelacionado «vinculado»',
    (string) $uno("SELECT lado FROM complementos_pago WHERE id = $idComp") === 'proveedor'
    && (string) $uno("SELECT estado FROM complementos_pago WHERE id = $idComp") === 'vigente'
    && (string) $uno("SELECT estado FROM complemento_doctos WHERE complemento_id = $idComp") === 'vinculado');

// Integridad referencial global: ningún vínculo huérfano en NINGÚN complemento.
$huerfanos = (int) $uno('SELECT (SELECT COUNT(*) FROM complemento_doctos d LEFT JOIN gastos g ON g.id = d.gasto_id WHERE d.gasto_id IS NOT NULL AND g.id IS NULL)'
    . ' + (SELECT COUNT(*) FROM complemento_doctos d LEFT JOIN ordenes_compra o ON o.id = d.orden_id WHERE d.orden_id IS NOT NULL AND o.id IS NULL)'
    . ' + (SELECT COUNT(*) FROM complemento_doctos d LEFT JOIN ingresos i ON i.id = d.ingreso_id WHERE d.ingreso_id IS NOT NULL AND i.id IS NULL)'
    . ' + (SELECT COUNT(*) FROM complemento_pagos p LEFT JOIN pagos_compra pc ON pc.id = p.pago_id WHERE pc.id IS NULL)'
    . ' + (SELECT COUNT(*) FROM complemento_cobros c LEFT JOIN cobros cb ON cb.id = c.cobro_id WHERE cb.id IS NULL)');
$prueba('cero vínculos huérfanos (complemento → gasto/compra/ingreso/pago/cobro)', $huerfanos === 0, (string) $huerfanos);

/* ==================================================================== *
 * [7] UN ERROR DE EMISIÓN NO PIERDE DATOS NI DUPLICA (reintento idempotente)
 * ================================================================== */
echo "Emisión fallida: sin pérdidas ni duplicados\n";
$cpEmpresa = (string) $uno('SELECT COALESCE(cp, "") FROM empresas WHERE id = 2');
$db->query("UPDATE empresas SET cp = IF(cp IS NULL OR cp = '', '45678', cp) WHERE id = 2");
$okVenta2 = Ventas::crear(
    ['empresa_id' => 2, 'cliente_id' => $cliente38, 'fecha' => $hoy, 'aplica_iva' => 1,
        'descuento_pct' => 0, 'condiciones_compra' => ''],
    [37 => ['cantidad' => 2, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$prueba('la venta a emitir se crea', $okVenta2 === true, implode(' · ', Ventas::getErrores()));
$misVentas[] = (int) Ventas::$ultimoId;
$ventaF = (int) Ventas::$ultimoId;
$ingresoF = (int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaF");
VentasFiscal::guardarPago($ventaF, 'PUE', '03', $usuario);
VentasFiscal::getErrores();

$estado = static function () use ($db, $ventaF, $ingresoF): string {
    return json_encode([
        'ventas' => (int) $db->query("SELECT COUNT(*) FROM ventas WHERE id = $ventaF")->fetch_row()[0],
        'ingresos' => (int) $db->query("SELECT COUNT(*) FROM ingresos WHERE venta_id = $ventaF")->fetch_row()[0],
        'detalles' => (int) $db->query("SELECT COUNT(*) FROM venta_detalles WHERE venta_id = $ventaF")->fetch_row()[0],
        'documentos' => (int) $db->query('SELECT COUNT(*) FROM documentos')->fetch_row()[0],
        'ingreso' => $db->query("SELECT * FROM ingresos WHERE id = $ingresoF")->fetch_assoc(),
        'venta' => $db->query("SELECT * FROM ventas WHERE id = $ventaF")->fetch_assoc(),
    ]);
};
$revValida = VentasFiscal::validar($ventaF);
$prueba('PRECONDICIÓN: con datos completos la validación aprueba',
    $revValida !== null && $revValida['puede_facturar'] === true && $revValida['resumen']['error'] === 0,
    implode(' · ', $revValida['errores'] ?? []));

// 1) Error de validación → nada se escribe.
$db->query("UPDATE clientes SET codigo_postal = NULL WHERE id = $cliente38");
$antes = $estado();
$falla1 = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('con datos obligatorios incorrectos la emisión se niega con sus errores',
    $falla1['ok'] === false && $falla1['pendiente_pac'] === false && $falla1['errores'] !== [],
    json_encode($falla1['errores']));
$prueba('y NO modificó ni creó nada (venta, ingreso, renglones y documentos intactos)', $estado() === $antes);
VentasFiscal::getErrores();

// 2) Datos correctos, sin PAC → también falla sin tocar nada.
$db->query("UPDATE clientes SET codigo_postal = '45678' WHERE id = $cliente38");
$antes2 = $estado();
$falla2 = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('sin PAC la emisión informa «pendiente_pac» y no timbra', $falla2['ok'] === false && $falla2['pendiente_pac'] === true,
    json_encode($falla2['errores']));
$prueba('la venta se conserva intacta (sin facturas ni ingresos duplicados)', $estado() === $antes2);
$prueba('el intento quedó en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'venta' AND entidad_id = $ventaF AND accion = 'emision'") >= 1);

// 3) Reintento: mismo resultado, misma cuenta (idempotente).
$falla3 = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('el reintento devuelve el mismo resultado sin duplicar registros',
    $falla3['pendiente_pac'] === $falla2['pendiente_pac'] && $estado() === $antes2
    && (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $ventaF") === 1);

/* ==================================================================== *
 * [8] PERIODOS CERRADOS Y CANCELACIONES
 * ================================================================== */
echo "Periodos cerrados y cancelaciones\n";
$yaCerrado = (int) $uno("SELECT COUNT(*) FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 9");
if ($yaCerrado === 0) {
    $db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES (1, 2026, 9, 'cerrado', NOW())");
    $periodoMio = true;
}

$gastoCerrado = Gastos::crear([
    'fecha' => '2026-09-10', 'concepto' => 'T38 gasto en periodo cerrado', 'beneficiario_tipo' => 'otro',
    'beneficiario' => 'T38 Beneficiario', 'total' => '100.00', 'iva_tasa' => '16', 'naturaleza' => 'gasto',
    'estado_pago' => 'pendiente', 'empresa_id' => 2,
], $usuario);
$erroresCerrado = Gastos::getErrores();
$prueba('NO se puede crear un gasto fechado en un periodo cerrado',
    $gastoCerrado === false && (bool) array_filter($erroresCerrado, static fn ($e) => str_contains($e, 'cerrado')),
    implode(' · ', $erroresCerrado));
$prueba('y no quedó ningún gasto a medias en la base', (int) $uno("SELECT COUNT(*) FROM gastos WHERE concepto LIKE 'T38 gasto en periodo cerrado'") === 0);
Gastos::getErrores();

$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id, subtotal, iva_tasa, iva, total,
        estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, usuario_id)
    VALUES ($contrib, '2026-09-11', 'T38 Beneficiario', 'T38 gasto ya registrado en cerrado', 'gasto', 2,
        50.00, 16.00, 8.00, 58.00, 'pendiente', NULL, 1, 'validado', $usuario)");
$gastoCerradoId = (int) $db->insert_id;
$cancelaCerrado = Gastos::cancelar($gastoCerradoId, 'T38 no se puede tocar un periodo cerrado', $usuario);
$prueba('tampoco se cancela un gasto de periodo cerrado',
    $cancelaCerrado === false && (bool) array_filter(Gastos::getErrores(), static fn ($e) => str_contains($e, 'cerrado')));
$db->query("DELETE FROM gastos WHERE id = $gastoCerradoId");

$cobroCerrado = Contabilidad::registrarCobro($ingreso38, '2026-09-15', '1.00', 'Efectivo', 'T38-CERRADO', $usuario);
$prueba('tampoco se cobra en un periodo cerrado',
    $cobroCerrado === false && (bool) array_filter(Contabilidad::getErrores(), static fn ($e) => str_contains($e, 'cerrado')),
    implode(' · ', Contabilidad::getErrores()));

// Cancelación: el gasto sale del cálculo fiscal (una sola vez, sin residuos).
$antesFiscal = MotorFiscal::calcular($contrib, 2026, 6);
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id, subtotal, iva_tasa, iva, total,
        estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, usuario_id)
    VALUES ($contrib, '2026-06-01', 'T38 Beneficiario', 'T38 gasto a cancelar', 'gasto', 2,
        100.00, 16.00, 16.00, 116.00, 'pagado', '2026-06-05', 1, 'validado', $usuario)");
$gastoCancelar = (int) $db->insert_id;
$conGasto = MotorFiscal::calcular($contrib, 2026, 6);
$prueba('un gasto pagado y válido SÍ descuenta en su mes (junio, +$100.00)',
    (int) $conGasto['por_mes']['deduccion'][6] - (int) $antesFiscal['por_mes']['deduccion'][6] === 10000,
    'delta=' . ((int) $conGasto['por_mes']['deduccion'][6] - (int) $antesFiscal['por_mes']['deduccion'][6]));
$okCancelaGasto = Gastos::cancelar($gastoCancelar, 'T38 cancelación de prueba', $usuario);
$trasCancelar = MotorFiscal::calcular($contrib, 2026, 6);
$prueba('al CANCELARLO el gasto sale del cálculo (delta vuelve a 0)', $okCancelaGasto === true
    && (int) $trasCancelar['por_mes']['deduccion'][6] - (int) $antesFiscal['por_mes']['deduccion'][6] === 0,
    implode(' · ', Gastos::getErrores()));
$prueba('la cancelación deja el gasto «cancelado» sin borrarlo',
    (string) $uno("SELECT estado FROM gastos WHERE id = $gastoCancelar") === 'cancelado');

// Orden cancelada: no se le puede asignar contabilidad.
$ordenCancel = $crearCompra('T38-E', $proveedor38, '2026-04-10', '900.00');
$okCancelOrden = OC::cancelar($ordenCancel, $usuario);
$antesGastos = (int) $uno("SELECT COUNT(*) FROM gastos WHERE concepto LIKE 'T38%'");
$reenvio = CompraContable::enviarAContabilidad($ordenCancel, $usuario);
$prueba('una compra cancelada no admite alta en contabilidad (sin gasto nuevo)',
    $okCancelOrden === true && $reenvio === 0
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE concepto LIKE 'T38%'") === $antesGastos
    && (bool) array_filter(CompraContable::getErrores(), static fn ($e) => str_contains($e, 'cancelada')),
    implode(' · ', CompraContable::getErrores()));

/* ==================================================================== *
 * [10] XML: malformado y «no complemento»
 * ================================================================== */
echo "Archivos XML\n";
file_put_contents("$tmp/t38-malformado.xml", '<cfdi:Comprobante no-cerrado');
$mal = Lecturas::preparar([
    ['name' => 't38-malformado.xml', 'tmp_name' => "$tmp/t38-malformado.xml", 'size' => 30, 'error' => UPLOAD_ERR_OK],
], 'complemento', $usuario, false);
$prueba('un XML malformado NO genera lectura (tampoco como complemento)',
    $mal === null && (bool) array_filter(Lecturas::getErrores(), static fn ($e) => str_contains($e, 'bien formado')),
    implode(' ', Lecturas::getErrores()));

$uuidFactE = 'FA380000-0000-4000-8000-000000000005';
$ordenF = $crearCompra('T38-F', $proveedor38, '2026-05-11', '700.00');
// El mismo CFDI, pero cargado por el camino de los complementos.
file_put_contents("$tmp/t38-no-complemento.xml", $cfdi('FA380000-0000-4000-8000-000000000006', 'ZZX380101AA1', 'T38 Proveedor Estable'));
$lecNoComp = Lecturas::preparar([
    ['name' => 't38-no-complemento.xml', 'tmp_name' => "$tmp/t38-no-complemento.xml",
        'size' => (int) filesize("$tmp/t38-no-complemento.xml"), 'error' => UPLOAD_ERR_OK],
], 'complemento', $usuario, false);
$anNoComp = ComplementosPago::analizar((int) $lecNoComp);
$compsAntes = (int) $uno('SELECT COUNT(*) FROM complementos_pago');
$okNoComp = ComplementosPago::registrar((int) $lecNoComp, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('un XML bien formado que NO es complemento se explica y NO se registra',
    $anNoComp !== null && $anNoComp['complemento'] === null
    && (bool) array_filter($anNoComp['avisos'], static fn ($a) => str_contains($a, 'no trae un complemento'))
    && $okNoComp === false
    && (bool) array_filter(ComplementosPago::getErrores(), static fn ($e) => str_contains($e, 'no hay nada que registrar'))
    && (int) $uno('SELECT COUNT(*) FROM complementos_pago') === $compsAntes,
    implode(' · ', ComplementosPago::getErrores()) . ' | ' . implode(' | ', $anNoComp['avisos'] ?? []));
$prueba('la lectura equivocada quedó «pendiente» (se puede reusar, no se pierde)',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = " . (int) $lecNoComp) === 'pendiente');
// No dejar residuos de esta sección.
$ordenFReuso = OC::buscarID($ordenF);
$prueba('la compra de control quedó sin gasto (nada escrito por el análisis)',
    empty($ordenFReuso['gasto_id']));

/* ==================================================================== *
 * [9] MIGRACIONES SOBRE DATOS EXISTENTES (sin pérdida) + D1
 * ================================================================== */
echo "Migraciones sin pérdida de datos\n";
$scratch = 'cotizaciones_mig_f8';
$prueba('el scratch nunca es la base real ni la que se está probando',
    $scratch !== getenv('DB_NAME') && $scratch !== 'cotizaciones');

$contarTodo = static function (string $bd) use ($db): array {
    $tablas = array_column($db->query(
        "SELECT table_name t FROM information_schema.tables WHERE table_schema = '$bd' AND table_type = 'BASE TABLE' ORDER BY t"
    )->fetch_all(MYSQLI_ASSOC), 't');
    $conteos = [];
    foreach ($tablas as $t) {
        $conteos[$t] = (int) $db->query("SELECT COUNT(*) FROM `$bd`.`$t`")->fetch_row()[0];
    }
    return $conteos;
};
$sumas = static function (string $bd) use ($db): array {
    $q = [
        'ingresos' => 'SELECT COALESCE(SUM(ROUND(total * 100)), 0) FROM ' . $bd . '.ingresos',
        'ingresos_iva' => 'SELECT COALESCE(SUM(ROUND(iva * 100)), 0) FROM ' . $bd . '.ingresos',
        'gastos' => 'SELECT COALESCE(SUM(ROUND(total * 100)), 0) FROM ' . $bd . '.gastos',
        'cobros' => 'SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM ' . $bd . '.cobros',
        'pagos_compra' => 'SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM ' . $bd . '.pagos_compra',
        'ventas' => 'SELECT COALESCE(SUM(ROUND(total * 100)), 0) FROM ' . $bd . '.ventas',
        'cotizaciones' => 'SELECT COALESCE(SUM(ROUND(total * 100)), 0) FROM ' . $bd . '.cotizaciones',
    ];
    $r = [];
    foreach ($q as $k => $sql) {
        $r[$k] = (int) $db->query($sql)->fetch_row()[0];
    }
    return $r;
};
$migracionesNuevas = ['032_compras_inteligentes.sql', '034_complementos_pago.sql', '035_ventas_validacion.sql', '036_complementos_clientes.sql'];
$enMigraciones = static function (string $bd) use ($db, $migracionesNuevas): array {
    $in = "'" . implode("','", $migracionesNuevas) . "'";
    return array_column($db->query("SELECT nombre FROM `$bd`.schema_migraciones WHERE nombre IN ($in)")->fetch_all(MYSQLI_ASSOC), 'nombre');
};

$correr = static function (string $cmd) use ($raiz): array {
    exec('cd ' . escapeshellarg($raiz) . ' && ' . $cmd . ' 2>&1', $salida, $codigo);
    return [$codigo, implode("\n", $salida)];
};
/** Reversiones en orden inverso (036 → 035 → 034 → 032). Devuelve '' o el error. */
$revertir = static function () use ($correr): string {
    $err = '';
    foreach (['036_complementos_clientes_reversion.sql', '035_ventas_validacion_reversion.sql',
              '034_complementos_pago_reversion.sql', '032_compras_inteligentes_reversion.sql'] as $archivo) {
        [$cd, $sd] = $correr('mysql -uroot cotizaciones_mig_f8 < mysql/migraciones/' . escapeshellarg($archivo));
        if ($cd !== 0) {
            $err = "$archivo: $sd";
            break;
        }
    }
    return $err;
};
/** Tablas que LAS PROPIAS migraciones crean: al revertir desaparecen por diseño. */
$creadasPorMigracion = ['complementos_pago', 'complemento_doctos', 'complemento_pagos', 'complemento_cobros'];

[$c0] = $correr("mysql -uroot -e \"DROP DATABASE IF EXISTS $scratch; CREATE DATABASE $scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"");
[$c1, $salida1] = $correr("mysqldump -uroot --single-transaction cotizaciones | mysql -uroot $scratch");
$prueba('se crea un SCRATCH con copia de los datos existentes (producción sólo se lee)', $c0 === 0 && $c1 === 0);

$preConteos = $contarTodo($scratch);
unset($preConteos['schema_migraciones']);   // sólo cambia su libreta, no es dato de negocio
$preSumas = $sumas($scratch);
$produccionAntes = $enMigraciones('cotizaciones');   // estado real de la fuente (0, 4 o algo intermedio)
$preMigradas = $enMigraciones($scratch);
$prueba('la copia arranca con el MISMO estado de migraciones que la fuente (volcado fiel)',
    $preMigradas === $produccionAntes,
    json_encode(['copia' => $preMigradas, 'fuente' => $produccionAntes]));

// Si la fuente YA trae las migraciones aplicadas, primero se revierte la copia
// para poder ejercitar su aplicación sobre datos existentes (mismo ciclo en los dos casos).
if ($preMigradas !== []) {
    $errPrevio = $revertir();
    $prueba('la copia se deja en estado 031 para ejercitar la aplicación', $errPrevio === ''
        && $enMigraciones($scratch) === [], $errPrevio);
}

[$c2, $salida2] = $correr("php scripts/migrar.php $scratch");
$falta = array_filter($migracionesNuevas, static fn ($n) => !str_contains($salida2, $n));
$prueba('migrar.php aplica las 4 migraciones pendientes sobre los datos', $c2 === 0 && $falta === [], json_encode($falta));

$postConteos = $contarTodo($scratch);
unset($postConteos['schema_migraciones']);
$postSumas = $sumas($scratch);
$conteosIguales = true;
foreach ($preConteos as $tabla => $n) {
    if (!array_key_exists($tabla, $postConteos) || $postConteos[$tabla] !== $n) {
        $conteosIguales = false;
        break;
    }
}
$prueba('cada tabla conserva EXACTAMENTE sus filas tras migrar', $conteosIguales,
    json_encode(array_diff_assoc($postConteos, $preConteos)));
$prueba('y todos los importes monetarios siguen idénticos (sin pérdida)', $preSumas === $postSumas,
    json_encode(['antes' => $preSumas, 'después' => $postSumas]));
$prueba('las 4 quedaron registradas en schema_migraciones', count($enMigraciones($scratch)) === 4);
$prueba('las columnas nuevas existen (clientes.codigo_postal / ingresos.metodo_pago)',
    (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$scratch' AND table_name = 'clientes' AND column_name = 'codigo_postal'")->fetch_row()[0] === 1
    && (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$scratch' AND table_name = 'ingresos' AND column_name = 'metodo_pago'")->fetch_row()[0] === 1);

[$c3, $salida3] = $correr("php scripts/migrar.php $scratch");
$prueba('volver a ejecutar migrar.php es un NO-OP (idempotente)',
    $c3 === 0 && !str_contains($salida3, '032_compras_inteligentes')
    && count($enMigraciones($scratch)) === 4);

$errReversion = $revertir();
$prueba('las reversiones se aplican sin errores', $errReversion === '', $errReversion);

$conteosRevert = $contarTodo($scratch);
unset($conteosRevert['schema_migraciones']);
$sumasRevert = $sumas($scratch);
$diffRevert = [];
foreach ($preConteos as $tabla => $n) {
    if (in_array($tabla, $creadasPorMigracion, true)) {
        continue;   // las crea 034/036: al revertir desaparecen (por diseño, no es pérdida de datos)
    }
    if (($conteosRevert[$tabla] ?? null) !== $n) {
        $diffRevert[$tabla] = ($conteosRevert[$tabla] ?? 'borrada') . ' vs ' . $n;
    }
}
$prueba('al revertir, los datos de negocio siguen intactos', $diffRevert === [] && $preSumas === $sumasRevert,
    json_encode($diffRevert));
$prueba('[D1] las reversiones SÍ quitan su registro de schema_migraciones', $enMigraciones($scratch) === [],
    json_encode($enMigraciones($scratch)));
$prueba('y el esquema volvió al estado previo (sin las columnas)',
    (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$scratch' AND table_name = 'clientes' AND column_name = 'codigo_postal'")->fetch_row()[0] === 0);

[$c4, $salida4] = $correr("php scripts/migrar.php $scratch");
$faltaRe = array_filter($migracionesNuevas, static fn ($n) => !str_contains($salida4, $n));
$prueba('migrar.php vuelve a aplicarlas tras la reversión (esquema restaurado)', $c4 === 0 && $faltaRe === [],
    json_encode($faltaRe));
$postConteos2 = $contarTodo($scratch);
unset($postConteos2['schema_migraciones']);
$postSumas2 = $sumas($scratch);
$conteosIguales2 = true;
foreach ($preConteos as $tabla => $n) {
    if (($postConteos2[$tabla] ?? null) !== $n) {
        $conteosIguales2 = false;
        break;
    }
}
$prueba('después del ciclo completo (migrar → revertir → migrar) NO se perdió ni una fila',
    $conteosIguales2 && $preSumas === $postSumas2
    && (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$scratch' AND table_name = 'clientes' AND column_name = 'codigo_postal'")->fetch_row()[0] === 1,
    json_encode(array_diff_assoc($postConteos2, $preConteos)));

$produccionDespues = $enMigraciones('cotizaciones');
$prueba('PRODUCCIÓN no fue tocada por la prueba (mismo estado de migraciones que al empezar)',
    $produccionDespues === $produccionAntes, json_encode($produccionDespues));

$correr("mysql -uroot -e \"DROP DATABASE IF EXISTS $scratch\"");
$prueba('el scratch se descarta y PRODUCCIÓN sigue viva',
    (int) $db->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$scratch'")->fetch_row()[0] === 0
    && (int) $db->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = 'cotizaciones'")->fetch_row()[0] === 1
    && (int) $db->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = 'cotizaciones_test'")->fetch_row()[0] === 1);

/* ==================================================================== *
 * [10] CERO avisos PHP durante toda la suite
 * ================================================================== */
$prueba('cero avisos PHP (warning/notice/deprecated) al ejercitar el código', $avisosPhp === [], implode(' | ', $avisosPhp));

$limpiar();
$correr("rm -rf " . escapeshellarg($tmp));
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
