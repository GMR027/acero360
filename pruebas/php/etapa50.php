<?php
/**
 * pruebas/php/etapa50.php — Abonos y complementos de pago de gastos sueltos.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa50.php
 *
 * Cubre el caso PPD: el proveedor emite la factura, el pago queda pendiente o
 * en parcialidades y después llega el complemento de pago (CFDI tipo P). Se
 * comprueba que:
 *   · un gasto suelto admite abonos parciales y conserva su saldo;
 *   · el gasto solo pasa a «pagado» cuando la suma cubre el total;
 *   · cancelar un abono reabre el saldo;
 *   · el complemento se resuelve contra el gasto (sin compra) y crea o vincula
 *     el abono, sin duplicarlo;
 *   · un XML tipo P se guarda como complemento, con su monto real y nunca como
 *     factura de $0;
 *   · los filtros de la lista distinguen sin pagar, parcial y pagado.
 *
 * Usa gastos con concepto «T50 …» y UUID propios; limpia todo al final.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Archivos;
use App\ComplementosPago;
use App\Contabilidad;
use App\Gastos;
use App\Lecturas;
use App\PagosGasto;

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
$contribuyente = Contabilidad::contribuyentePrincipal();
$rfcPropio = (string) $db->query("SELECT rfc FROM contribuyentes WHERE id = $contribuyente")->fetch_row()[0];
$fecha = date('Y-m-d');
$archivosTemporales = [];

$limpiar = function () use ($db, &$archivosTemporales) {
    $gastos = array_column(
        $db->query("SELECT id FROM gastos WHERE concepto LIKE 'T50 %'")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    if ($gastos) {
        $in = implode(',', array_map('intval', $gastos));
        $docs = $db->query("SELECT id, archivo FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($in)")->fetch_all(MYSQLI_ASSOC);
        foreach ($docs as $doc) {
            $ruta = Archivos::base() . '/' . $doc['archivo'];
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
        $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($in)");
        $db->query("DELETE FROM complemento_pagos_gasto WHERE pago_gasto_id IN (SELECT id FROM pagos_gasto WHERE gasto_id IN ($in))");
        $db->query("DELETE FROM gastos WHERE id IN ($in)");   // pagos_gasto se van en cascada
    }
    $db->query("DELETE FROM complemento_doctos WHERE documento_uuid LIKE 'FA500000-%'");
    $db->query("DELETE FROM complementos_pago WHERE uuid LIKE 'FA500000-%'");
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 'T50%'");
    foreach ($archivosTemporales as $ruta) {
        @unlink($ruta);
    }
    $archivosTemporales = [];
};
$limpiar();

$crearGasto = function (string $sufijo, string $uuid, string $metodo = 'PPD', bool $conFactura = true) use ($usuario, $fecha): int {
    $ok = Gastos::crear([
        'fecha' => $fecha, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T50 Proveedor ' . $sufijo,
        'concepto' => 'T50 Gasto ' . $sufijo, 'subtotal' => '1000', 'iva_tasa' => '16',
        'estado_pago' => 'pendiente', 'comprobante_fiscal' => $conFactura ? 1 : 0,
        'uuid_fiscal' => $conFactura ? $uuid : '', 'metodo_pago_sat' => $conFactura ? $metodo : '',
        'revision_fiscal' => $conFactura ? 'validado' : 'por_validar',
        'rfc_emisor' => 'T50010101AA1', 'rfc_receptor' => '',
    ], $usuario);
    if (!$ok) {
        throw new RuntimeException('No se pudo crear el gasto T50: ' . implode(' · ', Gastos::getErrores()));
    }
    return (int) Gastos::$ultimoId;
};

$complementoXml = function (string $uuidFactura, string $uuidComplemento, string $importe = '500.00', string $montoTotal = '500.00') use ($rfcPropio): string {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:pago20="http://www.sat.gob.mx/Pagos20"'
        . ' xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Fecha="' . date('Y-m-d') . 'T10:00:00"'
        . ' SubTotal="0" Total="0" Moneda="MXN" TipoDeComprobante="P" LugarExpedicion="06000" Exportacion="01">'
        . '<cfdi:Emisor Rfc="T50010101AA1" Nombre="T50 Proveedor" RegimenFiscal="612"/>'
        . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio" DomicilioFiscalReceptor="06000" RegimenFiscalReceptor="601" UsoCFDI="G03"/>'
        . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT" Descripcion="Pago" ValorUnitario="0" Importe="0"/></cfdi:Conceptos>'
        . '<cfdi:Complemento><pago20:Pagos Version="2.0" MontoTotalPagos="' . $montoTotal . '">'
        . '<pago20:Pago FechaPago="' . date('Y-m-d') . 'T09:00:00" FormaPagoP="03" Monto="' . $importe . '" MonedaP="MXN">'
        . '<pago20:DoctoRelacionado IdDocumento="' . $uuidFactura . '" Serie="F" Folio="1" MonedaDR="MXN"'
        . ' NumParcialidad="1" ImpSaldoAnt="1160.00" ImpPagado="' . $importe . '" ObjetoImpDR="02"/>'
        . '</pago20:Pago></pago20:Pagos>'
        . '<tfd:TimbreFiscalDigital Version="1.1" UUID="' . $uuidComplemento . '" FechaTimbrado="' . date('Y-m-d') . 'T10:01:00"/>'
        . '</cfdi:Complemento></cfdi:Comprobante>';
};
$archivoXml = function (string $nombre, string $xml) use (&$archivosTemporales, $limpiar): array {
    $ruta = sys_get_temp_dir() . '/t50_' . bin2hex(random_bytes(4)) . '.xml';
    file_put_contents($ruta, $xml);
    $archivosTemporales[] = $ruta;

    return ['name' => $nombre, 'tmp_name' => $ruta, 'size' => filesize($ruta), 'error' => UPLOAD_ERR_OK];
};

echo "1. Abonos parciales de un gasto suelto\n";
$gastoA = $crearGasto('A', 'FA500000-0000-4000-8000-0000000000A1');
$estado = Gastos::estadoPagoGasto(Gastos::buscarID($gastoA));
$prueba('inicia pendiente, sin abonos', $estado['clave'] === 'pendiente' && $estado['pagado'] === 0 && $estado['saldo'] === 116000);

$okPago = Gastos::pagar($gastoA, [
    'fecha_pago' => $fecha, 'importe' => '500', 'metodo_pago' => 'Transferencia',
    'metodo_pago_sat' => 'PPD', 'pago_token' => bin2hex(random_bytes(16)),
], $usuario);
$estado = Gastos::estadoPagoGasto(Gastos::buscarID($gastoA));
$prueba('un abono de 500 deja el gasto en pago parcial',
    $okPago && $estado['clave'] === 'parcial' && $estado['pagado'] === 50000 && $estado['saldo'] === 66000,
    json_encode($estado));

$okPago2 = Gastos::pagar($gastoA, [
    'fecha_pago' => $fecha, 'importe' => '660', 'metodo_pago' => 'Transferencia',
    'metodo_pago_sat' => 'PPD', 'pago_token' => bin2hex(random_bytes(16)),
], $usuario);
$filaA = Gastos::buscarID($gastoA);
$estado = Gastos::estadoPagoGasto($filaA);
$prueba('al cubrir el saldo el gasto queda pagado',
    $okPago2 && $estado['clave'] === 'pagado' && $estado['saldo'] === 0 && $filaA['estado_pago'] === 'pagado');
$prueba('no se puede abonar más que el saldo',
    Gastos::pagar($gastoA, ['fecha_pago' => $fecha, 'importe' => '1', 'pago_token' => bin2hex(random_bytes(16))], $usuario) === false);

$pagosA = PagosGasto::deGasto($gastoA);
$segundo = end($pagosA);
$prueba('cancelar el último abono reabre el saldo',
    PagosGasto::cancelar((int) $segundo['id'], 'Prueba de cancelación', $usuario)
    && Gastos::estadoPagoGasto(Gastos::buscarID($gastoA))['clave'] === 'parcial');

echo "1b. Pago de una factura de un periodo cerrado\n";
$anioCerrado = 2031;
$mesCerrado = 1;
$periodoExistia = (int) $db->query(
    "SELECT COUNT(*) FROM periodos_contables WHERE contribuyente_id = $contribuyente AND anio = $anioCerrado AND mes = $mesCerrado"
)->fetch_row()[0] > 0;
if (!$periodoExistia) {
    $db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en)
                VALUES ($contribuyente, $anioCerrado, $mesCerrado, 'cerrado', NOW())");
}
$uuidCerrado = 'FA500000-0000-4000-8000-0000000000C9';
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario_tipo, beneficiario, concepto, subtotal, iva_tasa,
                iva, total, estado_pago, comprobante_fiscal, uuid_fiscal, metodo_pago_sat, revision_fiscal, usuario_id)
            VALUES ($contribuyente, '2031-01-15', 'otro', 'T50 Proveedor cerrado', 'T50 Gasto de periodo cerrado',
                100.00, 0.00, 0.00, 100.00, 'pendiente', 1, '$uuidCerrado', 'PPD', 'validado', $usuario)");
$gastoCerrado = (int) $db->insert_id;
$okCerrado = Gastos::pagar($gastoCerrado, [
    'fecha_pago' => $fecha, 'importe' => '100', 'pago_token' => bin2hex(random_bytes(16)),
], $usuario);
$filaCerrado = Gastos::buscarID($gastoCerrado);
$prueba('una factura de un mes cerrado se puede pagar en un mes abierto',
    $okCerrado && $filaCerrado['estado_pago'] === 'pagado' && PagosGasto::pagado($gastoCerrado) === 10000,
    json_encode(['ok' => $okCerrado, 'estado' => $filaCerrado['estado_pago'] ?? null, 'errores' => Gastos::getErrores()]));
if (!$periodoExistia) {
    $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = $contribuyente AND anio = $anioCerrado AND mes = $mesCerrado");
}

echo "2. Complemento de pago de un gasto sin compra\n";
$gastoB = $crearGasto('B', 'FA500000-0000-4000-8000-0000000000B1');
$uuidCompB = 'FA500000-0000-4000-8000-0000000000B2';
$lecturaB = Lecturas::preparar(
    [$archivoXml('T50B-complemento.xml', $complementoXml('FA500000-0000-4000-8000-0000000000B1', $uuidCompB))],
    'gasto', $usuario, false
);
$prueba('un XML tipo P se desvía a Complementos de pago', $lecturaB !== null && (Lecturas::buscarID($lecturaB)['destino'] ?? '') === 'complemento');

$analisisB = $lecturaB !== null ? ComplementosPago::analizar($lecturaB) : null;
$doctoB = $analisisB['doctos'][0] ?? null;
$prueba('el complemento localiza el gasto y propone registrar el abono',
    $doctoB !== null && $doctoB['tipo_pago'] === 'gasto' && $doctoB['estado'] === 'sin_pago'
    && $doctoB['puede_crear'] === true, json_encode($doctoB));

$okReg = $lecturaB !== null && ComplementosPago::registrar($lecturaB, ['doctos' => [0 => ['vincular' => 1, 'crear_pago' => 1]]], $usuario);
$estadoB = Gastos::estadoPagoGasto(Gastos::buscarID($gastoB));
$pagosB = PagosGasto::deGasto($gastoB);
$compB = $db->query("SELECT id, total, estado FROM complementos_pago WHERE uuid = '$uuidCompB'")->fetch_assoc();
$prueba('al confirmar se crea el abono y el gasto queda parcial',
    $okReg && $estadoB['clave'] === 'parcial' && $estadoB['pagado'] === 50000 && count($pagosB) === 1,
    json_encode($estadoB));
$prueba('el complemento se registra con el monto real del pago (no con el Total 0)',
    $compB !== null && (float) $compB['total'] === 500.0, json_encode($compB));
$prueba('el complemento y el abono quedan vinculados',
    (int) $db->query('SELECT COUNT(*) FROM complemento_pagos_gasto')->fetch_row()[0] >= 1);
$prueba('el mismo complemento no se registra dos veces',
    $lecturaB !== null && ComplementosPago::registrar($lecturaB, ['doctos' => [0 => ['vincular' => 1]]], $usuario) === false);

$prueba('cancelar el complemento cancela su abono y reabre el saldo',
    $compB !== null && ComplementosPago::cancelar((int) $compB['id'], 'Prueba de cancelación', $usuario)
    && Gastos::estadoPagoGasto(Gastos::buscarID($gastoB))['clave'] === 'pendiente',
    json_encode(Gastos::estadoPagoGasto(Gastos::buscarID($gastoB))));

echo "3. XML tipo P guardado como documento del gasto\n";
$gastoC = $crearGasto('C', 'FA500000-0000-4000-8000-0000000000C1');
$archivoC = $archivoXml('T50C-complemento.xml', $complementoXml('FA500000-0000-4000-8000-0000000000C1', 'FA500000-0000-4000-8000-0000000000C2', '400.00', '400.00'));
$okSubir = Archivos::subir('gasto', $gastoC, $archivoC, 'auto', $usuario, null, '', false);
$docC = $db->query("SELECT * FROM documentos WHERE entidad = 'gasto' AND entidad_id = $gastoC AND tipo = 'complemento_pago' LIMIT 1")->fetch_assoc();
$datosC = $docC ? (json_decode((string) $docC['datos_cfdi'], true) ?: []) : [];
$prueba('se guarda con tipo complemento_pago y sin UUID de factura',
    $okSubir && $docC !== null && $docC['uuid_fiscal'] === null && $docC['estado_validacion'] === 'no_aplica',
    json_encode($docC));
$prueba('guarda el monto real del pago en datos_cfdi',
    (string) ($datosC['complemento']['monto'] ?? '') === '400.00'
    && !str_contains((string) json_encode($datosC['alertas'] ?? []), 'no coincide'));
$prueba('un complemento de otra factura se rechaza',
    Archivos::subir('gasto', $gastoC, $archivoXml('T50C-otra.xml', $complementoXml('FA500000-0000-4000-8000-0000000000FF', 'FA500000-0000-4000-8000-0000000000C3')), 'auto', $usuario, null, '', false) === false);
$prueba('un complemento no se adjunta a un gasto sin factura',
    Archivos::subir('gasto', $crearGasto('D', '', 'PPD', false), $archivoXml('T50D-complemento.xml', $complementoXml('FA500000-0000-4000-8000-0000000000D1', 'FA500000-0000-4000-8000-0000000000D2')), 'auto', $usuario, null, '', false) === false);

echo "4. Filtros de la lista\n";
$gastoE = $crearGasto('E', 'FA500000-0000-4000-8000-0000000000E1');
Gastos::pagar($gastoE, [
    'fecha_pago' => $fecha, 'importe' => '1160', 'metodo_pago' => 'Transferencia',
    'metodo_pago_sat' => 'PPD', 'pago_token' => bin2hex(random_bytes(16)),
], $usuario);
$filtrosBase = ['anio' => 0, 'mes' => 0, 'q' => 'T50 '];
$listaParcial = Gastos::listar($filtrosBase + ['pago' => 'parcial'], 100, 0);
$listaSinPago = Gastos::listar($filtrosBase + ['pago' => 'sin_pago'], 100, 0);
$listaPagado = Gastos::listar($filtrosBase + ['pago' => 'pagado'], 100, 0);
$prueba('«parcial» trae el gasto con abonos',
    array_filter($listaParcial, static fn ($g) => (int) $g['id'] === $gastoA) !== []);
$prueba('«pagado» trae el gasto con el saldo cubierto',
    array_filter($listaPagado, static fn ($g) => (int) $g['id'] === $gastoE) !== []);
$prueba('«sin pagar» trae el gasto sin abonos vigentes',
    array_filter($listaSinPago, static fn ($g) => (int) $g['id'] === $gastoB) !== []);
$prueba('la lista informa lo abonado por gasto',
    (int) ($db->query("SELECT pagado_centavos FROM (SELECT COALESCE((SELECT SUM(ROUND(pg.importe * 100)) FROM pagos_gasto pg WHERE pg.gasto_id = $gastoA AND pg.estado = 'vigente'), 0) AS pagado_centavos) x")->fetch_row()[0]) === 50000);

$limpiar();
echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
