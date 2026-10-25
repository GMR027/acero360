<?php
/**
 * pruebas/php/etapa41.php — FASE 9 (paso 4): carga por lotes.
 *
 * Sube de golpe facturas de proveedores (y una emitida) y verifica que la
 * bandeja detecte cada documento y que CADA BOTÓN haga lo suyo sin duplicar:
 *   · factura → gasto solo (con la regla PUE+Contado visible y editable) o
 *     gasto + compra de proveedor (métricas) o relación a compra existente;
 *   · complemento tipo P → su flujo (no gasto);
 *   · receptor ajeno y duplicados por UUID → señalados, nunca registrados;
 *   · PDF sin XML → revisar a mano (nada se adivina);
 *   · registrar todo el lote limpio;
 *   · carril de ventas: cliente nuevo dado de alta con un botón;
 *   · el gasto registrado alimenta el cálculo fiscal (validado y pagado).
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa41.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CargaLotes;
use App\ComprasIntel;
use App\Contabilidad;
use App\Lecturas;
use App\MotorFiscal;
use App\OrdenesCompra as OC;
use App\Proveedores;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$_SESSION['id'] = $usuario;
$contrib = Contabilidad::contribuyentePrincipal();
$rfcPropio = trim((string) $uno('SELECT COALESCE(rfc, "") FROM contribuyentes WHERE id = ' . $contrib)) ?: 'DEMO000101A01';
$raiz = dirname(__DIR__, 2);

/* -------------------------------------------------------------------- *
 * ENTORNO
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t41_' . bin2hex(random_bytes(4));
@mkdir($tmp);

$limpiar = static function () use ($db) {
    // Limpia lo de esta etapa y cualquier lectura pendiente ajena que haya
    // dejado otra suite (la bandeja es global: sin esto el «todo» no sería determinista).
    $db->query("UPDATE lecturas_documentales SET estado = 'descartada' WHERE estado = 'pendiente'");
    $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (SELECT id FROM gastos WHERE concepto LIKE '%T41%')");
    $db->query("DELETE FROM gastos WHERE concepto LIKE '%T41%'");
    $db->query("DELETE FROM pagos_compra WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE '%T41%')");
    $db->query("DELETE FROM orden_compra_partidas WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE '%T41%')");
    $db->query("DELETE FROM ordenes_compra WHERE referencia LIKE '%T41%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T41 %'");
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T41 %'");
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't41-%'");
};
$limpiar();
$db->query('UPDATE productos SET stock = 500 WHERE id = 37');

/** Factura tipo I de proveedor (receptor = el negocio). */
$factura = static fn (string $uuid, string $emisorRfc, string $emisorNombre, string $metodo = 'PUE', string $condiciones = 'Contado'): string =>
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' Version="4.0" Serie="T" Folio="41" Fecha="2026-05-06T10:00:00" SubTotal="1000.00" Total="1160.00" Moneda="MXN"'
    . ' TipoDeComprobante="I" LugarExpedicion="23000" Exportacion="01" FormaPago="03" MetodoPago="' . $metodo . '"'
    . ($condiciones !== '' ? ' CondicionesDePago="' . $condiciones . '"' : '')
    . '><cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '" RegimenFiscal="601"/>'
    . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 41" DomicilioFiscalReceptor="23088"'
    . ' RegimenFiscalReceptor="612" UsoCFDI="G03"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="30263600" NoIdentificacion="T41SKU" Cantidad="2.000000" ClaveUnidad="H87"'
    . ' Descripcion="Varilla T41" ValorUnitario="500.000000" Importe="1000.000000" ObjetoImp="02"/></cfdi:Conceptos>'
    . '<cfdi:Impuestos TotalImpuestosTrasladados="160.00"><cfdi:Traslados><cfdi:Traslado Base="1000.00" Impuesto="002"'
    . ' TipoFactor="Tasa" TasaOCuota="0.160000" Importe="160.00"/></cfdi:Traslados></cfdi:Impuestos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';

/** Complemento de pago (tipo P) de un proveedor. */
$complemento = static fn (string $uuid): string =>
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' xmlns:pago="http://www.sat.gob.mx/Pagos" Version="4.0" Serie="C" Folio="41" Fecha="2026-10-20T12:00:00"'
    . ' SubTotal="0.00" Total="400.00" Moneda="XXX" TipoDeComprobante="P" FormaPago="03" MetodoPago="PUE">'
    . '<cfdi:Emisor Rfc="ZZ410101AAA" Nombre="T41 Proveedor Nuevo" RegimenFiscal="601"/>'
    . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 41" DomicilioFiscalReceptor="23088"'
    . ' RegimenFiscalReceptor="612" UsoCFDI="CP01"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT" Descripcion="Pago"'
    . ' ValorUnitario="0.00" Importe="0.00" ObjetoImp="01"/></cfdi:Conceptos>'
    . '<cfdi:Complemento><pago:Pagos Version="1.0"><pago:Pago FechaPago="2026-10-20" MonedaP="MXN" Monto="400.00" FormaPagoP="03">'
    . '<pago:DoctoRelacionado IdDocumento="FA410000-0000-4000-8000-000000000099" MonedaDR="MXN" ImpPagado="400.00"/></pago:Pago>'
    . '</pago:Pagos></cfdi:Complemento><cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';

$archivos = static function (string ...$nombres) use ($tmp): array {
    $lista = [];
    foreach ($nombres as $n) {
        $lista[] = ['name' => $n, 'tmp_name' => "$tmp/$n", 'size' => (int) filesize("$tmp/$n"), 'error' => UPLOAD_ERR_OK];
    }
    return $lista;
};

// Archivos del lote de gastos: factura PUE+Contado, factura PPD, complemento, ajeno y un PDF.
file_put_contents("$tmp/t41-a.xml", $factura('FA410000-0000-4000-8000-00000000000A', 'ZZ410101AAA', 'T41 Proveedor Nuevo'));
file_put_contents("$tmp/t41-b.xml", $factura('FA410000-0000-4000-8000-00000000000B', 'ZZ410202BBB', 'T41 Proveedor Existente', 'PPD', ''));
file_put_contents("$tmp/t41-c.xml", $complemento('FA410000-0000-4000-8000-00000000000C'));
file_put_contents("$tmp/t41-d.xml", str_replace('Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 41"',
    'Rfc="AAA940101AAA" Nombre="Otra empresa"', $factura('FA410000-0000-4000-8000-00000000000D', 'ZZ410303CCC', 'T41 Proveedor Ajeno')));
$pdfPrueba = glob($raiz . '/storage/documentos/*/*/*/*/*.pdf')[0] ?? '';
copy($pdfPrueba, "$tmp/t41-e.pdf");

// Proveedor existente para la factura B.
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T41 Proveedor Existente', 'ZZ410202BBB', 'Material', 1)");
$proveedor41 = (int) $db->insert_id;

/* ==================================================================== *
 * 1. SUBIDA POR LOTE Y DETECCIÓN
 * ================================================================== */
echo "Subida por lote y detección\n";
$subida = CargaLotes::subir($archivos('t41-a.xml', 't41-b.xml', 't41-c.xml', 't41-d.xml', 't41-e.pdf'), 'gastos', $usuario, false);
$prueba('los 5 documentos suben de golpe (XML y PDF juntos)', $subida['ok'] === 5 && $subida['total'] === 5, json_encode($subida));

$filas = CargaLotes::pendientes('gastos');
$porNombre = [];
foreach ($filas as $f) {
    $porNombre[$f['nombre']] = $f;
}
$clases = array_map(static fn ($f) => $f['clase'], $filas);
$prueba('la bandeja clasifica cada documento (factura, complemento, ajeno, manual)',
    ($porNombre['t41-a.xml']['clase'] ?? '') === 'factura'
    && ($porNombre['t41-b.xml']['clase'] ?? '') === 'factura'
    && ($porNombre['t41-c.xml']['clase'] ?? '') === 'complemento'
    && ($porNombre['t41-d.xml']['clase'] ?? '') === 'ajeno'
    && ($porNombre['t41-e.pdf']['clase'] ?? '') === 'manual',
    json_encode($clases));
$prueba('PUE + «Contado» sugiere pagado; PPD no',
    ($porNombre['t41-a.xml']['sugiere_pagado'] ?? null) === true
    && ($porNombre['t41-b.xml']['sugiere_pagado'] ?? null) === false);
$prueba('el proveedor conocido se reconoce y el nuevo se propone',
    (int) ($porNombre['t41-b.xml']['proveedor']['id'] ?? 0) === $proveedor41
    && empty($porNombre['t41-a.xml']['proveedor']));
$prueba('el complemento señala su lado (proveedor) y no se ofrece como gasto',
    ($porNombre['t41-c.xml']['lado_cliente'] ?? true) === false);

/* ==================================================================== *
 * 2. GASTO SOLO (con la regla de pago visible y editable)
 * ================================================================== */
echo "Registrar como gasto\n";
$antes = MotorFiscal::calcular($contrib, 2026, 5);
$gastoA = CargaLotes::registrarGasto((int) $porNombre['t41-a.xml']['id'], true, $usuario);
$prueba('la factura PUE+Contado queda registrada como gasto PAGADO',
    $gastoA['ok'] === true && (int) ($gastoA['gasto_id'] ?? 0) > 0, json_encode($gastoA));
$gastoIdA = (int) $gastoA['gasto_id'];
$prueba('con su UUID, comprobante y fecha efectiva de pago',
    (string) $uno("SELECT uuid_fiscal FROM gastos WHERE id = $gastoIdA") === 'FA410000-0000-4000-8000-00000000000A'
    && (string) $uno("SELECT estado_pago FROM gastos WHERE id = $gastoIdA") === 'pagado'
    && (string) $uno("SELECT fecha_pago FROM gastos WHERE id = $gastoIdA") === '2026-05-06');
$prueba('y la lectura quedó CERRADA apuntando a su gasto',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = " . (int) $porNombre['t41-a.xml']['id']) === 'confirmada'
    && (string) $uno("SELECT entidad FROM lecturas_documentales WHERE id = " . (int) $porNombre['t41-a.xml']['id']) === 'gasto');

// Alimenta el cálculo fiscal cuando la revisión fiscal la valida (regla activa).
$db->query("UPDATE gastos SET revision_fiscal = 'validado' WHERE id = $gastoIdA");
$conGasto = MotorFiscal::calcular($contrib, 2026, 5);
$prueba('el gasto del lote ALIMENTA el cálculo fiscal (mayo, +$1,000 al validar)',
    (int) $conGasto['por_mes']['deduccion'][5] - (int) $antes['por_mes']['deduccion'][5] === 100000,
    'delta=' . ((int) $conGasto['por_mes']['deduccion'][5] - (int) $antes['por_mes']['deduccion'][5]));

// Sin marcar pagado queda pendiente (como el flujo de un documento).
$gastoB = CargaLotes::registrarGasto((int) $porNombre['t41-b.xml']['id'], false, $usuario);
$prueba('la factura PPD queda PENDIENTE de pago si no se marca',
    $gastoB['ok'] === true && (string) $uno("SELECT estado_pago FROM gastos WHERE id = " . (int) $gastoB['gasto_id']) === 'pendiente'
    && (string) $uno("SELECT COALESCE(fecha_pago, 'null') FROM gastos WHERE id = " . (int) $gastoB['gasto_id']) === 'null');

// El mismo folio fiscal jamás dos veces.
$otraVez = CargaLotes::registrarGasto((int) $porNombre['t41-a.xml']['id'], true, $usuario);
$prueba('reintentar el mismo folio fiscal se rechaza (no se duplica)',
    $otraVez['ok'] === false && (bool) array_filter($otraVez['errores'], static fn ($e) => str_contains($e, 'ya está registrado')));

/* ==================================================================== *
 * 3. GASTO + COMPRA DE PROVEEDOR (métricas) Y RELACIÓN A COMPRA EXISTENTE
 * ================================================================== */
echo "Compras de proveedor desde el lote\n";
file_put_contents("$tmp/t41-f.xml", $factura('FA410000-0000-4000-8000-00000000000F', 'ZZ410404DDD', 'T41 Proveedor A Auto'));
$subidaF = CargaLotes::subir($archivos('t41-f.xml'), 'gastos', $usuario, false);
$filaF = null;
foreach (CargaLotes::pendientes('gastos') as $f) {
    if ($f['nombre'] === 't41-f.xml') { $filaF = $f; }
}
$compraF = CargaLotes::registrarCompra((int) ($filaF['id'] ?? 0), $usuario);
$prueba('gasto + compra: proveedor nuevo AUTO-ALTA + orden con sus partidas',
    $compraF['ok'] === true && !empty($compraF['proveedor_nuevo']) && (int) ($compraF['orden_id'] ?? 0) > 0,
    json_encode($compraF));
$ordenF = OC::buscarID((int) $compraF['orden_id']);
$prueba('la compra lleva los conceptos del XML y su gasto queda enlazado',
    (int) $uno("SELECT COUNT(*) FROM orden_compra_partidas WHERE orden_id = " . (int) $compraF['orden_id']) === 1
    && !empty($ordenF['gasto_id'])
    && (int) $uno("SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = " . (int) $ordenF['gasto_id'] . " AND uuid_fiscal = 'FA410000-0000-4000-8000-00000000000F'") === 1);
$proveedores41 = \App\ResumenFiscal::proveedores(2026, 5)['proveedores'];
$metrica = null;
foreach ($proveedores41 as $p) {
    if ((string) ($p['proveedor'] ?? '') === (string) $ordenF['proveedor_nombre']) { $metrica = $p; }
}
$prueba('las MÉTRICAS de proveedor ven la compra del lote',
    $metrica !== null && (float) ($metrica['comprado'] ?? 0) >= 1160.0, json_encode($metrica));

// Relación a una compra EXISTENTE.
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T41 Proveedor Relacion', 'ZZ410505EEE', 'Material', 1)");
$provRel = (int) $db->insert_id;
$okOrden = OC::crear(['tipo' => 'insumo', 'proveedor_id' => $provRel, 'fecha_solicitud' => '2026-05-02', 'referencia' => 'T41-REL',
    'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => ''], [
    ['descripcion' => 'Varilla T41', 'cantidad' => '2', 'costo' => '500.00', 'unidad' => 'pza', 'controla' => 0,
     'aplica_iva' => 1, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0],
], $usuario);
$ordenRel = (int) OC::$ultimoId;
file_put_contents("$tmp/t41-g.xml", $factura('FA410000-0000-4000-8000-000000000e11', 'ZZ410505EEE', 'T41 Proveedor Relacion'));
CargaLotes::subir($archivos('t41-g.xml'), 'gastos', $usuario, false);
$filaG = null;
foreach (CargaLotes::pendientes('gastos') as $f) {
    if ($f['nombre'] === 't41-g.xml') { $filaG = $f; }
}
$prueba('la bandeja propone las compras candidatas del proveedor',
    $filaG !== null && count($filaG['ordenes'] ?? []) >= 1 && (int) ($filaG['ordenes'][0]['id'] ?? 0) === $ordenRel,
    json_encode($filaG['ordenes'] ?? null));
$okRel = ComprasIntel::relacionar((int) $filaG['id'], $ordenRel, $usuario);
$prueba('relacionar a la compra existente adjunta el XML y crea SU gasto (una vez)',
    $okRel === true && !empty(OC::buscarID($ordenRel)['gasto_id'])
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = 'FA410000-0000-4000-8000-000000000e11'") === 1,
    implode(' · ', ComprasIntel::getErrores()));

// Alta de proveedor suelta (botón explícito).
file_put_contents("$tmp/t41-h.xml", $factura('FA410000-0000-4000-8000-000000000e22', 'ZZ410606FFF', 'T41 Proveedor Solo Alta'));
CargaLotes::subir($archivos('t41-h.xml'), 'gastos', $usuario, false);
$filaH = null;
foreach (CargaLotes::pendientes('gastos') as $f) {
    if ($f['nombre'] === 't41-h.xml') { $filaH = $f; }
}
$altaH = CargaLotes::altaProveedor((int) ($filaH['id'] ?? 0), $usuario);
$prueba('el botón de alta de proveedor lo crea con el RFC del XML (sin registrar la factura)',
    $altaH['ok'] === true && (int) $uno("SELECT COUNT(*) FROM proveedores WHERE rfc = 'ZZ410606FFF'") === 1
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = 'FA410000-0000-4000-8000-000000000e22'") === 0,
    json_encode($altaH));

/* ==================================================================== *
 * 4. DUPLICADOS, AJENOS, DESCARTE Y REGISTRAR TODO
 * ================================================================== */
echo "Duplicados, ajenos y el atajo de todo el lote\n";
file_put_contents("$tmp/t41-i.xml", $factura('FA410000-0000-4000-8000-00000000000A', 'ZZ410101AAA', 'T41 Proveedor Nuevo'));
CargaLotes::subir($archivos('t41-i.xml'), 'gastos', $usuario, false);
$duplicada = null;
foreach (CargaLotes::pendientes('gastos') as $f) {
    if ($f['nombre'] === 't41-i.xml') { $duplicada = $f; }
}
$prueba('el mismo folio fiscal subido de nuevo se marca DUPLICADO con su origen',
    ($duplicada['clase'] ?? '') === 'duplicado' && str_contains((string) ($duplicada['duplicado']['etiqueta'] ?? ''), 'Ya está'),
    json_encode($duplicada['duplicado'] ?? null));

$ajena = $porNombre['t41-d.xml'];
$descarteAjeno = CargaLotes::descartar((int) $ajena['id'], $usuario);
$prueba('la factura de otro receptor se descarta sin registrar nada',
    $descarteAjeno === true
    && (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = " . (int) $ajena['id']) === 'descartada'
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = 'FA410000-0000-4000-8000-00000000000D'") === 0);

// Lote limpio para el atajo: dos facturas nuevas (una PUE+Contado, otra PPD).
file_put_contents("$tmp/t41-j.xml", $factura('FA410000-0000-4000-8000-000000000e44', 'ZZ410707GGG', 'T41 Proveedor J'));
file_put_contents("$tmp/t41-k.xml", $factura('FA410000-0000-4000-8000-000000000e55', 'ZZ410808HHH', 'T41 Proveedor K', 'PPD', ''));
CargaLotes::subir($archivos('t41-j.xml', 't41-k.xml'), 'gastos', $usuario, false);
$todos = CargaLotes::registrarTodo($usuario);
// (son TRES: j y k, más la «h», que quedó pendiente porque sólo se dio de alta a su proveedor)
$prueba('«registrar todo» procesa las facturas limpias y respeta cada sugerencia de pago',
    $todos['registrados'] === 3 && $todos['errores'] === []
    && (string) $uno("SELECT estado_pago FROM gastos WHERE uuid_fiscal = 'FA410000-0000-4000-8000-000000000e44'") === 'pagado'
    && (string) $uno("SELECT estado_pago FROM gastos WHERE uuid_fiscal = 'FA410000-0000-4000-8000-000000000e55'") === 'pendiente',
    json_encode($todos));
$pendientesTras = CargaLotes::pendientes('gastos');
$soloEspeciales = array_filter($pendientesTras, static fn ($f) => $f['clase'] === 'factura');
$prueba('la bandeja queda sin facturas limpias pendientes (sólo complemento/manual/duplicado)',
    $soloEspeciales === [], json_encode(array_column($pendientesTras, 'clase')));

/* ==================================================================== *
 * 5. CARRIL DE VENTAS: cliente nuevo con un botón
 * ================================================================== */
echo "Carril de ventas\n";
file_put_contents("$tmp/t41-v.xml", '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' Version="4.0" Serie="SS" Folio="41" Fecha="2026-06-08T08:45:48" SubTotal="1000.00" Total="1160.00" Moneda="MXN"'
    . ' TipoDeComprobante="I" LugarExpedicion="23088" Exportacion="01" FormaPago="03" MetodoPago="PUE" CondicionesDePago="Contado">'
    . '<cfdi:Emisor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 41" RegimenFiscal="612"/>'
    . '<cfdi:Receptor Rfc="QQFF010101A41" Nombre="T41 Cliente Ventas SA" DomicilioFiscalReceptor="23407"'
    . ' RegimenFiscalReceptor="601" UsoCFDI="G03"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="30102304" Cantidad="2.000000" ClaveUnidad="H87" Descripcion="Canasta T41"'
    . ' ValorUnitario="500.000000" Importe="1000.000000" ObjetoImp="02"/></cfdi:Conceptos>'
    . '<cfdi:Impuestos TotalImpuestosTrasladados="160.00"><cfdi:Traslados><cfdi:Traslado Base="1000.00" Impuesto="002"'
    . ' TipoFactor="Tasa" TasaOCuota="0.160000" Importe="160.00"/></cfdi:Traslados></cfdi:Impuestos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="FA410000-0000-4000-8000-000000000e66"/></cfdi:Complemento></cfdi:Comprobante>');
$subidaV = CargaLotes::subir($archivos('t41-v.xml'), 'ventas', $usuario, false);
$filasV = CargaLotes::pendientes('ventas');
$prueba('la factura emitida entra al carril de ventas y detecta al cliente',
    $subidaV['ok'] === 1 && $filasV !== [] && $filasV[0]['clase'] === 'factura' && $filasV[0]['cliente'] === null,
    json_encode($filasV[0]['cliente'] ?? 'no-fila'));
$altaV = Lecturas::altaClienteDesdeLectura((int) $filasV[0]['id'], ['rfc' => 'QQFF010101A41'], $usuario);
$prueba('el botón de alta de cliente la crea con los datos de la factura (reutiliza el paso 2)',
    $altaV['ok'] === true && empty($altaV['ya_existia'])
    && (int) $uno("SELECT COUNT(*) FROM clientes WHERE rfc = 'QQFF010101A41'") === 1, json_encode($altaV));
$filasV2 = CargaLotes::pendientes('ventas');
$prueba('y la bandeja de ventas ya lo reconoce para crear la venta',
    $filasV2 !== [] && (int) ($filasV2[0]['cliente']['id'] ?? 0) > 0, json_encode($filasV2[0]['cliente'] ?? null));

/* ==================================================================== *
 * 6. Vistas y rutas
 * ================================================================== */
echo "Vistas y rutas\n";
$lotes = file_get_contents($raiz . '/views/admin/contabilidad/lotes.php');
$prueba('la bandeja existe con sus dos carriles, la regla de pago visible y el botón por renglón',
    str_contains($lotes, 'carril=gastos') && str_contains($lotes, 'carril=ventas')
    && str_contains($lotes, 'Solo gasto') && str_contains($lotes, 'Gasto y compra')
    && str_contains($lotes, 'Relacionar') && str_contains($lotes, 'PUE y «Contado»')
    && str_contains($lotes, 'Registrar todas las facturas limpias'));
$prueba('las rutas y la pestaña de Contabilidad están registradas',
    str_contains(file_get_contents($raiz . '/index.php'), "'/admin/contabilidad/lotes'")
    && str_contains(file_get_contents($raiz . '/index.php'), "'/admin/contabilidad/lotes-accion'")
    && str_contains(file_get_contents($raiz . '/templates/tabsContabilidad.php'), 'Carga por lotes'));
$resumen41 = file_get_contents($raiz . '/views/admin/fiscal/resumen.php');
$prueba('el resumen fiscal trae el mini-calendario de captura (meses con ventas/gastos)',
    str_contains($resumen41, 'Meses con datos capturados'));
$prueba('y el resumen del mes avisa de compras con factura pero sin gasto',
    str_contains(file_get_contents($raiz . '/views/admin/contabilidad/index.php'), 'no tienen gasto en contabilidad'));
$prueba('la clase no registra nada sola: cada acción pasa por las vías existentes',
    str_contains(file_get_contents($raiz . '/clases/CargaLotes.php'), 'Gastos::crear')
    && str_contains(file_get_contents($raiz . '/clases/CargaLotes.php'), 'ComprasIntel::relacionar')
    && str_contains(file_get_contents($raiz . '/clases/CargaLotes.php'), 'operacionPorUuid'));

$limpiar();
exec('rm -rf ' . escapeshellarg($tmp));
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
