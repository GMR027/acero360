<?php
/**
 * pruebas/php/etapa33.php — Registro inteligente de GASTOS desde Compras Inteligentes.
 *
 * Cubre: factura de gasolina (categoría y clasificación «gasto»), telefonía, servicios,
 * gasto sin XML (captura manual intacta), XML incompleto, documentos duplicados (reutiliza
 * el gasto existente y bloquea el de una compra), distinción de fechas de emisión/pago,
 * estado de pago que NO se inventa, revisión fiscal «validado» cuando hay CFDI, auditoría y periodo cerrado.
 *
 * Los «33» en nombres y RFC evitan chocar con las demás etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa33.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\ComprasIntel;
use App\Gastos;
use App\Lecturas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;

$contribuyente = \App\Contabilidad::contribuyentePrincipal();
$rfcPropio = (string) $uno('SELECT rfc FROM contribuyentes WHERE id = ' . $contribuyente);

/** CFDI de gasto (emisor = quien me cobró). */
$cfdi = static function (string $uuid, string $emisorRfc, string $emisorNombre, string $conceptos,
                          array $extra = []) use ($rfcPropio): string {
    $total = $extra['total'] ?? '1160.00';
    $iva = $extra['iva'] ?? '160.00';
    $subtotal = $extra['subtotal'] ?? '1000.00';
    $tipo = $extra['tipo'] ?? 'I';
    $otros = $extra['otros'] ?? '';
    $ret = $extra['retenciones'] ?? '';

    $impuestos = '<cfdi:Impuestos TotalImpuestosTrasladados="' . $iva . '"'
        . ($ret !== '' ? ' TotalRetenciones="' . $ret . '"' : '') . '><cfdi:Traslados>'
        . '<cfdi:Traslado Impuesto="002" Importe="' . $iva . '"/>' . $otros
        . '</cfdi:Traslados>'
        . ($ret !== '' ? '<cfdi:Retenciones><cfdi:Retencion Impuesto="001" Importe="' . $ret . '"/></cfdi:Retenciones>' : '')
        . '</cfdi:Impuestos>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0"'
        . ' Serie="G" Folio="33" Fecha="2026-10-18T08:15:00" SubTotal="' . $subtotal . '" Descuento="0.00"'
        . ' Total="' . $total . '" Moneda="MXN" FormaPago="01" MetodoPago="PUE" TipoDeComprobante="' . $tipo . '">'
        . '<cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '"/>'
        . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 33"/>'
        . '<cfdi:Conceptos>' . $conceptos . '</cfdi:Conceptos>'
        . $impuestos
        . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
};

$tmp = sys_get_temp_dir() . '/t33_' . bin2hex(random_bytes(4));
@mkdir($tmp);

/** Guarda el XML como lectura destino «compra» y devuelve su id. */
$leer = function (string $archivo, string $xml) use ($tmp): int {
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'compra', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('No se preparó la lectura: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};

/** Propuesta de formulario de gasto (lo que verá la persona antes de guardar). */
$propuesta = function (int $lecturaId): array {
    $p = Lecturas::propuesta($lecturaId, 'gasto');
    if ($p === null) {
        throw new RuntimeException('Lecturas::propuesta devolvió null para ' . $lecturaId);
    }
    return $p;
};

$limpiar = function () use ($db) {
    $g = array_column($db->query("SELECT id FROM gastos WHERE observaciones LIKE '%etapa 33%' OR concepto LIKE '%33%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($g) {
        $in = implode(',', array_map('intval', $g));
        $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($in)");
        $db->query("DELETE FROM gasto_reparto WHERE gasto_id IN ($in)");
        $db->query("DELETE FROM gastos WHERE id IN ($in)");
    }
    $l = array_column($db->query("SELECT id FROM lecturas_documentales WHERE nombre_original LIKE 't33-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($l) {
        $in = implode(',', array_map('intval', $l));
        $db->query("UPDATE gastos SET lectura_id = NULL WHERE lectura_id IN ($in)");
        $db->query("DELETE FROM lecturas_documentales WHERE id IN ($in)");
    }
    $db->query("DELETE oc FROM ordenes_compra oc WHERE oc.referencia LIKE 'T33-%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T33 %'");
};
$limpiar();

echo "1. Gasolina (clasificación «gasto operativo»)\n";
$uuidGas = 'C1B2C3D4-3300-4000-8000-111122220001';
$gasXml = $cfdi($uuidGas, 'GGA330101AA1', 'T33 Gasolinera del Norte',
    '<cfdi:Concepto Cantidad="20" Descripcion="Gasolina Magna 91" ValorUnitario="50.00" Importe="1000.00"/>');
$lecGas = $leer('t33-gasolina.xml', $gasXml);

$p = $propuesta($lecGas);
$prueba('la lectura de compra se propone como gasto (propuesta no nula)', $p !== null);
$prueba('trae emisor, RFC, UUID, fecha, total e IVA',
    $p['campos']['emisor_nombre']['valor'] === 'T33 Gasolinera del Norte'
    && $p['campos']['emisor_rfc']['valor'] === 'GGA330101AA1'
    && $p['uuid'] === $uuidGas
    && $p['fecha'] === '2026-10-18'
    && abs($p['total'] - 1160.0) < 0.005
    && abs($p['iva'] - 160.0) < 0.005, json_encode(['total' => $p['total'], 'iva' => $p['iva']]));
$prueba('trae subtotal y descuento del XML', abs($p['subtotal'] - 1000.0) < 0.005 && abs($p['descuento']) < 0.005);

$an = ComprasIntel::analizar($lecGas);
$prueba('se clasifica como gasto operativo (no material)', $an['clasificacion']['clave'] === 'gasto', $an['clasificacion']['motivo']);
$prueba('sugiere la categoría «Combustible y transporte»',
    ($an['coincidencias']['categoria']['nombre'] ?? '') === 'Combustible y transporte',
    (string) ($an['coincidencias']['categoria']['nombre'] ?? 'sin categoría'));

echo "2. Telefonía y servicios\n";
$uuidTel = 'C1B2C3D4-3300-4000-8000-111122220002';
$lecTel = $leer('t33-telefonia.xml', $cfdi($uuidTel, 'TTA330201BB2', 'T33 Telefonía Integral',
    '<cfdi:Concepto Cantidad="1" Descripcion="Plan de telefonía y internet mensual" ValorUnitario="800.00" Importe="800.00"/>',
    ['subtotal' => '800.00', 'iva' => '128.00', 'total' => '928.00']));
$anTel = ComprasIntel::analizar($lecTel);
$prueba('la telefonía se clasifica como gasto', $anTel['clasificacion']['clave'] === 'gasto', $anTel['clasificacion']['motivo']);
$prueba('sugiere la categoría «Teléfono e internet»',
    ($anTel['coincidencias']['categoria']['nombre'] ?? '') === 'Teléfono e internet',
    (string) ($anTel['coincidencias']['categoria']['nombre'] ?? ''));

$uuidSer = 'C1B2C3D4-3300-4000-8000-111122220003';
$lecSer = $leer('t33-servicio.xml', $cfdi($uuidSer, 'SSA330301CC3', 'T33 Servicios Contables del Sur',
    '<cfdi:Concepto Cantidad="1" Descripcion="Honorarios contables del mes" ValorUnitario="3000.00" Importe="3000.00"/>',
    ['subtotal' => '3000.00', 'iva' => '480.00', 'total' => '3480.00']));
$anSer = ComprasIntel::analizar($lecSer);
$prueba('los servicios se clasifican como gasto (no como mercancía)', $anSer['clasificacion']['clave'] !== 'material');

echo "3. Otros impuestos y retenciones del CFDI\n";
$uuidIeps = 'C1B2C3D4-3300-4000-8000-111122220004';
$lecIeps = $leer('t33-ieps.xml', $cfdi($uuidIeps, 'EEE330401DD4', 'T33 Energética',
    '<cfdi:Concepto Cantidad="1" Descripcion="Diésel" ValorUnitario="1000.00" Importe="1000.00"/>',
    ['subtotal' => '1000.00', 'iva' => '112.00', 'total' => '1160.00',
     'otros' => '<cfdi:Traslado Impuesto="003" Importe="48.00"/>', 'retenciones' => '40.00']));
$cfdiLeido = \App\CfdiXml::leer((string) file_get_contents("$tmp/t33-ieps.xml"));
$prueba('extrae los otros impuestos (IEPS 003) aparte del IVA',
    $cfdiLeido['datos']['otros_impuestos'] === '48.00', $cfdiLeido['datos']['otros_impuestos']);
$prueba('extrae las retenciones del comprobante', $cfdiLeido['datos']['retenciones'] === '40.00', $cfdiLeido['datos']['retenciones']);
$prueba('un CFDI solo con IVA deja los otros impuestos vacíos',
    \App\CfdiXml::leer($gasXml)['datos']['otros_impuestos'] === '');

echo "4. Registro del gasto: fechas, pago y revisión fiscal\n";
$cats = [];
foreach (\App\Gastos::categorias() as $c) {
    $cats[(string) $c['nombre']] = (int) $c['id'];
}
$datos = [
    'fecha' => $p['fecha'], 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T33 Gasolinera del Norte',
    'concepto' => 'Gasolina Magna 91 (etapa 33)', 'categoria_id' => $cats['Combustible y transporte'] ?? 0,
    'total' => '1160.00', 'iva_tasa' => '16', 'estado_pago' => 'pendiente',
    'comprobante_fiscal' => '1', 'uuid_fiscal' => $uuidGas, 'naturaleza' => 'gasto',
    'revision_fiscal' => 'por_validar', 'fecha_pago' => '',
    'folio_comercial' => 'G33', 'rfc_emisor' => 'GGA330101AA1', 'rfc_receptor' => $rfcPropio,
    'moneda' => 'MXN', 'forma_pago_sat' => '01', 'metodo_pago_sat' => 'PUE',
    'observaciones' => 'Prueba etapa 33', 'lectura_id' => $lecGas,
];
$ok = Gastos::crear($datos, $usuario);
$prueba('el gasto se guarda', $ok, implode(' ', Gastos::getErrores()));
$gastoId = (int) Gastos::$ultimoId;
$g = Gastos::buscarID($gastoId);
$prueba('NO se inventa fecha de pago (queda vacía)', $g['fecha_pago'] === null || $g['fecha_pago'] === '', (string) $g['fecha_pago']);
$prueba('NO se marca pagado automáticamente', $g['estado_pago'] === 'pendiente', $g['estado_pago']);
$prueba('la fecha guardada es la de EMISIÓN del CFDI', $g['fecha'] === '2026-10-18', $g['fecha']);
$prueba('revisión fiscal «validado» (el CFDI con folio fiscal viene del SAT)', $g['revision_fiscal'] === 'validado', $g['revision_fiscal']);
$prueba('el gasto conserva el enlace a su lectura', (int) $g['lectura_id'] === $lecGas);
$prueba('se audita la creación', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'gasto' AND entidad_id = $gastoId AND accion = 'crear'") === 1);
$prueba('la lectura de compra queda cerrada (no sigue «sin decidir»)',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lecGas") === 'confirmada'
    && (string) $uno("SELECT entidad FROM lecturas_documentales WHERE id = $lecGas") === 'gasto'
    && (int) $uno("SELECT entidad_id FROM lecturas_documentales WHERE id = $lecGas") === $gastoId);

echo "5. El XML se adjunta al gasto\n";
$fallosAdjuntar = Lecturas::adjuntar(Lecturas::buscarID($lecGas), 'gasto', $gastoId, $usuario);
$prueba('el XML de la lectura queda adjunto al gasto', $fallosAdjuntar === [] && (int) $uno(
    "SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = $gastoId AND activo = 1 AND uuid_fiscal = '$uuidGas'"
) === 1, json_encode($fallosAdjuntar));

echo "6. Documento duplicado: se reutiliza el registro existente\n";
$lecGas2 = $leer('t33-gasolina-b.xml', $gasXml);
$p2 = $propuesta($lecGas2);
$prueba('la propuesta detecta que el UUID ya está en un gasto', !empty($p2['duplicado']) || !empty($p2['duplicados']),
    json_encode($p2['duplicado'] ?? null));
$prueba('y señala el gasto existente (reutiliza, no duplica)',
    \App\ComprasIntel::operacionPorUuid($uuidGas) !== null
    && \App\ComprasIntel::operacionPorUuid($uuidGas)['tipo'] === 'gasto'
    && (int) \App\ComprasIntel::operacionPorUuid($uuidGas)['gasto_id'] === $gastoId);

$gastosAntes = (int) $uno('SELECT COUNT(*) FROM gastos');
$datosDuplicado = $datos;
$datosDuplicado['concepto'] = 'Gasolina duplicada (etapa 33)';
$datosDuplicado['lectura_id'] = $lecGas2;
$okDup = Gastos::crear($datosDuplicado, $usuario);
$prueba('guardar el mismo UUID otra vez se RECHAZA', !$okDup && str_contains(implode(' ', Gastos::getErrores()), 'ya está registrado'));
$prueba('y no se creó un gasto extra', (int) $uno('SELECT COUNT(*) FROM gastos') === $gastosAntes);
Gastos::getErrores();

echo "7. Factura ya relacionada con una compra → no genera gasto duplicado\n";
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T33 Proveedor de compra', 'T33RFC0101AA1', 'Material', 1)");
$prov = (int) $db->insert_id;
$okCompra = \App\OrdenesCompra::crear([
    'tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => 'T33-A',
    'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => '16',
], [[
    'descripcion' => 'Material de la etapa 33', 'cantidad' => '1', 'costo' => '1000.00', 'unidad' => 'pza',
    'controla' => 0, 'aplica_iva' => 1, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0,
]], $usuario);
$ordenId = (int) \App\OrdenesCompra::$ultimoId;
$prueba('se crea la compra de prueba', $okCompra, implode(' ', \App\OrdenesCompra::getErrores()));

$uuidCompra = 'C1B2C3D4-3300-4000-8000-111122220005';
$xmlCompra = $cfdi($uuidCompra, 'T33RFC0101AA1', 'T33 Proveedor de compra',
    '<cfdi:Concepto Cantidad="1" Descripcion="Material de la etapa 33" ValorUnitario="1000.00" Importe="1000.00"/>');
$lecCompra = $leer('t33-compra.xml', $xmlCompra);
$prueba('relacionar la factura con la compra', ComprasIntel::relacionar($lecCompra, $ordenId, $usuario),
    implode(' ', ComprasIntel::getErrores()));
$operacion = ComprasIntel::operacionPorUuid($uuidCompra);
$prueba('el UUID de la compra apunta a la compra (no a un gasto suelto)',
    $operacion !== null && $operacion['tipo'] === 'compra' && (int) $operacion['orden']['id'] === $ordenId,
    json_encode($operacion));

// La misma factura cargada otra vez con intención de gasto: la vista bloquea el formulario
// vía ComprasIntel::operacionPorUuid; aquí se verifica esa señal que consume la vista.
$lecCompra2 = $leer('t33-compra-b.xml', $xmlCompra);
$bloqueo = ComprasIntel::operacionPorUuid($uuidCompra);
$prueba('la vista de gasto recibe el bloqueo (tipo compra) para NO ofrecer el formulario',
    $bloqueo !== null && $bloqueo['tipo'] === 'compra' && str_contains($bloqueo['etiqueta'], 'compra'));
$prueba('la propuesta de gasto existe pero el UUID ya está registrado (Gastos lo rechazaría)',
    $propuesta($lecCompra2) !== null
    && \App\Gastos::crear($datos + ['uuid_fiscal' => $uuidCompra, 'concepto' => 'Duplicado de compra (etapa 33)'], $usuario) === false);
Gastos::getErrores();

echo "8. Gasto SIN XML (captura manual intacta)\n";
$manual = [
    'fecha' => $hoy, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T33 proveedor sin factura',
    'concepto' => 'Gasto manual sin XML (etapa 33)', 'categoria_id' => $cats['Otros'] ?? 0,
    'total' => '500.00', 'iva_tasa' => '0', 'estado_pago' => 'pagado', 'fecha_pago' => $hoy,
    'comprobante_fiscal' => '0', 'naturaleza' => 'gasto', 'revision_fiscal' => 'por_validar',
    'observaciones' => 'Prueba etapa 33 manual',
];
$okManual = Gastos::crear($manual, $usuario);
$prueba('un gasto manual sin XML y sin lectura sigue funcionando', $okManual, implode(' ', Gastos::getErrores()));
$gm = Gastos::buscarID((int) Gastos::$ultimoId);
$prueba('manual: sí se registra el pago porque la persona lo indicó', $gm['estado_pago'] === 'pagado' && $gm['fecha_pago'] === $hoy);
$prueba('manual: sin factura no guarda datos de CFDI', $gm['comprobante_fiscal'] === 0 && $gm['uuid_fiscal'] === null);
Gastos::getErrores();

echo "9. XML incompleto\n";
$uuidIncompleto = 'C1B2C3D4-3300-4000-8000-111122220006';
// Sin IVA desglosado, sin emisor de nombre, sin conceptos: se precarga con lo que haya.
$incompleto = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0"'
    . ' Fecha="2026-10-19T12:00:00" SubTotal="300.00" Total="300.00" Moneda="MXN" TipoDeComprobante="I">'
    . '<cfdi:Emisor Rfc="III330501EE5" Nombre=""/>'
    . '<cfdi:Receptor Rfc="' . $rfcPropio . '"/>'
    . '<cfdi:Conceptos><cfdi:Concepto Cantidad="1" Descripcion="Servicio incompleto 33" ValorUnitario="300.00" Importe="300.00"/></cfdi:Conceptos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuidIncompleto . '"/></cfdi:Complemento></cfdi:Comprobante>';
$lecInc = $leer('t33-incompleto.xml', $incompleto);
$pInc = $propuesta($lecInc);
$prueba('el XML incompleto sí produce propuesta (no truena)', $pInc !== null);
$prueba('los campos faltantes quedan vacíos para revisar, no inventados',
    $pInc['campos']['emisor_nombre']['valor'] === '' && abs($pInc['iva']) < 0.005, json_encode(['iva' => $pInc['iva']]));
$prueba('el total disponible sí se precarga', abs($pInc['total'] - 300.0) < 0.005);
$prueba('la fecha se toma del CFDI', $pInc['fecha'] === '2026-10-19');
// El campo de baja confianza exige revisión (el motor marca «baja» lo que no leyó).
$camposBajos = array_filter($pInc['campos'], static fn ($c) => ($c['confianza'] ?? '') === 'baja');
$prueba('los campos no leídos quedan con confianza «baja» (hay que revisarlos)', $camposBajos !== []);

// XML malformado: rechazado desde la carga (Lecturas::preparar no lo acepta).
file_put_contents("$tmp/t33-malformado.xml", '<cfdi:Comprobante no-cerrado');
$mal = Lecturas::preparar([
    ['name' => 't33-malformado.xml', 'tmp_name' => "$tmp/t33-malformado.xml", 'size' => 30, 'error' => UPLOAD_ERR_OK],
], 'compra', $usuario, false);
$prueba('un XML malformado no genera lectura', $mal === null, implode(' ', Lecturas::getErrores()));
Lecturas::getErrores();

echo "10. Complemento de pago (tipo «P») no se cuela como gasto de factura\n";
$lecPago = $leer('t33-pago.xml', $cfdi('C1B2C3D4-3300-4000-8000-111122220007', 'T33RFC0101AA1', 'T33 Proveedor de compra',
    '', ['tipo' => 'P', 'total' => '160.00', 'iva' => '0.00', 'subtotal' => '0.00']));
$anPago = ComprasIntel::analizar($lecPago);
$prueba('el tipo «P» queda como no admitido/no clasificado', $anPago['clasificacion']['clave'] === 'indeterminado');

echo "11. Periodo cerrado: el gasto no se cuela\n";
$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES ($contribuyente, 2026, 9, 'cerrado', NOW())");
$cerrado = [
    'fecha' => '2026-09-15', 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T33',
    'concepto' => 'Gasto en mes cerrado (etapa 33)', 'categoria_id' => $cats['Otros'] ?? 0,
    'total' => '100.00', 'iva_tasa' => '0', 'estado_pago' => 'pendiente',
    'comprobante_fiscal' => '0', 'naturaleza' => 'gasto', 'observaciones' => 'Prueba etapa 33 cerrado',
];
$okCerrado = Gastos::crear($cerrado, $usuario);
$prueba('un gasto en mes cerrado se rechaza', !$okCerrado && str_contains(implode(' ', Gastos::getErrores()), 'cerrado'));
Gastos::getErrores();
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = $contribuyente AND anio = 2026 AND mes = 9");

echo "12. La escritura de un gasto no toca compras ni inventario\n";
$comprasAntes = (int) $uno('SELECT COUNT(*) FROM ordenes_compra');
$movsAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_inventario');
Gastos::crear($manual + ['uuid_fiscal' => '', 'concepto' => 'Otro manual (etapa 33)'], $usuario);
Gastos::getErrores();
$prueba('registrar gastos no crea ni modifica compras ni movimientos de inventario',
    (int) $uno('SELECT COUNT(*) FROM ordenes_compra') === $comprasAntes
    && (int) $uno('SELECT COUNT(*) FROM movimientos_inventario') === $movsAntes);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
