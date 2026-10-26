<?php
/**
 * pruebas/php/etapa44.php — Los cuatro datos del CFDI (método, forma, uso y tipo
 * de comprobante) autocompletados desde el documento, en el gasto y en la venta.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa44.php
 *
 * Comprueba:
 *   · migración 039: existen gastos.tipo_cfdi, ingresos.uso_cfdi e ingresos.tipo_cfdi;
 *   · el XML entrega los cuatro datos con confianza «alta» y el PDF los detecta
 *     junto a su etiqueta (sin adivinar si no están);
 *   · CoincidenciasFactura los devuelve AUNQUE no se reconozca al tercero ni a un
 *     producto: son datos del comprobante, no del cotejo;
 *   · una lectura guardada sin esos campos (hecha con el motor anterior) los
 *     completa releyendo el XML, sin tocar el resto de lo leído;
 *   · el formulario de gasto trae los cuatro campos visibles y el alta los
 *     precarga; el gasto los guarda o los limpia según «¿Tiene factura?»;
 *   · la edición cambia forma y tipo de comprobante;
 *   · guardarDatosLeidos() escribe en el ingreso lo que viene en catálogo y no
 *     toca nada con lo que no, y la validación previa a facturar usa el uso del
 *     CFDI antes que el de la ficha del cliente.
 *
 * Los nombres llevan «T44» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Archivos;
use App\CoincidenciasFactura;
use App\Contabilidad;
use App\Gastos;
use App\LectorDocumental;
use App\Lecturas;
use App\Ventas;
use App\VentasFiscal;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$cliente = (int) $uno('SELECT MIN(id) FROM clientes');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;

/** CFDI 4.0 mínimo; $atributos extra van en el nodo Comprobante. */
$cfdi = static function (array $atributos = []): string {
    $comprobante = '';
    foreach ($atributos as $clave => $valor) {
        $comprobante .= " $clave=\"$valor\"";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
        . ' Version="4.0" Serie="T44" Folio="7" Fecha="2026-10-20T10:00:00" SubTotal="100.00" Total="116.00"'
        . $comprobante . '>'
        . '<cfdi:Emisor Rfc="AAA010101AAA" Nombre="Soluciones en Acero" RegimenFiscal="601"/>'
        . '<cfdi:Receptor Rfc="ZZYY010101AAA" Nombre="Cliente T44" RegimenFiscal="601" UsoCFDI="G03"/>'
        . '<cfdi:Conceptos><cfdi:Concepto Cantidad="1" Unidad="pza" Descripcion="Producto T44"'
        . ' ValorUnitario="100.00" Importe="100.00"/></cfdi:Conceptos>'
        . '<cfdi:Impuestos TotalImpuestosTrasladados="16.00"><cfdi:Traslados>'
        . '<cfdi:Traslado Base="100.00" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="16.00"/>'
        . '</cfdi:Traslados></cfdi:Impuestos>'
        . '<tfd:TimbreFiscalDigital UUID="44444444-1111-4222-8333-444444444444" FechaTimbrado="2026-10-20T10:01:00"'
        . ' RfcProvCertif="SAT00000000000000000000" SelloCFD="x" NoCertificado="0000" Version="1.1"/>'
        . '</cfdi:Comprobante>';
};

/**
 * Limpia lo de esta etapa. Las ventas se marcan con `condiciones_compra = 'T44'`:
 * así se borran sin tocar ninguna otra de la base y en el orden que exigen las
 * llaves foráneas (primero cobros y documentos, luego el ingreso y la venta).
 */
$limpiar = function () use ($db) {
    $marcadas = "SELECT id FROM ventas WHERE condiciones_compra = 'T44'";
    $ingresos = "SELECT id FROM ingresos WHERE venta_id IN ($marcadas)";
    $db->query("DELETE FROM cobros WHERE ingreso_id IN ($ingresos)");
    $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN ($ingresos)");
    $db->query("DELETE FROM ingresos WHERE venta_id IN ($marcadas)");
    $db->query("DELETE FROM venta_detalles WHERE venta_id IN ($marcadas)");
    $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN ($marcadas)");
    $db->query("DELETE FROM ventas WHERE condiciones_compra = 'T44'");
    $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (SELECT id FROM gastos WHERE beneficiario LIKE 'T44%')");
    $db->query("DELETE FROM gasto_reparto WHERE gasto_id IN (SELECT id FROM gastos WHERE beneficiario LIKE 'T44%')");
    $db->query("DELETE FROM gastos WHERE beneficiario LIKE 'T44%'");
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't44%'");
};
$limpiar();

echo "Migración 039\n";
$columna = static fn (string $tabla, string $col): string => (string) $uno(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
    . " AND TABLE_NAME = '$tabla' AND COLUMN_NAME = '$col'"
);
$prueba('existe gastos.tipo_cfdi', $columna('gastos', 'tipo_cfdi') === '1');
$prueba('existe ingresos.uso_cfdi', $columna('ingresos', 'uso_cfdi') === '1');
$prueba('existe ingresos.tipo_cfdi', $columna('ingresos', 'tipo_cfdi') === '1');
$prueba('los cuatro datos están en LectorDocumental::CAMPOS',
    array_keys(array_intersect_key(LectorDocumental::CAMPOS,
        array_flip(['forma_pago', 'metodo_pago', 'uso_cfdi', 'tipo_cfdi']))) ===
        ['forma_pago', 'metodo_pago', 'uso_cfdi', 'tipo_cfdi']);

echo "Lectura de los cuatro datos\n";
$origen = Archivos::base() . '/lecturas/t44_' . bin2hex(random_bytes(4)) . '.xml';
$xmlConDatos = $cfdi(['FormaPago' => '03', 'MetodoPago' => 'PUE', 'TipoDeComprobante' => 'E']);
file_put_contents($origen, $xmlConDatos);
@chmod($origen, 0640);
$lectura = LectorDocumental::leer($origen, 'xml');
$leido = static fn (string $c): string => (string) ($lectura['campos'][$c]['valor'] ?? '');
$prueba('el XML entrega los cuatro datos', $leido('forma_pago') === '03' && $leido('metodo_pago') === 'PUE'
    && $leido('uso_cfdi') === 'G03' && $leido('tipo_cfdi') === 'E', json_encode($lectura['campos']));
$prueba('los cuatro salen con confianza alta (son del comprobante)',
    $lectura['campos']['uso_cfdi']['confianza'] === 'alta' && $lectura['campos']['tipo_cfdi']['confianza'] === 'alta');

$pdf = "FACTURA DE COMPRA\nUso del CFDI: G01\nTipo de comprobante: Ingreso\nForma de pago: 03\nMetodo de pago: PUE\nTotal: 116.00\n";
$delPdf = LectorDocumental::analizarTexto($pdf, 'pdf_texto');
$prueba('el PDF los detecta junto a su etiqueta',
    ($delPdf['uso_cfdi']['valor'] ?? '') === 'G01' && ($delPdf['tipo_cfdi']['valor'] ?? '') === 'I'
    && ($delPdf['forma_pago']['valor'] ?? '') === '03' && ($delPdf['metodo_pago']['valor'] ?? '') === 'PUE',
    json_encode($delPdf));
$sinDatos = LectorDocumental::analizarTexto("FACTURA\nTotal: 116.00\n", 'pdf_texto');
$prueba('sin la etiqueta no inventa nada', ($sinDatos['uso_cfdi']['valor'] ?? '') === '' && ($sinDatos['tipo_cfdi']['valor'] ?? '') === '');

echo "Independientes del resto del cotejo\n";
// Ni el emisor ni el receptor existen y el concepto no está en el catálogo: el
// cruce sale vacío, pero los datos del CFDI llegan igual. (Se usa un emisor
// neutro a propósito: «Soluciones en Acero» dispararía la regla «Material y
// acero» y la categoría SÍ se reconocería.)
$desconocido = $cfdi([
    'FormaPago' => '03', 'MetodoPago' => 'PUE', 'TipoDeComprobante' => 'E',
]);
$desconocido = str_replace(['AAA010101AAA', 'Soluciones en Acero', 'ZZYY010101AAA', 'Cliente T44'],
    ['XXX000000000', 'Taller Desconocido T44', 'ZZZZ010101AAA', 'Nadie T44'], $desconocido);
file_put_contents($origen, $desconocido);
$propuesta = CoincidenciasFactura::analizar(LectorDocumental::leer($origen, 'xml'), 'gasto');
file_put_contents($origen, $xmlConDatos);
$prueba('el cruce no reconoce a nadie', $propuesta['proveedor'] === null && $propuesta['categoria'] === null
    && $propuesta['lineas'] === [] && $propuesta['sin_equivalente'] !== [], json_encode($propuesta['proveedor']));
$prueba('los cuatro datos del CFDI llegan igual', $propuesta['forma_pago'] === '03' && $propuesta['metodo_pago'] === 'PUE'
    && $propuesta['uso_cfdi'] === 'G03' && $propuesta['tipo_cfdi'] === 'E', json_encode($propuesta));

echo "Lectura guardada sin esos campos (motor anterior)\n";
$lecturaId = Lecturas::preparar(
    [['name' => 't44_factura.xml', 'tmp_name' => $origen, 'size' => strlen($xmlConDatos), 'error' => UPLOAD_ERR_OK]],
    'gasto', $usuario, false
);
$prueba('la lectura se preparó', $lecturaId > 0, implode(' · ', Lecturas::getErrores()));

$propuestaLectura = $lecturaId > 0 ? Lecturas::propuesta($lecturaId, 'gasto') : null;
$prueba('la propuesta trae los cuatro datos',
    $propuestaLectura !== null && $propuestaLectura['uso_cfdi'] === 'G03' && $propuestaLectura['tipo_cfdi'] === 'E'
    && $propuestaLectura['forma_pago'] === '03' && $propuestaLectura['metodo_pago'] === 'PUE',
    json_encode($propuestaLectura ? [$propuestaLectura['uso_cfdi'], $propuestaLectura['tipo_cfdi']] : null));

// Se simula una lectura hecha con el motor anterior: sin uso, tipo ni forma en el JSON.
$vieja = $lecturaId > 0 ? Lecturas::buscarID($lecturaId) : null;
$datosViejos = json_decode((string) ($vieja['datos_leidos'] ?? ''), true) ?: [];
unset($datosViejos['campos']['uso_cfdi'], $datosViejos['campos']['tipo_cfdi'], $datosViejos['campos']['forma_pago']);
$datosViejosJson = json_encode($datosViejos, JSON_UNESCAPED_UNICODE);
$db->query("UPDATE lecturas_documentales SET datos_leidos = '" . $db->real_escape_string($datosViejosJson)
    . "' WHERE id = " . (int) $lecturaId);
$rereleyendo = $lecturaId > 0 ? Lecturas::propuesta($lecturaId, 'gasto') : null;
$prueba('se completan releyendo el XML, sin tocar el resto',
    $rereleyendo !== null && $rereleyendo['uso_cfdi'] === 'G03' && $rereleyendo['tipo_cfdi'] === 'E'
    && $rereleyendo['forma_pago'] === '03' && $rereleyendo['total'] > 0, json_encode($rereleyendo['campos'] ?? null));

echo "Formulario de gasto\n";
$crear = static function (array $extra) use ($empresa, $usuario, $hoy): int {
    $ok = Gastos::crear(array_merge([
        'fecha' => $hoy, 'empresa_id' => $empresa, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T44 Proveedor',
        'concepto' => 'T44 Factura', 'subtotal' => '1000', 'iva_tasa' => '16', 'naturaleza' => 'gasto',
        'estado_pago' => 'pagado', 'fecha_pago' => $hoy, 'comprobante_fiscal' => 1, 'revision_fiscal' => 'validado',
    ], $extra), $usuario);

    return $ok ? (int) Gastos::$ultimoId : 0;
};
$campos = static fn (int $id): array => $GLOBALS['db']->query(
    "SELECT forma_pago_sat, tipo_cfdi, uso_cfdi, metodo_pago_sat FROM gastos WHERE id = $id"
)->fetch_assoc();

$gasto = $crear(['forma_pago_sat' => '03', 'tipo_cfdi' => 'I', 'uso_cfdi' => 'G01', 'metodo_pago_sat' => 'PUE']);
$prueba('guarda forma y tipo junto a uso y método', $gasto > 0 && $campos($gasto) === [
    'forma_pago_sat' => '03', 'tipo_cfdi' => 'I', 'uso_cfdi' => 'G01', 'metodo_pago_sat' => 'PUE',
], json_encode($gasto ? $campos($gasto) : Gastos::getErrores()));
$prueba('una forma fuera del catálogo no pasa', $crear(['forma_pago_sat' => '77']) === 0
    && (bool) array_filter(Gastos::getErrores(), fn ($e) => str_contains($e, 'forma de pago')));
$prueba('un tipo fuera del catálogo no pasa', $crear(['tipo_cfdi' => 'X']) === 0
    && (bool) array_filter(Gastos::getErrores(), fn ($e) => str_contains($e, 'tipo de comprobante')));
$sinFactura = $crear(['comprobante_fiscal' => 0, 'forma_pago_sat' => '03', 'tipo_cfdi' => 'I', 'uso_cfdi' => 'G01']);
$prueba('un gasto sin factura limpia los cuatro datos del CFDI', $sinFactura > 0
    && $campos($sinFactura) === ['forma_pago_sat' => null, 'tipo_cfdi' => null, 'uso_cfdi' => null, 'metodo_pago_sat' => null],
    json_encode($sinFactura ? $campos($sinFactura) : Gastos::getErrores()));

$edicion = Gastos::actualizar($gasto, [
    'fecha' => $hoy, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T44 Proveedor', 'concepto' => 'T44 Factura',
    'subtotal' => '1000', 'iva_tasa' => '16', 'estado_pago' => 'pagado', 'comprobante_fiscal' => 1,
    'forma_pago_sat' => '01', 'tipo_cfdi' => 'E', 'uso_cfdi' => 'G03', 'metodo_pago_sat' => 'PPD',
], $usuario);
$prueba('la edición cambia forma y tipo', $edicion && $campos($gasto) === [
    'forma_pago_sat' => '01', 'tipo_cfdi' => 'E', 'uso_cfdi' => 'G03', 'metodo_pago_sat' => 'PPD',
], json_encode($edicion ? $campos($gasto) : Gastos::getErrores()));

echo "Venta: datos del CFDI en el ingreso\n";
$usoClienteAntes = $cliente > 0 ? $uno("SELECT uso_cfdi FROM clientes WHERE id = $cliente") : null;
try {
    if ($cliente <= 0) {
        throw new RuntimeException('no hay clientes en la base de prueba');
    }
    $db->query("UPDATE productos SET stock = 100 WHERE id = 37");
    $db->query("UPDATE clientes SET uso_cfdi = 'G01' WHERE id = $cliente");

    $ok = Ventas::crear(
        ['empresa_id' => $empresa, 'cliente_id' => $cliente, 'fecha' => $hoy,
         'aplica_iva' => 1, 'descuento_pct' => 0, 'condiciones_compra' => 'T44'],
        [37 => ['cantidad' => 1, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]],
        $usuario
    );
    if (!$ok) {
        throw new RuntimeException('no se pudo crear la venta: ' . implode(' · ', Ventas::getErrores()));
    }
    $ventaId = (int) Ventas::$ultimoId;
    $ingreso = Contabilidad::ingresoDeVenta($ventaId);
    $prueba('la venta de prueba dejó su ingreso', $ingreso !== null);

    $guardado = VentasFiscal::guardarDatosLeidos($ventaId, [
        'metodo_pago' => 'PUE', 'forma_pago' => '03', 'uso_cfdi' => 'G03', 'tipo_cfdi' => 'I',
    ], $usuario);
    $ingreso = $ingreso ? Contabilidad::ingresoDeVenta($ventaId) : null;
    $prueba('escribe los cuatro datos en el ingreso', $guardado && $ingreso !== null
        && $ingreso['metodo_pago'] === 'PUE' && $ingreso['forma_pago'] === '03'
        && $ingreso['uso_cfdi'] === 'G03' && $ingreso['tipo_cfdi'] === 'I',
        json_encode($guardado ? [$ingreso['metodo_pago'], $ingreso['forma_pago'], $ingreso['uso_cfdi'], $ingreso['tipo_cfdi']] : VentasFiscal::getErrores()));

    VentasFiscal::guardarDatosLeidos($ventaId, ['metodo_pago' => 'XYZ', 'forma_pago' => '77', 'uso_cfdi' => 'ZZ99', 'tipo_cfdi' => 'Q'], $usuario);
    $ingreso = Contabilidad::ingresoDeVenta($ventaId);
    $prueba('lo que no está en el catálogo del SAT no se escribe', $ingreso['metodo_pago'] === 'PUE'
        && $ingreso['forma_pago'] === '03' && $ingreso['uso_cfdi'] === 'G03' && $ingreso['tipo_cfdi'] === 'I',
        json_encode([$ingreso['metodo_pago'], $ingreso['forma_pago'], $ingreso['uso_cfdi'], $ingreso['tipo_cfdi']]));
    $prueba('queda en la bitácora', (int) $uno(
        "SELECT COUNT(*) FROM auditoria WHERE accion = 'datos_cfdi' AND entidad_id = " . (int) $ingreso['id']
    ) >= 1);

    // La ficha del cliente dice G01 y el CFDI de la venta dice G03: manda el documento.
    $revision = VentasFiscal::validar($ventaId);
    $usoDeLaValidacion = '';
    foreach (($revision['items'] ?? []) as $item) {
        if (($item['grupo'] ?? '') === 'receptor' && ($item['clave'] ?? '') === 'uso') {
            $usoDeLaValidacion = (string) $item['detalle'];
        }
    }
    $prueba('la validación usa el uso del CFDI antes que el de la ficha',
        $revision !== null && str_contains($usoDeLaValidacion, 'G03') && !str_contains($usoDeLaValidacion, 'G01'),
        $usoDeLaValidacion);
} catch (Throwable $e) {
    $fallos += 1;
    echo '  FALLA la sección de venta → ' . $e->getMessage() . "\n";
}

echo "Pantallas\n";
$raiz = dirname(__DIR__, 2);
$archivo = static fn (string $ruta): string => (string) file_get_contents($raiz . '/' . $ruta);
$formulario = $archivo('templates/formularioGasto.php');
$prueba('el formulario de gasto trae los cuatro campos visibles',
    str_contains($formulario, 'for="uso_cfdi"') && str_contains($formulario, 'for="metodo_pago_sat"')
    && str_contains($formulario, 'for="forma_pago_sat"') && str_contains($formulario, 'for="tipo_cfdi"'));
$prueba('la forma de pago ya no va oculta', !str_contains($formulario, '<input type="hidden" name="forma_pago_sat"'));
$prueba('los cuatro se ocultan juntos cuando no hay factura', substr_count($formulario, '<div class="campo" data-cfdi') === 4);
$altaGasto = $archivo('views/admin/contabilidad/gastos/crear.php');
$prueba('el alta precarga uso y tipo desde la lectura',
    str_contains($altaGasto, "'uso_cfdi'") && str_contains($altaGasto, "'tipo_cfdi'"));
$prueba('el detalle muestra forma y tipo',
    str_contains($archivo('views/admin/contabilidad/gastos/detalle.php'), 'Forma de pago')
    && str_contains($archivo('views/admin/contabilidad/gastos/detalle.php'), 'Tipo de comprobante'));
$facturacion = $archivo('templates/facturacionVenta.php');
$prueba('la facturación de la venta trae el tipo de comprobante',
    str_contains($facturacion, 'id="f-tipo"') && str_contains($facturacion, 'name="tipo_cfdi"'));
$prueba('el formulario de venta guarda los datos leídos en el ingreso',
    str_contains($archivo('views/admin/ventas/crear.php'), 'guardarDatosLeidos'));
$prueba('al facturar se guarda el tipo elegido', str_contains($archivo('views/admin/ventas/fiscal.php'), 'tipo_cfdi'));

// Limpieza final.
$limpiar();
if ($lecturaId > 0) {
    $lecturaBorrada = Lecturas::buscarID($lecturaId);
    if ($lecturaBorrada && is_file(Archivos::base() . '/' . $lecturaBorrada['archivo'])) {
        @unlink(Archivos::base() . '/' . $lecturaBorrada['archivo']);
    }
    $db->query('DELETE FROM lecturas_documentales WHERE id = ' . (int) $lecturaId);
}
@unlink($origen);
if ($cliente > 0) {
    if ($usoClienteAntes === null || $usoClienteAntes === '') {
        $db->query("UPDATE clientes SET uso_cfdi = NULL WHERE id = $cliente");
    } else {
        $db->query("UPDATE clientes SET uso_cfdi = '" . $db->real_escape_string((string) $usoClienteAntes) . "' WHERE id = $cliente");
    }
}

echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
