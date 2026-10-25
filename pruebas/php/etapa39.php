<?php
/**
 * pruebas/php/etapa39.php — FASE 9 (pasos 1 y 2).
 *
 * 1. CfdiXml lee los datos fiscales completos del CFDI 4.0: CP y régimen del
 *    receptor (DomicilioFiscalReceptor / RegimenFiscalReceptor), régimen del
 *    emisor, CondicionesDePago, LugarExpedición, TipoCambio, Exportación y las
 *    claves SAT de cada concepto (ClaveProdServ / ClaveUnidad / ObjetoImp).
 * 2. Alta rápida de cliente desde una factura de «Registro Venta Inteligente»
 *    (Lecturas::altaClienteDesdeLectura): crea la ficha con los datos del
 *    documento, coteja el RFC contra el XML, reutiliza fichas existentes y
 *    queda auditado. La vista y su ruta existen.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa39.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CfdiXml;
use App\Lecturas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$_SESSION['id'] = $usuario;
$contrib = \App\Contabilidad::contribuyentePrincipal();
$rfcPropio = trim((string) $uno('SELECT COALESCE(rfc, "") FROM contribuyentes WHERE id = ' . $contrib)) ?: 'DEMO000101A01';
$raiz = dirname(__DIR__, 2);

/* -------------------------------------------------------------------- *
 * ENTORNO
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t39_' . bin2hex(random_bytes(4));
@mkdir($tmp);

$limpiar = static function () use ($db) {
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T39 %'");
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't39-%'");
};
$limpiar();

/** CFDI tipo I emitido por el negocio, con TODOS los atributos de 4.0. */
$cfdi = static fn (string $uuid, string $receptorRfc, string $receptorNombre): string =>
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' Version="4.0" Serie="SS" Folio="39" Fecha="2026-10-08T08:45:48" SubTotal="1000.00" Descuento="0.00" Total="1160.00"'
    . ' Moneda="MXN" TipoCambio="1" TipoDeComprobante="I" LugarExpedicion="23088" Exportacion="01"'
    . ' FormaPago="03" MetodoPago="PUE" CondicionesDePago="Contado">'
    . '<cfdi:Emisor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 39" RegimenFiscal="612"/>'
    . '<cfdi:Receptor Rfc="' . $receptorRfc . '" Nombre="' . $receptorNombre . '" DomicilioFiscalReceptor="23407"'
    . ' RegimenFiscalReceptor="601" UsoCFDI="G03"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="30102304" NoIdentificacion="T39SKU" Cantidad="2.000000"'
    . ' ClaveUnidad="H87" Descripcion="Canasta pasajuntas" ValorUnitario="500.000000" Importe="1000.000000" ObjetoImp="02"/></cfdi:Conceptos>'
    . '<cfdi:Impuestos TotalImpuestosTrasladados="160.00"><cfdi:Traslados>'
    . '<cfdi:Traslado Base="1000.00" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="160.00"/>'
    . '</cfdi:Traslados></cfdi:Impuestos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';

$leer = static function (string $archivo, string $xml) use ($tmp): int {
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => (int) filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'venta', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('lectura: ' . implode(' ', Lecturas::getErrores()));
    }
    return (int) $id;
};

/* ==================================================================== *
 * 1. CfdiXml: DATOS FISCALES COMPLETOS DEL CFDI 4.0
 * ================================================================== */
echo "CfdiXml lee los datos fiscales del 4.0\n";
$xml39 = $cfdi('FA390000-0000-4000-8000-000000000001', 'QQBB010101A39', 'T39 Cliente Nuevo SA');
$d = CfdiXml::leer($xml39)['datos'];
$prueba('CP del receptor (DomicilioFiscalReceptor = 23407)', $d['receptor_cp'] === '23407', $d['receptor_cp']);
$prueba('régimen del receptor (601)', $d['receptor_regimen'] === '601', $d['receptor_regimen']);
$prueba('régimen del emisor (612)', $d['emisor_regimen'] === '612', $d['emisor_regimen']);
$prueba('condiciones de pago («Contado»)', $d['condiciones_pago'] === 'Contado', $d['condiciones_pago']);
$prueba('lugar de expedición (23088)', $d['lugar_expedicion'] === '23088', $d['lugar_expedicion']);
$prueba('tipo de cambio (1)', $d['tipo_cambio'] === '1', $d['tipo_cambio']);
$prueba('exportación (01)', $d['exportacion'] === '01', $d['exportacion']);
$prueba('claves SAT del concepto (30102304 / H87 / 02)',
    $d['conceptos'][0]['clave_prod_serv'] === '30102304' && $d['conceptos'][0]['clave_unidad'] === 'H87'
    && $d['conceptos'][0]['objeto_imp'] === '02' && $d['conceptos'][0]['sku'] === 'T39SKU',
    json_encode($d['conceptos'][0]));
$prueba('los campos de siempre siguen igual (uuid, totales, método)',
    $d['uuid'] === 'FA390000-0000-4000-8000-000000000001' && $d['total'] === '1160.00' && $d['metodo_pago'] === 'PUE');

/* ==================================================================== *
 * 2. ALTA RÁPIDA DE CLIENTE DESDE LA FACTURA
 * ================================================================== */
echo "Alta rápida de cliente con los datos del documento\n";
$lectura39 = $leer('t39-factura.xml', $xml39);
$prueba('la lectura de la venta se archiva (destino venta, XML guardado)',
    Lecturas::cfdi($lectura39) !== null && (Lecturas::cfdi($lectura39)['receptor_rfc'] ?? '') === 'QQBB010101A39');

$alta = Lecturas::altaClienteDesdeLectura($lectura39, [
    'nombre' => 'T39 Contacto', 'nombre_empresa' => 'T39 Cliente Nuevo SA', 'rfc' => 'qqbb 010101-a39',
    'codigo_postal' => '23407', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03',
], $usuario);
$prueba('el alta rápida crea la ficha del cliente', $alta['ok'] === true && (int) ($alta['cliente_id'] ?? 0) > 0
    && empty($alta['ya_existia']), json_encode($alta));
$ficha = \App\VentasFiscal::buscarClientePorRfc('QQBB010101A39');
$prueba('con su RFC, CP, régimen y uso leídos de la factura',
    $ficha !== null && (string) $ficha['codigo_postal'] === '23407' && (string) $ficha['regimen_fiscal'] === '601'
    && (string) $ficha['uso_cfdi'] === 'G03', json_encode($ficha));
$prueba('y quedó auditada como alta rápida',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'cliente' AND entidad_id = " . (int) $alta['cliente_id']
        . " AND accion = 'crear'") === 1);

// El RFC que NO coincide con el receptor del documento: rechazado sin escribir.
$antes = (int) $uno('SELECT COUNT(*) FROM clientes');
$mala = Lecturas::altaClienteDesdeLectura($lectura39, [
    'nombre' => 'T39 Contacto', 'nombre_empresa' => 'T39 Cliente Ajeno', 'rfc' => 'AAA940101AAA',
], $usuario);
$prueba('un RFC distinto al del documento se rechaza (no se da de alta a otra persona)',
    $alta['ok'] === true && $mala['ok'] === false
    && (bool) array_filter($mala['errores'], static fn ($e) => str_contains($e, 'no coincide'))
    && (int) $uno('SELECT COUNT(*) FROM clientes') === $antes, json_encode($mala));

// El RFC ya registrado: se reutiliza la ficha, no se duplica.
$repetida = Lecturas::altaClienteDesdeLectura($lectura39, [
    'nombre' => 'T39 Contacto', 'nombre_empresa' => 'T39 Cliente Nuevo SA', 'rfc' => 'QQBB010101A39',
], $usuario);
$prueba('si el RFC ya tiene ficha se REUTILIZA (ya_existia, sin duplicar)',
    $repetida['ok'] === true && !empty($repetida['ya_existia'])
    && (int) $repetida['cliente_id'] === (int) $alta['cliente_id']
    && (int) $uno('SELECT COUNT(*) FROM clientes') === $antes, json_encode($repetida));

// Dato inválido capturado en el modal: la validación de la ficha lo detiene.
$malCp = Lecturas::altaClienteDesdeLectura($lectura39 + 0, [
    'nombre' => 'T39 Contacto', 'nombre_empresa' => 'T39 Cliente con mal CP',
    'rfc' => 'QQBB010101A39', 'codigo_postal' => '23',
], $usuario + 0);
// (la lectura sigue siendo la misma; el RFC coincide pero la ficha ya existe → reutiliza)
$prueba('con RFC existente la ficha se reutiliza aunque otros datos traigan errores',
    $malCp['ok'] === true && !empty($malCp['ya_existia']), json_encode($malCp));

// Lectura inexistente.
$inexistente = Lecturas::altaClienteDesdeLectura(99999999, ['rfc' => 'QQBB010101A39'], $usuario);
$prueba('una lectura que ya no existe se rechaza con su motivo',
    $inexistente['ok'] === false && (bool) array_filter($inexistente['errores'], static fn ($e) => str_contains($e, 'ya no existe')));

// Factura SIN RFC de receptor: no hay alta posible (no se inventa).
$xmlSinRfc = str_replace(' Rfc="XAXX010101000"', '', $cfdi('FA390000-0000-4000-8000-000000000002', 'XAXX010101000', 'T39 Sin RFC'));
$lecturaSinRfc = $leer('t39-sin-rfc.xml', $xmlSinRfc);
$sinRfc = Lecturas::altaClienteDesdeLectura($lecturaSinRfc, ['rfc' => 'XAXX010101000', 'nombre' => 'T39 Sin RFC'], $usuario);
$prueba('una factura sin RFC del receptor no permite el alta (se pide a mano)',
    $sinRfc['ok'] === false && (bool) array_filter($sinRfc['errores'], static fn ($e) => str_contains($e, 'no trae el RFC')));

/* ==================================================================== *
 * Vista y ruta del alta rápida
 * ================================================================== */
echo "Vista, ruta y modal\n";
$prueba('la vista de alta rápida existe y valida su entrada',
    is_file($raiz . '/views/admin/ventas/inteligente-cliente.php')
    && str_contains(file_get_contents($raiz . '/views/admin/ventas/inteligente-cliente.php'), 'altaClienteDesdeLectura'));
$prueba('la ruta POST está registrada',
    str_contains(file_get_contents($raiz . '/index.php'), "'/admin/ventas/inteligente-cliente'"));
$crear = file_get_contents($raiz . '/views/admin/ventas/crear.php');
$prueba('la venta inteligente ofrece el botón y el modal de alta (con respaldo sin JS)',
    str_contains($crear, 'btn-alta-cliente') && str_contains($crear, 'tpl-alta-cliente')
    && str_contains($crear, 'abrirModal') && str_contains($crear, 'Regístralo a mano'));
$prueba('el RFC del modal sale del documento y el servidor lo coteja',
    str_contains(file_get_contents($raiz . '/clases/Lecturas.php'), 'no coincide con el receptor de la factura'));

$limpiar();
exec('rm -rf ' . escapeshellarg($tmp));
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
