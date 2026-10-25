<?php
/**
 * pruebas/php/etapa35.php — Validación previa de ventas inteligentes (FASE 5).
 *
 * Cobertura pedida:
 *   · clientes existentes y nuevos (RFC normalizado, sin duplicados)
 *   · RFC inválido
 *   · datos fiscales incompletos (receptor y emisor)
 *   · importes incorrectos (conceptos, subtotal, descuento, IVA, total)
 *   · método y forma de pago vs los cobros reales de la operación
 *   · venta ya facturada (CFDI vinculado; corrección/sustitución autorizada)
 *   · fallos del proceso de emisión (sin PAC: la venta se conserva)
 *   · CFDI válido relacionado con la venta conservando su UUID
 *   · periodos cerrados, auditoría y que la validación NO escribe nada.
 * No se modifica el motor fiscal: sólo se lee.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa35.php
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
use App\Clientes;
use App\Contabilidad;
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

/** ¿Aparece algún elemento de la lista con el fragmento buscado? */
$contiene = static function (array $lista, string $fragmento): bool {
    foreach ($lista as $elemento) {
        if (str_contains((string) $elemento, $fragmento)) {
            return true;
        }
    }
    return false;
};

/** ¿Algún ítem del grupo X tiene error obligatorio? */
$grupoConError = static function (array $revision, string $grupo): bool {
    foreach ($revision['items'] as $item) {
        if ($item['grupo'] === $grupo && $item['estado'] === 'error') {
            return true;
        }
    }
    return false;
};

/* -------------------------------------------------------------------- *
 * ENTORNO: archivos temporales, clientes de prueba y limpieza
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t35_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$archivo = static function (string $nombre, string $contenido) use ($tmp): array {
    $ruta = $tmp . '/' . bin2hex(random_bytes(3));
    file_put_contents($ruta, $contenido);
    return ['name' => $nombre, 'tmp_name' => $ruta, 'size' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
};

/** Venta de prueba (2 × $100 con IVA) que además deja su ingreso 1:1. */
$crearVenta = static function (int $empresaId, int $clienteId) use ($usuario, $hoy, &$misVentas): int {
    $ok = Ventas::crear(
        ['empresa_id' => $empresaId, 'cliente_id' => $clienteId, 'fecha' => $hoy,
         'aplica_iva' => 1, 'descuento_pct' => 0, 'condiciones_compra' => ''],
        [37 => ['cantidad' => 2, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]],
        $usuario
    );
    if (!$ok) {
        throw new RuntimeException('No se pudo crear la venta de prueba: ' . implode(' · ', Ventas::getErrores()));
    }
    $misVentas[] = (int) Ventas::$ultimoId;
    return (int) Ventas::$ultimoId;
};

$misVentas = [];
$limpiar = static function () use ($db, &$misVentas) {
    if ($misVentas) {
        $in = implode(',', array_map('intval', $misVentas));
        $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
        $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
        $db->query("DELETE FROM ingresos WHERE venta_id IN ($in)");
        $db->query("DELETE FROM venta_detalles WHERE venta_id IN ($in)");
        $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN ($in)");
        $db->query("DELETE FROM ventas WHERE id IN ($in)");
    }
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T35 %'");
    $misVentas = [];
};
$limpiar();

// Stock holgado para las ventas de prueba (mismo patrón que etapa12/16).
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

/* ==================================================================== *
 * 1. RFC NORMALIZADO Y REUTILIZACIÓN DE CLIENTES (requisito 2)
 * ================================================================== */
echo "RFC y reutilización de clientes\n";
$prueba('normaliza espacios, guiones y minúsculas', VentasFiscal::normalizarRfc('demo 000101-a01') === 'DEMO000101A01');
$prueba('acepta un RFC con estructura válida', VentasFiscal::rfcConFormato('AAA940101AAA') === true);
$prueba('acepta los RFCs genéricos del SAT', VentasFiscal::rfcConFormato('XAXX010101000') && VentasFiscal::rfcConFormato('xexx010101000'));
$prueba('rechaza RFCs incompletos o con estructura rara',
    !VentasFiscal::rfcConFormato('AAA940101') && !VentasFiscal::rfcConFormato('AB12')
    && !VentasFiscal::rfcConFormato('AAA940101ABCD') && !VentasFiscal::rfcConFormato('1AAA940101AAA'));

$existeRfc = (int) $uno("SELECT id FROM clientes WHERE UPPER(REPLACE(REPLACE(rfc, ' ', ''), '-', '')) = 'DEMO000101A01'");
$encontrado = VentasFiscal::buscarClientePorRfc('demo 000101 a01');
$prueba('encuentra un cliente EXISTENTE por RFC normalizado', $encontrado !== null && (int) $encontrado['id'] === $existeRfc);
$prueba('un RFC desconocido no devuelve cliente', VentasFiscal::buscarClientePorRfc('ZZZ991231ZZZ') === null);

// Cliente nuevo con todos los datos fiscales.
$cliA = new Clientes([
    'nombre' => 'T35 Contacto A', 'nombre_empresa' => 'T35 Receptor SA', 'alias' => 'T35 A',
    'rfc' => 'AAA940101AAA', 'codigo_postal' => '01000', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G01',
]);
$erroresA = $cliA->validar();
$prueba('el cliente nuevo con datos fiscales válidos pasa su validación', $erroresA === [], implode(' | ', $erroresA));
$prueba('y se crea', $cliA->crear() === true && $cliA->id > 0, implode(' | ', Clientes::getErrores()));
$cliAId = (int) $cliA->id;
$rebuscado = VentasFiscal::buscarClientePorRfc('AAA 940101 AAA');
$prueba('queda localizable por su RFC con otro formato', $rebuscado !== null && (int) $rebuscado['id'] === $cliAId);

// Un cliente con el MISMO RFC no debe poder duplicarse.
$cliDuplicado = new Clientes([
    'nombre' => 'T35 Duplicado', 'nombre_empresa' => 'T35 Receptor SA',
    'rfc' => 'aaa940101aaa', 'codigo_postal' => '01000', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G01',
]);
$erroresDup = $cliDuplicado->validar();
$prueba('el alta con un RFC repetido se rechaza (se reutiliza la ficha existente)',
    $contiene($erroresDup, 'ya está registrado') && (int) $uno("SELECT COUNT(*) FROM clientes WHERE UPPER(REPLACE(REPLACE(rfc, ' ', ''), '-', '')) = 'AAA940101AAA'") === 1,
    implode(' | ', $erroresDup));

/* ==================================================================== *
 * 2. VENTAS DE PRUEBA Y DATOS FISCALES DEL RECEPTOR (requisitos 3 y 9)
 * ================================================================== */
echo "Datos fiscales del receptor\n";
$cliB = new Clientes(['nombre' => 'T35 Contacto B', 'nombre_empresa' => 'T35 Receptor B', 'rfc' => 'BBB940101BBB']);
$cliB->validar();
$prueba('cliente B (sólo con RFC) se crea', $cliB->crear() === true && $cliB->id > 0, implode(' | ', Clientes::getErrores()));
$cliBId = (int) $cliB->id;
/* Cliente C: con RFC pero SIN datos fiscales. Antes se tomaba «el primer cliente
 * de la base», pero las suites de navegador le dejan datos fiscales cargados y la
 * etapa dejaba de valer corrida sola: ahora el caso es propio y determinista. */
$cliC = new Clientes(['nombre' => 'T35 Contacto C', 'nombre_empresa' => 'T35 Receptor C', 'rfc' => 'CCC940101CCC']);
$cliC->validar();
$cliC->crear();
$cliCId = (int) $cliC->id;

$ventaF = $crearVenta(2, $cliAId);   // feliz: receptor completo
$ventaB = $crearVenta(2, $cliBId);   // sin datos fiscales
$ventaC = $crearVenta(2, $cliCId);   // con RFC pero sin datos fiscales
$ingresoF = (int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaF");
$ingresoB = (int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaB");

$revF = VentasFiscal::validar($ventaF);
$prueba('la venta se analiza y devuelve su folio', $revF !== null && $revF['venta']['folio'] !== '');
$prueba('cubre los 6 grupos (emisor, receptor, conceptos, importes, pago, emisión)',
    count(array_unique(array_column($revF['items'], 'grupo'))) === 6);
$prueba('cada validación queda clasificada en correcto/advertencia/error', (function (array $rev): bool {
    foreach ($rev['items'] as $item) {
        if (!isset(\App\VentasFiscal::ESTADOS[$item['estado']])) {
            return false;
        }
    }
    return true;
})($revF));
$prueba('sin método de pago declarado NO se puede facturar (error obligatorio)',
    $revF['puede_facturar'] === false && $contiene($revF['errores'], 'Falta el método de pago'));

$revB = VentasFiscal::validar($ventaB);
$prueba('datos fiscales incompletos → tres errores obligatorios',
    $contiene($revB['errores'], 'Falta el código postal fiscal del receptor')
    && $contiene($revB['errores'], 'Falta el régimen fiscal del receptor')
    && $contiene($revB['errores'], 'Falta el uso del CFDI del receptor'));
$prueba('con esos errores no se permite avanzar', $revB['puede_facturar'] === false);

$okCompletar = VentasFiscal::completarReceptor($ventaB, ['codigo_postal' => '06600', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03'], $usuario);
$prueba('se completan los datos fiscales desde la revisión', $okCompletar === true, implode(' · ', VentasFiscal::getErrores()));
$revB2 = VentasFiscal::validar($ventaB);
$prueba('tras completarlos desaparecen esos tres errores',
    !$contiene($revB2['errores'], 'código postal fiscal del receptor')
    && !$contiene($revB2['errores'], 'régimen fiscal del receptor')
    && !$contiene($revB2['errores'], 'uso del CFDI del receptor'));
$prueba('quedaron guardados en el cliente',
    (string) $uno("SELECT codigo_postal FROM clientes WHERE id = $cliBId") === '06600'
    && (string) $uno("SELECT regimen_fiscal FROM clientes WHERE id = $cliBId") === '601'
    && (string) $uno("SELECT uso_cfdi FROM clientes WHERE id = $cliBId") === 'G03');
$prueba('la acción quedó auditada', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'cliente' AND entidad_id = $cliBId AND accion = 'datos_fiscales'") >= 1);

$revC = VentasFiscal::validar($ventaC);
$prueba('un cliente con RFC pero sin datos fiscales se bloquea',
    $revC['puede_facturar'] === false && $contiene($revC['errores'], 'código postal fiscal del receptor'));

// El código postal debe ser de 5 dígitos y el régimen una clave del catálogo.
$prueba('código postal inválido se rechaza',
    VentasFiscal::completarReceptor($ventaB, ['codigo_postal' => '123'], $usuario) === false
    && $contiene(VentasFiscal::getErrores(), '5 dígitos'));
$prueba('régimen fuera de catálogo se rechaza',
    VentasFiscal::completarReceptor($ventaB, ['regimen_fiscal' => 'ZZ'], $usuario) === false
    && $contiene(VentasFiscal::getErrores(), 'no es una clave'));

// Completar con el RFC de OTRO cliente no duplica fichas.
$antesRfc = (string) $uno("SELECT rfc FROM clientes WHERE id = $cliBId");
$okAjeno = VentasFiscal::completarReceptor($ventaB, ['rfc' => 'AAA940101AAA'], $usuario);
$prueba('un RFC que pertenece a otro cliente NO se guarda (evita duplicados)',
    $okAjeno === false && $contiene(VentasFiscal::getErrores(), 'ya está registrado')
    && (string) $uno("SELECT rfc FROM clientes WHERE id = $cliBId") === $antesRfc);
$prueba('un RFC inválido se rechaza sin escribir',
    VentasFiscal::completarReceptor($ventaB, ['rfc' => 'AB12'], $usuario) === false
    && $contiene(VentasFiscal::getErrores(), 'no tiene el formato')
    && (string) $uno("SELECT rfc FROM clientes WHERE id = $cliBId") === $antesRfc);

// Emisor incompleto (momentáneamente sin CP).
$cpEmpresa = (string) $uno('SELECT cp FROM empresas WHERE id = 2');
$db->query('UPDATE empresas SET cp = NULL WHERE id = 2');
$prueba('emisor sin código postal → error obligatorio',
    $contiene(VentasFiscal::validar($ventaF)['errores'], 'código postal fiscal del emisor'));
$db->query("UPDATE empresas SET cp = '$cpEmpresa' WHERE id = 2");
$prueba('restaurado el CP del emisor deja de ser error',
    !$contiene(VentasFiscal::validar($ventaF)['errores'], 'código postal fiscal del emisor'));

/* ==================================================================== *
 * 3. CONCEPTOS E IMPORTES (requisito 4)
 * ================================================================== */
echo "Conceptos e importes\n";
$revLimpia = VentasFiscal::validar($ventaF);
$prueba('los importes de la venta nueva son correctos', !$grupoConError($revLimpia, 'importes'));
$prueba('los conceptos también', !$grupoConError($revLimpia, 'conceptos'));
$prueba('y el ingreso contable refleja la misma venta', !$grupoConError($revLimpia, 'importes'));

$totalOk = number_format((float) $revLimpia['venta']['total'], 2, '.', '');
$ivaOk = number_format((float) $revLimpia['venta']['iva'], 2, '.', '');
$descuentoOk = number_format((float) $revLimpia['venta']['descuento'], 2, '.', '');

$db->query("UPDATE ventas SET total = total + 100 WHERE id = $ventaF");
$revTotal = VentasFiscal::validar($ventaF);
$prueba('total alterado → error obligatorio', $contiene($revTotal['errores'], 'El total no cuadra'));
$prueba('y el ingreso queda marcado desincronizado', $contiene($revTotal['errores'], 'desincronizado'));
$db->query("UPDATE ventas SET total = $totalOk WHERE id = $ventaF");

$db->query("UPDATE ventas SET iva = 0 WHERE id = $ventaF");
$prueba('IVA que no corresponde a la operación → error', $contiene(VentasFiscal::validar($ventaF)['errores'], 'El IVA no corresponde'));
$db->query("UPDATE ventas SET iva = $ivaOk WHERE id = $ventaF");

$db->query("UPDATE ventas SET descuento = subtotal + 10 WHERE id = $ventaF");
$prueba('descuento mayor al subtotal → error', $contiene(VentasFiscal::validar($ventaF)['errores'], 'mayor al subtotal'));
$db->query("UPDATE ventas SET descuento = $descuentoOk WHERE id = $ventaF");

$db->query("UPDATE venta_detalles SET subtotal = subtotal + 7 WHERE venta_id = $ventaF");
$prueba('concepto con importe que no cuadra → error', $contiene(VentasFiscal::validar($ventaF)['errores'], 'no coincide con cantidad × precio'));
$db->query("UPDATE venta_detalles SET subtotal = subtotal - 7 WHERE venta_id = $ventaF");

$revRestaurada = VentasFiscal::validar($ventaF);
$prueba('con los importes restaurados vuelve a quedar sin errores de importes', !$grupoConError($revRestaurada, 'importes'));

/* ==================================================================== *
 * 4. MÉTODO Y FORMA DE PAGO vs LOS DATOS REALES (requisitos 5 y 9)
 * ================================================================== */
echo "Método y forma de pago\n";
$prueba('método distinto de PUE/PPD se rechaza',
    VentasFiscal::guardarPago($ventaF, 'XX', '03') === false && $contiene(VentasFiscal::getErrores(), 'PUE o PPD'));
$prueba('forma de pago con un solo dígito se rechaza',
    VentasFiscal::guardarPago($ventaF, 'PUE', '9') === false && $contiene(VentasFiscal::getErrores(), '2 dígitos'));

$prueba('guardar PUE con forma "Por definir" se rechaza al validar',
    VentasFiscal::guardarPago($ventaF, 'PUE', '99') === true
    && $contiene(VentasFiscal::validar($ventaF)['errores'], 'no puede declararse la forma de pago "99'));
$prueba('declarar PUE con forma real (transferencia) deja de ser error',
    VentasFiscal::guardarPago($ventaF, 'PUE', '03') === true
    && !$contiene(VentasFiscal::validar($ventaF)['errores'], 'método de pago')
    && !$contiene(VentasFiscal::validar($ventaF)['errores'], 'forma de pago'));

$revLista = VentasFiscal::validar($ventaF);
$prueba('venta completa → 0 errores obligatorios y puede facturar',
    $revLista['puede_facturar'] === true && $revLista['resumen']['error'] === 0,
    implode(' · ', $revLista['errores']));
$prueba('la propuesta de método/forma sale de los datos de la operación',
    $revLista['propuestas']['metodo'] === 'PUE' && $revLista['propuestas']['forma'] === '03', json_encode($revLista['propuestas']));

// Con un cobro real en efectivo la propuesta cambia y la coherencia se juzga con él.
$prueba('cobrar el total de la venta', Contabilidad::registrarCobro($ingresoF, $hoy, '232.00', 'Efectivo', 'COBRO-T35', $usuario),
    implode(' · ', Contabilidad::getErrores()));
$revCobrada = VentasFiscal::validar($ventaF);
$prueba('la forma propuesta sigue al cobro real (efectivo → 01)', $revCobrada['propuestas']['forma'] === '01');
$prueba('los cobros reflejan la operación', $contiene(array_column($revCobrada['items'], 'detalle'), 'Cobrado 232.00'));

VentasFiscal::guardarPago($ventaF, 'PPD', '03');
$revPpd = VentasFiscal::validar($ventaF);
$prueba('PPD con la venta cobrada al 100% → advertencia de coherencia',
    $contiene($revPpd['advertencias'], 'ya está cobrada al 100%'));

// Cobro que excede el total (sólo por SQL: registrarCobro lo impide) → error.
$db->query("INSERT INTO cobros (ingreso_id, fecha, importe, metodo, estado, usuario_id) VALUES ($ingresoF, '$hoy', 999.99, 'Efectivo', 'vigente', $usuario)");
$prueba('cobros que superan el total → error obligatorio',
    $contiene(VentasFiscal::validar($ventaF)['errores'], 'superan el total'));
$db->query("DELETE FROM cobros WHERE ingreso_id = $ingresoF AND importe = 999.99");

VentasFiscal::guardarPago($ventaF, 'PUE', '03');
$revRecuperada = VentasFiscal::validar($ventaF);
$prueba('con la operación cuadrada la venta vuelve a poder facturarse', $revRecuperada['puede_facturar'] === true, implode(' · ', $revRecuperada['errores']));

/* ==================================================================== *
 * 5. PERIODOS CERRADOS Y VENTA CANCELADA (requisito 13)
 * ================================================================== */
echo "Periodos cerrados y cancelaciones\n";
$anio = (int) substr($hoy, 0, 4);
$mes = (int) substr($hoy, 5, 2);
$creoPeriodo = (int) $uno("SELECT COUNT(*) FROM periodos_contables WHERE contribuyente_id = 1 AND anio = $anio AND mes = $mes") === 0;
if ($creoPeriodo) {
    $db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES (1, $anio, $mes, 'cerrado', NOW())");
}
$prueba('periodo cerrado de la venta → error obligatorio',
    $contiene(VentasFiscal::validar($ventaF)['errores'], 'cerrado'));
$emitidaCerrado = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('y la emisión se niega por periodo cerrado',
    $emitidaCerrado['ok'] === false && $contiene($emitidaCerrado['errores'], 'cerrado'));
if ($creoPeriodo) {
    $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = $anio AND mes = $mes AND estado = 'cerrado'");
}

$db->query("UPDATE ventas SET estado = 'cancelada' WHERE id = $ventaF");
$prueba('una venta cancelada no puede facturarse',
    $contiene(VentasFiscal::validar($ventaF)['errores'], 'La venta está cancelada'));
$db->query("UPDATE ventas SET estado = 'registrada' WHERE id = $ventaF");
$prueba('y al restaurarla vuelve a ser facturable', VentasFiscal::validar($ventaF)['puede_facturar'] === true);

/* ==================================================================== *
 * 6. VENTA YA FACTURADA Y SUSTITUCIÓN AUTORIZADA (requisito 6)
 * ================================================================== */
echo "Venta ya facturada (CFDI vinculado)\n";
$uuidPrevio = 'FA350000-0000-4000-8000-000000000035';
$okFacturar = Contabilidad::actualizarFacturacion($ingresoF, 'facturada', 'F35-1', $uuidPrevio, $hoy, $usuario, true);
$prueba('la venta queda marcada como facturada con su UUID', $okFacturar === true, implode(' · ', Contabilidad::getErrores()));

$revYa = VentasFiscal::validar($ventaF);
$prueba('ya facturada con CFDI → error y no se permite reemitir',
    $revYa['puede_facturar'] === false && $contiene($revYa['errores'], 'ya tiene un CFDI válido vinculado'));

$rechazo = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('re-facturar sin corrección autorizada se rechaza',
    $rechazo['ok'] === false && $contiene($rechazo['errores'], 'ya tiene un CFDI válido'));
$prueba('el rechazo quedó auditado',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'venta' AND entidad_id = $ventaF AND accion = 'emision' AND motivo = 'Rechazada: CFDI ya vinculado'") >= 1);

$sinMotivo = VentasFiscal::emitir($ventaF, true, 'abc', $usuario);
$prueba('la sustitución exige un motivo real', $sinMotivo['ok'] === false && $contiene($sinMotivo['errores'], 'motivo'));

$subs = VentasFiscal::emitir($ventaF, true, 'Corrección de cantidades (etapa 35)', $usuario);
$prueba('con sustitución autorizada el intento continúa hasta el PAC', $subs['ok'] === false && $subs['pendiente_pac'] === true);
$prueba('la sustitución quedó auditada con su motivo',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'venta' AND entidad_id = $ventaF AND accion = 'sustitucion' AND motivo = 'Corrección de cantidades (etapa 35)'") >= 1);
$prueba('no se perdió el CFDI anterior (UUID conservado)',
    (string) $uno("SELECT uuid_fiscal FROM ingresos WHERE id = $ingresoF") === $uuidPrevio
    && (string) $uno("SELECT estado_facturacion FROM ingresos WHERE id = $ingresoF") === 'facturada');

$prueba('se puede volver a pendiente de facturar',
    Contabilidad::actualizarFacturacion($ingresoF, 'sin_clasificar', '', '', '', $usuario, false) === true,
    implode(' · ', Contabilidad::getErrores()));

/* ==================================================================== *
 * 7. FALLO DEL PROCESO DE EMISIÓN SIN PAC (requisitos 10 y 11)
 * ================================================================== */
echo "Emisión sin PAC\n";
$prueba('no existe integración con PAC (no se simula timbrado)', VentasFiscal::pacDisponible() === null);

$antes = [
    'ventas'    => (int) $uno('SELECT COUNT(*) FROM ventas'),
    'ingresos'  => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros'    => (int) $uno('SELECT COUNT(*) FROM cobros'),
    'estado'    => (string) $uno("SELECT estado_facturacion FROM ingresos WHERE id = $ingresoF"),
    'uuid'      => (string) ($uno("SELECT uuid_fiscal FROM ingresos WHERE id = $ingresoF") ?? ''),
    'total'     => (string) $uno("SELECT total FROM ingresos WHERE id = $ingresoF"),
];
$fallo = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('con validación aprobada el intento informa pendiente de PAC',
    $fallo['ok'] === false && $fallo['pendiente_pac'] === true && $contiene($fallo['errores'], 'no hay integración con un PAC'),
    implode(' · ', $fallo['errores']));
$despues = [
    'ventas'    => (int) $uno('SELECT COUNT(*) FROM ventas'),
    'ingresos'  => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros'    => (int) $uno('SELECT COUNT(*) FROM cobros'),
    'estado'    => (string) $uno("SELECT estado_facturacion FROM ingresos WHERE id = $ingresoF"),
    'uuid'      => (string) ($uno("SELECT uuid_fiscal FROM ingresos WHERE id = $ingresoF") ?? ''),
    'total'     => (string) $uno("SELECT total FROM ingresos WHERE id = $ingresoF"),
];
$prueba('la venta se conserva y NO se crean facturas ni ingresos duplicados', $antes === $despues, json_encode(['antes' => $antes, 'despues' => $despues]));
$prueba('queda auditado el intento fallido',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'venta' AND entidad_id = $ventaF AND accion = 'emision' AND motivo = 'Intento de emisión sin PAC configurado'") >= 1);

/* ==================================================================== *
 * 8. CFDI VÁLIDO RELACIONADO CON LA VENTA (requisito 12)
 * ================================================================== */
echo "Relación del CFDI existente\n";
$uuidCfdi = 'FA350001-0000-4000-8000-000000000035';
$rfcEmisor = (string) $uno('SELECT rfc FROM contribuyentes WHERE activo = 1 ORDER BY id LIMIT 1');
$xmlCfdi = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="' . $hoy . 'T10:00:00"'
    . ' SubTotal="200.00" Total="232.00" Moneda="MXN" TipoDeComprobante="I">'
    . '<cfdi:Emisor Rfc="' . $rfcEmisor . '" Nombre="Soluciones en Acero"/>'
    . '<cfdi:Receptor Rfc="AAA940101AAA" Nombre="T35 Receptor SA"/>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuidCfdi . '"/>'
    . '</cfdi:Complemento></cfdi:Comprobante>';

$subir = static fn (array $a) => Archivos::subir('ingreso', $ingresoF, $a, 'xml', $usuario, null, '', false);
$prueba('se adjunta el CFDI con timbre al ingreso de la venta',
    $subir($archivo('cfdi-t35.xml', $xmlCfdi)) === true, implode(' · ', Archivos::getErrores()));

$revAdjunta = VentasFiscal::validar($ventaF);
$prueba('la validación detecta el CFDI listo para relacionar',
    $revAdjunta['cfdi'] !== null && $contiene(array_column($revAdjunta['items'], 'detalle'), 'listo para relacionar'));

$relacionada = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('confirmar RELACIONA el CFDI con la venta', $relacionada['ok'] === true && $contiene([$relacionada['mensaje'] ?? ''], 'UUID'),
    implode(' · ', $relacionada['errores']));
$prueba('se conserva su UUID en el ingreso de la venta',
    (string) $uno("SELECT uuid_fiscal FROM ingresos WHERE id = $ingresoF") === $uuidCfdi);
$prueba('y queda facturada con el documento enlazado',
    (string) $uno("SELECT estado_facturacion FROM ingresos WHERE id = $ingresoF") === 'facturada'
    && (int) $uno("SELECT factura_documento_id FROM ingresos WHERE id = $ingresoF") > 0);
$prueba('la relación quedó auditada',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'venta' AND entidad_id = $ventaF AND accion = 'emision' AND motivo = 'CFDI existente relacionado con la venta'") >= 1);

$reintento = VentasFiscal::emitir($ventaF, false, '', $usuario);
$prueba('con el CFDI ya vinculado no se puede facturar otra vez (requisito 6)',
    $reintento['ok'] === false && $contiene($reintento['errores'], 'ya tiene un CFDI válido'));

/* ==================================================================== *
 * 9. LA VALIDACIÓN ES SÓLO LECTURA (requisitos 13 y 1)
 * ================================================================== */
echo "Alcance\n";
$conteosAntes = [
    'ventas' => (int) $uno('SELECT COUNT(*) FROM ventas'), 'ingresos' => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros' => (int) $uno('SELECT COUNT(*) FROM cobros'), 'clientes' => (int) $uno('SELECT COUNT(*) FROM clientes'),
    'documentos' => (int) $uno('SELECT COUNT(*) FROM documentos'), 'periodos' => (int) $uno('SELECT COUNT(*) FROM periodos_contables'),
];
VentasFiscal::validar($ventaB);
VentasFiscal::validar($ventaC);
$conteosDespues = [
    'ventas' => (int) $uno('SELECT COUNT(*) FROM ventas'), 'ingresos' => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros' => (int) $uno('SELECT COUNT(*) FROM cobros'), 'clientes' => (int) $uno('SELECT COUNT(*) FROM clientes'),
    'documentos' => (int) $uno('SELECT COUNT(*) FROM documentos'), 'periodos' => (int) $uno('SELECT COUNT(*) FROM periodos_contables'),
];
$prueba('la validación previa no escribe nada en la base', $conteosAntes === $conteosDespues, json_encode($conteosDespues));
$prueba('el motor fiscal no se toca: la venta sigue con su ingreso 1:1',
    (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $ventaF") === 1);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
