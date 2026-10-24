<?php
/**
 * pruebas/php/etapa34.php — Ciclo completo de compras, pagos y complementos de proveedores.
 *
 * Escenario A: producto recibido y pago ANTES de la factura (pendiente de recibir CFDI;
 *              al llegar el XML no se genera otro gasto ni otro pago).
 * Escenario B: factura recibida ANTES del pago (saldo pendiente; pago con importe,
 *              fecha efectiva, método y referencia).
 * Escenario C: pagos parciales ($10,000 → $4,000 → $6,000): ambos movimientos, saldo
 *              y «pagada» solo al final.
 * Escenario D: complemento de pago (UUID, DoctoRelacionado, localizar facturas,
 *              vincular a pagos sin duplicar, discrepancias, duplicado, varias facturas).
 * Además: pago sin factura recibida, factura ya registrada, cancelación lógica,
 *         periodos cerrados y estados combinados (ciclo).
 *
 * Los «34» en nombres evitan chocar con las demás etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa34.php
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
use App\OrdenesCompra as OC;
use App\PagosCompra;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;
$contribuyente = Contabilidad::contribuyentePrincipal();
$rfcPropio = (string) $uno('SELECT rfc FROM contribuyentes WHERE id = ' . $contribuyente);
$token = fn (): string => bin2hex(random_bytes(16));

$tmp = sys_get_temp_dir() . '/t34_' . bin2hex(random_bytes(4));
@mkdir($tmp);

$limpiar = function () use ($db) {
    $db->query('DELETE FROM complemento_pagos');
    $db->query('DELETE FROM complemento_doctos');
    $db->query('DELETE FROM complementos_pago');
    $ids = array_column($db->query("SELECT id FROM lecturas_documentales WHERE nombre_original LIKE 't34-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $db->query("UPDATE gastos SET lectura_id = NULL WHERE lectura_id IN ($in)");
        $db->query("DELETE FROM lecturas_documentales WHERE id IN ($in)");
    }
    $db->query("DELETE p FROM pagos_compra p JOIN ordenes_compra o ON o.id = p.orden_id WHERE o.referencia LIKE 'T34-%'");
    $g = array_column($db->query("SELECT g.id FROM gastos g JOIN ordenes_compra o ON o.gasto_id = g.id WHERE o.referencia LIKE 'T34-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($g) {
        $in = implode(',', array_map('intval', $g));
        $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($in)");
        $db->query("DELETE FROM gastos WHERE id IN ($in)");
    }
    $db->query("DELETE oc FROM ordenes_compra oc WHERE oc.referencia LIKE 'T34-%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T34 %'");
};
$limpiar();

/* ---------------------------------------------------------------- helpers */

/** CFDI de compra (tipo I) guardado como lectura; devuelve id. */
$leerComprobante = function (string $archivo, string $xml) use ($tmp): int {
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'compra', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('Lectura de compra falló: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};

/** CFDI tipo «P» (complemento de pago) con sus DoctoRelacionado. */
$complementoXml = static function (string $uuid, array $doctos, array $extra = []) use ($rfcPropio): string {
    $monto = $extra['monto'] ?? '4000.00';
    $fecha = $extra['fecha'] ?? '2026-10-25';
    $forma = $extra['forma'] ?? '03';
    $emisorRfc = $extra['emisor_rfc'] ?? 'T34RFC0101AA1';
    $emisorNombre = $extra['emisor_nombre'] ?? 'T34 Proveedor Ciclo';

    $pagos = '';
    foreach ($doctos as $d) {
        $pagos .= '<pago:DoctoRelacionado IdDocumento="' . $d['uuid'] . '"'
            . ($d['serie'] !== '' ? ' Serie="' . $d['serie'] . '"' : '')
            . ($d['folio'] !== '' ? ' Folio="' . $d['folio'] . '"' : '')
            . ' MonedaDR="MXN"'
            . ($d['parcialidad'] !== '' ? ' NumParcialidad="' . $d['parcialidad'] . '"' : '')
            . ' ImpSaldoAnt="' . ($d['saldo'] ?? '0.00') . '"'
            . ' ImpPagado="' . ($d['pagado'] ?? '0.00') . '"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
        . ' xmlns:pago="http://www.sat.gob.mx/Pagos" Version="4.0" Serie="C" Folio="1"'
        . ' Fecha="' . $fecha . 'T12:00:00" SubTotal="0.00" Total="' . $monto . '" Moneda="MXN"'
        . ' FormaPago="' . $forma . '" MetodoPago="PUE" TipoDeComprobante="P">'
        . '<cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '" RegimenFiscal="601"/>'
        . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 34" UsoCFDI="P01" DomicilioFiscalReceptor="' . $rfcPropio . '"/>'
        . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT"'
        . ' Descripcion="Pago" ValorUnitario="0.00" Importe="0.00"/></cfdi:Conceptos>'
        . '<cfdi:Complemento><pago:Pagos Version="1.0"><pago:Pago FechaPago="' . $fecha . '" MonedaP="MXN"'
        . ' Monto="' . $monto . '" FormaPagoP="' . $forma . '">' . $pagos . '</pago:Pago></pago:Pagos></cfdi:Complemento>'
        . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
};

$leerComplemento = function (string $archivo, string $xml) use ($tmp): int {
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'complemento', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('Lectura de complemento falló: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};

/** Crea una compra de prueba con $importe (sin IVA) y la deja pendiente de recibir CFDI. */
$crearCompra = function (string $ref, int $prov, float $importe) use ($usuario, $hoy): int {
    $ok = OC::crear([
        'tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => $ref,
        'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => '',
    ], [[
        'descripcion' => 'Mercancía ' . $ref, 'cantidad' => '1', 'costo' => number_format($importe, 2, '.', ''),
        'unidad' => 'pza', 'controla' => 0, 'aplica_iva' => 0, 'producto_id' => 0, 'material_id' => 0,
        'material_nuevo' => '', 'proveedor_producto_id' => 0,
    ]], $usuario);
    if (!$ok) {
        throw new RuntimeException('Compra de prueba falló: ' . implode(' ', OC::getErrores()));
    }
    return (int) OC::$ultimoId;
};

/** CFDI de factura (tipo I) para una compra. */
$facturaXml = static function (string $uuid, float $total, array $extra = []) use ($rfcPropio): string {
    $emisorRfc = $extra['emisor_rfc'] ?? 'T34RFC0101AA1';
    $emisorNombre = $extra['emisor_nombre'] ?? 'T34 Proveedor Ciclo';
    $desc = $extra['descripcion'] ?? 'Mercancía facturada';
    $subtotal = number_format($total / 1.16, 2, '.', '');
    $iva = number_format($total - (float) $subtotal, 2, '.', '');

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
        . ' Version="4.0" Serie="A" Folio="9" Fecha="2026-10-20T09:00:00" SubTotal="' . $subtotal . '" Total="' . number_format($total, 2, '.', '') . '"'
        . ' Moneda="MXN" FormaPago="05" MetodoPago="PPD" TipoDeComprobante="I">'
        . '<cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '"/>'
        . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 34"/>'
        . '<cfdi:Conceptos><cfdi:Concepto Cantidad="1" Descripcion="' . $desc . '" ValorUnitario="' . $subtotal . '" Importe="' . $subtotal . '"/></cfdi:Conceptos>'
        . '<cfdi:Impuestos TotalImpuestosTrasladados="' . $iva . '"><cfdi:Traslados>'
        . '<cfdi:Traslado Impuesto="002" Importe="' . $iva . '"/></cfdi:Traslados></cfdi:Impuestos>'
        . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
};

$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T34 Proveedor Ciclo', 'T34RFC0101AA1', 'Material', 1)");
$prov = (int) $db->insert_id;

/* ==================================================================
 * ESCENARIO A + C: pago ANTES de la factura, y pagos parciales
 * ================================================================== */
echo "A/C. Pago antes de la factura y pagos parciales (10.000 → 4.000 → 6.000)\n";
$ordenA = $crearCompra('T34-A', $prov, 10000.00);
$oA = OC::buscarID($ordenA);
$prueba('la compra nace pendiente de recibir CFDI', $oA['estado_cfdi'] === 'pendiente_cfdi', $oA['estado_cfdi']);
$prueba('y sin nada pagado', PagosCompra::pagado($ordenA) === 0);

$gastosAntes = (int) $uno('SELECT COUNT(*) FROM gastos');
$pagosAntes = (int) $uno('SELECT COUNT(*) FROM pagos_compra');

// Pago 1 (parcial) con fecha efectiva, método y referencia.
$okP1 = PagosCompra::registrar($ordenA, '2026-10-19', '4000.00', 'Transferencia', 'TRF-001-A', $token(), $usuario);
$prueba('se registra el pago parcial de $4,000', $okP1, implode(' ', PagosCompra::getErrores()));
$prueba('conserva fecha efectiva, método y referencia',
    (string) $uno("SELECT fecha FROM pagos_compra WHERE orden_id = $ordenA AND importe = 4000.00") === '2026-10-19'
    && (string) $uno("SELECT metodo FROM pagos_compra WHERE orden_id = $ordenA AND importe = 4000.00") === 'Transferencia'
    && (string) $uno("SELECT referencia FROM pagos_compra WHERE orden_id = $ordenA AND importe = 4000.00") === 'TRF-001-A');
$prueba('saldo correcto tras el primer pago ($6,000)', PagosCompra::totalCompra($oA) - PagosCompra::pagado($ordenA) === 600000);
$oA = OC::buscarID($ordenA);
$prueba('estado: pago parcial', PagosCompra::estado(PagosCompra::totalCompra($oA), PagosCompra::pagado($ordenA)) === 'parcial');
$prueba('ciclo: SIN factura · pago parcial', PagosCompra::ciclo($oA + ['estado_pago' => 'parcial'])['etiqueta'] === 'Sin factura · pago parcial');

// Pago 2 (total).
$okP2 = PagosCompra::registrar($ordenA, '2026-10-21', '6000.00', 'Efectivo', 'EFEC-B', $token(), $usuario);
$prueba('se registra el pago final de $6,000', $okP2, implode(' ', PagosCompra::getErrores()));
$prueba('AMBOS movimientos conservados (2 filas)', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenA AND estado = 'vigente'") === 2);
$prueba('total pagado exacto ($10,000)', PagosCompra::pagado($ordenA) === 1000000);
$oA = OC::buscarID($ordenA);
$prueba('solo ahora marca «pagada»', PagosCompra::estado(PagosCompra::totalCompra($oA), PagosCompra::pagado($ordenA)) === 'pagada');
$prueba('y no se pudo pagar de más', !PagosCompra::registrar($ordenA, '2026-10-22', '0.01', 'Efectivo', '', $token(), $usuario)
    && str_contains(implode(' ', PagosCompra::getErrores()), 'excede el saldo'));
PagosCompra::getErrores();

// Ahora llega la factura: se relaciona SIN crear otro gasto ni otro pago.
$uuidFacturaA = 'F1A2B3C4-3400-4000-8000-00000000000A';
$lecA = $leerComprobante('t34-factura-a.xml', $facturaXml($uuidFacturaA, 10000.00, ['descripcion' => 'Mercancía T34-A']));
$okRel = ComprasIntel::relacionar($lecA, $ordenA, $usuario);
$prueba('la factura se relaciona con la compra ya pagada', $okRel, implode(' ', ComprasIntel::getErrores()));
$oA = OC::buscarID($ordenA);
$prueba('la compra pasa a «Con CFDI»', $oA['estado_cfdi'] === 'con_cfdi');
$prueba('se creó UN solo gasto (el de la compra)', (int) $uno('SELECT COUNT(*) FROM gastos') === $gastosAntes + 1);
$prueba('NO se duplicó ningún pago (siguen 2)', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenA AND estado = 'vigente'") === 2);
$gastoA = (int) $oA['gasto_id'];
$prueba('el gasto refleja el pago total (integración contable)',
    (string) $uno("SELECT estado_pago FROM gastos WHERE id = $gastoA") === 'pagado'
    && (string) $uno("SELECT fecha_pago FROM gastos WHERE id = $gastoA") === '2026-10-21');
$oA = OC::buscarID($ordenA);
$prueba('ciclo: FACTURA recibida · pagada', PagosCompra::ciclo($oA + ['estado_pago' => 'pagada'])['etiqueta'] === 'Factura recibida · pagada');

/* ==================================================================
 * ESCENARIO B: factura ANTES del pago
 * ================================================================== */
echo "B. Factura recibida antes del pago (saldo pendiente)\n";
$ordenB = $crearCompra('T34-B', $prov, 5000.00);
$uuidFacturaB = 'F1A2B3C4-3400-4000-8000-00000000000B';
$lecB = $leerComprobante('t34-factura-b.xml', $facturaXml($uuidFacturaB, 5000.00, ['descripcion' => 'Servicio T34-B']));
$prueba('la factura se relaciona', ComprasIntel::relacionar($lecB, $ordenB, $usuario), implode(' ', ComprasIntel::getErrores()));
$oB = OC::buscarID($ordenB);
$prueba('factura recibida con saldo pendiente de pagar',
    $oB['estado_cfdi'] === 'con_cfdi' && PagosCompra::pagado($ordenB) === 0
    && PagosCompra::ciclo($oB + ['estado_pago' => 'pendiente'])['etiqueta'] === 'Factura recibida · pendiente de pagar');
$prueba('y el gasto queda por pagar', (string) $uno("SELECT estado_pago FROM gastos WHERE id = " . (int) $oB['gasto_id']) === 'pendiente');

// Pago posterior con todos sus datos.
$okPB = PagosCompra::registrar($ordenB, '2026-10-23', '5000.00', 'Cheque', 'CHQ-777', $token(), $usuario);
$prueba('pago posterior registrado con método y referencia', $okPB
    && (string) $uno("SELECT metodo FROM pagos_compra WHERE orden_id = $ordenB") === 'Cheque'
    && (string) $uno("SELECT referencia FROM pagos_compra WHERE orden_id = $ordenB") === 'CHQ-777', implode(' ', PagosCompra::getErrores()));
$prueba('el gasto pasa a pagado con la fecha efectiva', (string) $uno("SELECT fecha_pago FROM gastos WHERE id = " . (int) $oB['gasto_id']) === '2026-10-23');

/* ==================================================================
 * ESCENARIO D: complemento de pago
 * ================================================================== */
echo "D. Complemento de pago\n";

// D1. Complemento cuyo pago YA existe → vincula, no crea segundo pago.
$pagosAntesD1 = (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenA");
$uuidComp1 = 'C1C2D3E4-3400-4000-8000-000000000001';
$lecComp1 = $leerComplemento('t34-comp-1.xml', $complementoXml($uuidComp1, [
    ['uuid' => $uuidFacturaA, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '10000.00', 'pagado' => '4000.00'],
]));
$an1 = ComplementosPago::analizar($lecComp1);
$prueba('analiza el complemento sin escribir nada', $an1 !== null && count($an1['doctos']) === 1);
$prueba('identifica UUID, fecha y monto', $an1['datos']['uuid'] === $uuidComp1
    && $an1['complemento']['fecha_pago'] === '2026-10-25'
    && abs((float) $an1['complemento']['monto'] - 4000.0) < 0.005);
$prueba('localiza la factura original (gasto y compra)', $an1['doctos'][0]['gasto'] !== null
    && $an1['doctos'][0]['orden']['id'] === $ordenA, json_encode($an1['doctos'][0]['estado']));
$prueba('encuentra el pago existente de $4,000 y lo da por vinculado', $an1['doctos'][0]['estado'] === 'vinculado',
    $an1['doctos'][0]['nota']);
$prueba('sin discrepancia', abs($an1['doctos'][0]['diferencia']) < 0.005);
$prueba('el pago candidato es el correcto', (int) $an1['doctos'][0]['coincidencia']['id'] > 0
    && abs((float) $an1['doctos'][0]['coincidencia']['importe'] - 4000.0) < 0.005);

$okComp1 = ComplementosPago::registrar($lecComp1, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('el complemento se registra', $okComp1, implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$prueba('NO creó un segundo pago (el movimiento ya existía)', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenA") === $pagosAntesD1);
$prueba('quedó vinculado al pago existente', (int) $uno('SELECT COUNT(*) FROM complemento_pagos') === 1);
$comp1 = ComplementosPago::buscarPorUuid($uuidComp1);
$prueba('y el complemento guardó su UUID único', $comp1 !== null && (string) $comp1['estado'] === 'vigente');
$prueba('la lectura del complemento se cierra (confirmada)',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lecComp1") === 'confirmada'
    && (string) $uno("SELECT entidad FROM lecturas_documentales WHERE id = $lecComp1") === 'complemento');

// D2. Mismo complemento cargado otra vez → duplicado, no registra nada.
$pagosAntesD2 = (int) $uno('SELECT COUNT(*) FROM pagos_compra');
$lecComp1b = $leerComplemento('t34-comp-1b.xml', $complementoXml($uuidComp1, [
    ['uuid' => $uuidFacturaA, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '10000.00', 'pagado' => '4000.00'],
]));
$anDup = ComplementosPago::analizar($lecComp1b);
$prueba('el análisis señala el complemento duplicado', $anDup['duplicado'] !== null && $anDup['duplicado']['uuid'] === $uuidComp1);
$okDup = ComplementosPago::registrar($lecComp1b, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('registrarlo otra vez se RECHAZA', !$okDup && str_contains(implode(' ', ComplementosPago::getErrores()), 'ya está registrado'));
$prueba('y no creó pagos extra', (int) $uno('SELECT COUNT(*) FROM pagos_compra') === $pagosAntesD2);
ComplementosPago::getErrores();

// D3. Complemento con VARIAS facturas (dos compras distintas).
$uuidComp2 = 'C1C2D3E4-3400-4000-8000-000000000002';
$lecComp2 = $leerComplemento('t34-comp-2.xml', $complementoXml($uuidComp2, [
    ['uuid' => $uuidFacturaA, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '2', 'saldo' => '6000.00', 'pagado' => '6000.00'],
    ['uuid' => $uuidFacturaB, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '5000.00', 'pagado' => '5000.00'],
], ['monto' => '11000.00']));
$an2 = ComplementosPago::analizar($lecComp2);
$prueba('reconoce las DOS facturas relacionadas', count($an2['doctos']) === 2
    && $an2['doctos'][0]['orden']['id'] === $ordenA && $an2['doctos'][1]['orden']['id'] === $ordenB);
$prueba('ambas con pago existente (se vinculan, no se crean)', $an2['doctos'][0]['estado'] === 'vinculado' && $an2['doctos'][1]['estado'] === 'vinculado',
    $an2['doctos'][0]['nota'] . ' | ' . $an2['doctos'][1]['nota']);
$pagosAntesD3 = (int) $uno('SELECT COUNT(*) FROM pagos_compra');
$okComp2 = ComplementosPago::registrar($lecComp2, ['doctos' => [0 => ['vincular' => '1'], 1 => ['vincular' => '1']]], $usuario);
$prueba('el complemento multi-factura se registra', $okComp2, implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$comp2 = ComplementosPago::buscarPorUuid($uuidComp2);
$prueba('guarda 2 filas de factura relacionada', (int) $uno('SELECT COUNT(*) FROM complemento_doctos WHERE complemento_id = ' . (int) $comp2['id']) === 2);
$prueba('ambas vinculadas a sus pagos', (int) $uno('SELECT COUNT(*) FROM complemento_pagos WHERE complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ' . (int) $comp2['id'] . ')') === 2);
$prueba('sin crear ningún pago nuevo', (int) $uno('SELECT COUNT(*) FROM pagos_compra') === $pagosAntesD3);

// D4. Discrepancia: dice 5.000 y el pago existente es de 4.000.
$uuidComp3 = 'C1C2D3E4-3400-4000-8000-000000000003';
$lecComp3 = $leerComplemento('t34-comp-3.xml', $complementoXml($uuidComp3, [
    ['uuid' => $uuidFacturaA, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '3', 'saldo' => '10000.00', 'pagado' => '5000.00'],
], ['monto' => '5000.00']));
$an3 = ComplementosPago::analizar($lecComp3);
$prueba('detecta la discrepancia de $1,000', $an3['doctos'][0]['estado'] === 'discrepancia'
    && abs($an3['doctos'][0]['diferencia'] - 1000.0) < 0.005, $an3['doctos'][0]['nota']);
$prueba('la muestra ANTES de confirmar (la vista la lee)', str_contains($an3['doctos'][0]['nota'], 'diferencia'));
$pagosAntesD4 = (int) $uno('SELECT COUNT(*) FROM pagos_compra');
// Confirmado SIN registrar pago nuevo: solo se guarda la discrepancia.
$okComp3 = ComplementosPago::registrar($lecComp3, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('se registra el complemento con su discrepancia', $okComp3, implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$comp3 = ComplementosPago::buscarPorUuid($uuidComp3);
$prueba('la discrepancia quedó persistida (estado diferencia)',
    (string) $uno("SELECT estado FROM complemento_doctos WHERE complemento_id = " . (int) $comp3['id']) === 'sin_pago'
    || (string) $uno("SELECT estado FROM complemento_doctos WHERE complemento_id = " . (int) $comp3['id']) === 'discrepancia');
$prueba('y NO se creó el pago faltante sin pedirlo', (int) $uno('SELECT COUNT(*) FROM pagos_compra') === $pagosAntesD4);

// D5. Pago SIN factura recibida (complemento que cita una factura desconocida).
$uuidComp4 = 'C1C2D3E4-3400-4000-8000-000000000004';
$lecComp4 = $leerComplemento('t34-comp-4.xml', $complementoXml($uuidComp4, [
    ['uuid' => 'DEAD0000-0000-4000-8000-000000000009', 'serie' => 'Z', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '800.00', 'pagado' => '800.00'],
], ['monto' => '800.00']));
$an4 = ComplementosPago::analizar($lecComp4);
$prueba('la factura desconocida queda «sin factura» sin romper', $an4['doctos'][0]['estado'] === 'sin_factura'
    && $an4['doctos'][0]['orden'] === null, $an4['doctos'][0]['nota']);
$pagosAntesD5 = (int) $uno('SELECT COUNT(*) FROM pagos_compra');
$okComp4 = ComplementosPago::registrar($lecComp4, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('se registra sin vínculos imposibles', $okComp4, implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$comp4 = ComplementosPago::buscarPorUuid($uuidComp4);
$prueba('sin crear pagos ni tocar compras', (int) $uno('SELECT COUNT(*) FROM pagos_compra') === $pagosAntesD5
    && (string) $uno("SELECT estado FROM complemento_doctos WHERE complemento_id = " . (int) $comp4['id']) === 'sin_factura');

// D6. Pago que NO existe → se crea SOLO si se pide.
$uuidComp5 = 'C1C2D3E4-3400-4000-8000-000000000005';
$ordenD6 = $crearCompra('T34-D6', $prov, 3000.00);
$uuidFacturaD6 = 'F1A2B3D4-3400-4000-8000-00000000000D';
$lecD6 = $leerComprobante('t34-factura-d6.xml', $facturaXml($uuidFacturaD6, 3000.00, ['descripcion' => 'Compra T34-D6']));
ComprasIntel::relacionar($lecD6, $ordenD6, $usuario);
ComprasIntel::getErrores();
$pagosAntesD6 = (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD6");
$uuidComp6 = 'C1C2D3E4-3400-4000-8000-000000000006';
$lecComp6 = $leerComplemento('t34-comp-6.xml', $complementoXml($uuidComp6, [
    ['uuid' => $uuidFacturaD6, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '3000.00', 'pagado' => '3000.00'],
], ['monto' => '3000.00', 'fecha' => '2026-10-26']));
$an6 = ComplementosPago::analizar($lecComp6);
$prueba('sin pago registrado lo ofrece como «pago sin registrar»', $an6['doctos'][0]['estado'] === 'sin_pago'
    && $an6['doctos'][0]['puede_crear'] === true, $an6['doctos'][0]['nota']);
// Sin pedirlo: no crea.
ComplementosPago::registrar($lecComp6, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
ComplementosPago::getErrores();
$prueba('sin pedir el pago, no se crea', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD6") === $pagosAntesD6);
// Pidiéndolo: lo crea con los datos del complemento.
$uuidComp7 = 'C1C2D3E4-3400-4000-8000-000000000007';
$lecComp7 = $leerComplemento('t34-comp-7.xml', $complementoXml($uuidComp7, [
    ['uuid' => $uuidFacturaD6, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '3000.00', 'pagado' => '3000.00'],
], ['monto' => '3000.00', 'fecha' => '2026-10-26', 'forma' => '03']));
$okComp7 = ComplementosPago::registrar($lecComp7, ['doctos' => [0 => ['vincular' => '1', 'crear_pago' => '1']]], $usuario);
$prueba('con la casilla explícita, crea el pago que faltaba', $okComp7, implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$prueba('ahora sí hay 1 pago en la compra', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD6") === $pagosAntesD6 + 1);
$prueba('el pago creado lleva fecha efectiva, método y referencia del complemento',
    (string) $uno("SELECT fecha FROM pagos_compra WHERE orden_id = $ordenD6 AND estado = 'vigente'") === '2026-10-26'
    && (string) $uno("SELECT metodo FROM pagos_compra WHERE orden_id = $ordenD6 AND estado = 'vigente'") === 'Transferencia'
    && (string) $uno("SELECT referencia FROM pagos_compra WHERE orden_id = $ordenD6 AND estado = 'vigente'") === 'Complemento ' . $uuidComp7);
$prueba('y el gasto de la compra queda pagado (integración contable)',
    (string) $uno("SELECT estado_pago FROM gastos g JOIN ordenes_compra o ON o.gasto_id = g.id WHERE o.id = $ordenD6") === 'pagado');

// D7. Reenviar el MISMO complemento nunca duplica el pago (token determinista).
$pagosAntesD7 = (int) $uno('SELECT COUNT(*) FROM pagos_compra');
$lecComp7b = $leerComplemento('t34-comp-7b.xml', $complementoXml($uuidComp7, [
    ['uuid' => $uuidFacturaD6, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '3000.00', 'pagado' => '3000.00'],
], ['monto' => '3000.00', 'fecha' => '2026-10-26', 'forma' => '03']));
$okComp7b = ComplementosPago::registrar($lecComp7b, ['doctos' => [0 => ['vincular' => '1', 'crear_pago' => '1']]], $usuario);
$prueba('el mismo complemento otra vez se rechaza (UUID único)', !$okComp7b);
$prueba('sin pagos duplicados', (int) $uno('SELECT COUNT(*) FROM pagos_compra') === $pagosAntesD7);
ComplementosPago::getErrores();

/* ==================================================================
 * Cancelación lógica, auditoría y periodo cerrado
 * ================================================================== */
echo "Cancelaciones, auditoría y periodos cerrados\n";
$comp7 = ComplementosPago::buscarPorUuid($uuidComp7);
$okCancel = ComplementosPago::cancelar((int) $comp7['id'], 'Se equivocó el banco (etapa 34)', $usuario);
$prueba('el complemento se cancela con motivo (no se borra)', $okCancel
    && (string) $uno("SELECT estado FROM complementos_pago WHERE id = " . (int) $comp7['id']) === 'cancelado'
    && (string) $uno("SELECT motivo_cancelacion FROM complementos_pago WHERE id = " . (int) $comp7['id']) !== '', implode(' ', ComplementosPago::getErrores()));
ComplementosPago::getErrores();
$prueba('los pagos que ÉL creó se cancelan (ya sin respaldo)',
    (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $ordenD6 AND estado = 'vigente'") === $pagosAntesD6);
$prueba('los vínculos se desvinculan', (int) $uno('SELECT COUNT(*) FROM complemento_pagos WHERE complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ' . (int) $comp7['id'] . ')') === 0);
$prueba('queda auditado', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'complemento_pago' AND entidad_id = " . (int) $comp7['id'] . " AND accion = 'cancelar'") === 1);
$prueba('con motivo corto se rechaza', !ComplementosPago::cancelar((int) $comp7['id'], 'abc', $usuario));
ComplementosPago::getErrores();
$prueba('cancelar dos veces se rechaza', !ComplementosPago::cancelar((int) $comp7['id'], 'Otra vez más (34)', $usuario));
ComplementosPago::getErrores();

$prueba('la creación de cada complemento quedó auditada',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'complemento_pago' AND accion = 'crear'") >= 5);
$prueba('los pagos quedaron auditados (trazabilidad)', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'pago_compra' AND accion = 'crear'") >= 5);

$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES ($contribuyente, 2026, 8, 'cerrado', NOW())");
$uuidComp8 = 'C1C2D3E4-3400-4000-8000-000000000008';
$lecComp8 = $leerComplemento('t34-comp-8.xml', $complementoXml($uuidComp8, [
    ['uuid' => $uuidFacturaB, 'serie' => 'A', 'folio' => '9', 'parcialidad' => '1', 'saldo' => '5000.00', 'pagado' => '1000.00'],
], ['monto' => '1000.00', 'fecha' => '2026-08-15']));
$an8 = ComplementosPago::analizar($lecComp8);
$prueba('el análisis avisa que el periodo está cerrado', $an8['periodo_cerrado'] === true);
$okComp8 = ComplementosPago::registrar($lecComp8, ['doctos' => [0 => ['vincular' => '1', 'crear_pago' => '1']]], $usuario);
$prueba('registrar en mes cerrado se RECHAZA', !$okComp8 && str_contains(implode(' ', ComplementosPago::getErrores()), 'cerrado'));
ComplementosPago::getErrores();
// Y también el pago directo en mes cerrado (regla preexistente intacta).
$prueba('un pago directo en mes cerrado también se rechaza',
    !PagosCompra::registrar($ordenB, '2026-08-15', '10.00', 'Efectivo', '', $token(), $usuario)
    && str_contains(implode(' ', PagosCompra::getErrores()), 'cerrado'));
PagosCompra::getErrores();
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = $contribuyente AND anio = 2026 AND mes = 8");

echo "Ciclos combinados (estados del ciclo de vida)\n";
$oB = OC::buscarID($ordenB);
$cicloB = PagosCompra::ciclo($oB + ['estado_pago' => 'parcial']);
$prueba('factura recibida con pago parcial se distingue', $cicloB['etiqueta'] === 'Factura recibida · pago parcial');
$cicloC = PagosCompra::ciclo(['estado_cfdi' => 'pendiente_cfdi', 'estado_pago' => 'pagada']);
$prueba('pagada SIN factura se distingue (no se esconde)', $cicloC['etiqueta'] === 'Sin factura · ya pagada');

echo "Sin efectos colaterales\n";
$recepcionesAntes = (int) $uno('SELECT COUNT(*) FROM recepciones');
$movimientosAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_inventario');
PagosCompra::registrar($ordenB, '2026-10-24', '1.00', 'Efectivo', 'micro', $token(), $usuario);
PagosCompra::getErrores();
$prueba('los pagos no tocan recepciones ni inventario', (int) $uno('SELECT COUNT(*) FROM recepciones') === $recepcionesAntes
    && (int) $uno('SELECT COUNT(*) FROM movimientos_inventario') === $movimientosAntes);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
