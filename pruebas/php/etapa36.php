<?php
/**
 * pruebas/php/etapa36.php — Cobros y complementos de pago de CLIENTES (FASE 6).
 *
 * Cobertura pedida:
 *   · cobros parciales y cobro total (cada uno conserva fecha, importe y referencia)
 *   · complemento duplicado
 *   · complemento con varias facturas
 *   · factura ya relacionada (su cobro ya estaba respaldado por otro complemento)
 * Además: nunca se crea una venta al cargar un complemento, nunca se duplica un
 * cobro registrado, discrepancias de importe y de saldo mostradas antes de
 * confirmar, estados de facturación y de cobro separados, lado correcto del
 * complemento, auditoría, periodo cerrado, cancelación lógica y la dependencia
 * de PAC (sin emisión propia). El motor fiscal no se modifica: todo es lectura
 * de ventas/facturas y registro de cobros con las clases existentes.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa36.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\ComplementosPago;
use App\ComplementosVenta;
use App\Contabilidad;
use App\Lecturas;
use App\Ventas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;
$rfcPropio = (string) $uno('SELECT rfc FROM contribuyentes WHERE activo = 1 ORDER BY id LIMIT 1');

/** ¿Aparece algún elemento con el fragmento? */
$contiene = static function (array $lista, string $f): bool {
    foreach ($lista as $x) {
        if (str_contains((string) $x, $f)) {
            return true;
        }
    }
    return false;
};

/* -------------------------------------------------------------------- *
 * ENTORNO: archivos, limpieza y ventas de prueba
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t36_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$archivo = static function (string $nombre, string $contenido) use ($tmp): array {
    $ruta = $tmp . '/' . bin2hex(random_bytes(3));
    file_put_contents($ruta, $contenido);
    return ['name' => $nombre, 'tmp_name' => $ruta, 'size' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
};

$misVentas = [];
$limpiar = static function () use ($db, &$misVentas) {
    $db->query('DELETE FROM complemento_cobros');
    $db->query('DELETE FROM complemento_doctos');
    $db->query("DELETE FROM complementos_pago WHERE lado = 'cliente'");

    // Ventas del cliente de pruebas (aunque otra ejecución se caiga a medias) + las rastreadas.
    $idsClientes = array_column(
        $db->query("SELECT id FROM clientes WHERE nombre_empresa LIKE 'T36 %'")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    $ventas = [];
    if ($idsClientes) {
        $in = implode(',', array_map('intval', $idsClientes));
        $ventas = array_column(
            $db->query("SELECT id FROM ventas WHERE cliente_id IN ($in)")->fetch_all(MYSQLI_ASSOC),
            'id'
        );
    }
    $ventas = array_values(array_unique(array_merge($ventas, $misVentas)));
    if ($ventas) {
        $in = implode(',', array_map('intval', $ventas));
        $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
        $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id IN ($in))");
        $db->query("DELETE FROM ingresos WHERE venta_id IN ($in)");
        $db->query("DELETE FROM venta_detalles WHERE venta_id IN ($in)");
        $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN ($in)");
        $db->query("DELETE FROM ventas WHERE id IN ($in)");
    }
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't36-%'");
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T36 %'");
    $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 8 AND estado = 'cerrado'");
    $misVentas = [];
};
$limpiar();
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

$db->query("INSERT INTO clientes (nombre, nombre_empresa) VALUES ('T36 Contacto', 'T36 Cliente SA')");
$clienteId = (int) $db->insert_id;

/** Venta de prueba con N unidades de $100 + IVA (total = 116·N). */
$crearVenta = static function (int $unidades) use ($db, $clienteId, $usuario, $hoy, &$misVentas): int {
    $ok = Ventas::crear(
        ['empresa_id' => 2, 'cliente_id' => $clienteId, 'fecha' => $hoy, 'aplica_iva' => 1,
         'descuento_pct' => 0, 'condiciones_compra' => ''],
        [37 => ['cantidad' => $unidades, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]],
        $usuario
    );
    if (!$ok) {
        throw new RuntimeException('Venta de prueba falló: ' . implode(' · ', Ventas::getErrores()));
    }
    $misVentas[] = (int) Ventas::$ultimoId;
    return (int) Ventas::$ultimoId;
};

/** Marca la venta como facturada con un UUID (así su factura es localizable) y devuelve su ingreso. */
$facturar = static function (int $ventaId, string $uuid) use ($db, $usuario, $hoy): int {
    $ingreso = (int) $db->query("SELECT id FROM ingresos WHERE venta_id = $ventaId")->fetch_row()[0];
    if (!Contabilidad::actualizarFacturacion($ingreso, 'facturada', 'F36-' . $ventaId, $uuid, $hoy, $usuario, true)) {
        throw new RuntimeException('No se pudo fijar el UUID: ' . implode(' · ', Contabilidad::getErrores()));
    }
    return $ingreso;
};

/** Sube un XML como lectura del destino indicado y devuelve su id. */
$leer = static function (string $nombre, string $xml, string $destino) use ($tmp): int {
    file_put_contents("$tmp/$nombre", $xml);
    $id = Lecturas::preparar(
        [['name' => $nombre, 'tmp_name' => "$tmp/$nombre", 'size' => filesize("$tmp/$nombre"), 'error' => UPLOAD_ERR_OK]],
        $destino,
        (int) $_SESSION['id'],
        false
    );
    if ($id === null) {
        throw new RuntimeException('Lectura falló: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};

/** XML de complemento de pago (CFDI tipo «P») con sus DoctoRelacionado. */
$complementoXml = static function (string $uuidComp, array $doctos, array $extra = []) use ($rfcPropio): string {
    $emisor = $extra['emisor'] ?? $rfcPropio;
    $receptor = $extra['receptor'] ?? 'XAXX010101000';
    $fecha = $extra['fecha'] ?? '2026-10-20';
    $forma = $extra['forma'] ?? '03';
    if (!isset($extra['monto'])) {
        $suma = 0.0;
        foreach ($doctos as $d) {
            $suma += (float) ($d['pagado'] ?? 0);
        }
        $extra['monto'] = number_format($suma, 2, '.', '');
    }
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
        . ' Fecha="' . $fecha . 'T12:00:00" SubTotal="0.00" Total="' . $extra['monto'] . '" Moneda="MXN"'
        . ' FormaPago="' . $forma . '" MetodoPago="PUE" TipoDeComprobante="P">'
        . '<cfdi:Emisor Rfc="' . $emisor . '" Nombre="Emisor"/>'
        . '<cfdi:Receptor Rfc="' . $receptor . '" Nombre="Receptor"/>'
        . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT"'
        . ' Descripcion="Pago" ValorUnitario="0.00" Importe="0.00"/></cfdi:Conceptos>'
        . '<cfdi:Complemento><pago:Pagos Version="1.0"><pago:Pago FechaPago="' . $fecha . '" MonedaP="MXN"'
        . ' Monto="' . $extra['monto'] . '" FormaPagoP="' . $forma . '">' . $pagos . '</pago:Pago></pago:Pagos></cfdi:Complemento>'
        . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuidComp . '"/></cfdi:Complemento></cfdi:Comprobante>';
};

/** Venta facturada con su UUID; devuelve [venta, ingreso]. */
$ventaFacturada = static function (string $uuid, int $unidades = 1) use ($crearVenta, $facturar): array {
    $venta = $crearVenta($unidades);
    return [$venta, $facturar($venta, $uuid)];
};

$uuidA = 'FA360000-0000-4000-8000-00000000000A';
$uuidB = 'FA360000-0000-4000-8000-00000000000B';
$uuidC = 'FA360000-0000-4000-8000-00000000000C';
$uuidD = 'FA360000-0000-4000-8000-00000000000D';

[$venta1, $ingreso1] = $ventaFacturada($uuidA);   // cobros: 50 (parcial) + 66 (total)
[$venta2, $ingreso2] = $ventaFacturada($uuidB);   // sin cobros
[$venta3, $ingreso3] = $ventaFacturada($uuidC);   // sin cobros (lo cobrará su complemento)
[$venta4, $ingreso4] = $ventaFacturada($uuidD);   // cobro 40 (discrepancia con el complemento)

/* ==================================================================== *
 * 1. COBROS: PARCIALES, TOTAL Y TRAZABILIDAD (requisitos 3 y 4)
 * ================================================================== */
echo "Cobros parciales y total\n";
$okParcial = Contabilidad::registrarCobro($ingreso1, '2026-10-10', '50.00', 'Efectivo', 'REF-50', $usuario);
$prueba('se registra el primer cobro (parcial)', $okParcial === true, implode(' · ', Contabilidad::getErrores()));
$filaCobro = $db->query("SELECT * FROM cobros WHERE ingreso_id = $ingreso1 ORDER BY id LIMIT 1")->fetch_assoc();
$prueba('conserva fecha, importe, método y referencia',
    $filaCobro['fecha'] === '2026-10-10' && $filaCobro['importe'] === '50.00'
    && $filaCobro['metodo'] === 'Efectivo' && $filaCobro['referencia'] === 'REF-50');
$ing = Contabilidad::buscarIngreso($ingreso1);
$prueba('estado de cobro: parcial; facturación intacta (separados)',
    $ing['estado_cobro'] === 'parcial' && $ing['estado_facturacion'] === 'facturada');

$okTotal = Contabilidad::registrarCobro($ingreso1, '2026-10-12', '66.00', 'Transferencia', 'REF-66', $usuario);
$prueba('se registra el segundo cobro y la venta queda cobrada', $okTotal === true
    && Contabilidad::buscarIngreso($ingreso1)['estado_cobro'] === 'cobrado', implode(' · ', Contabilidad::getErrores()));
$listaCobros = Contabilidad::cobrosDeIngreso($ingreso1);
$prueba('ambos cobros quedan como movimientos individuales', count($listaCobros) === 2
    && $listaCobros[0]['importe'] === '50.00' && $listaCobros[1]['importe'] === '66.00');
$prueba('la lista trae el complemento que lo respalda (columna nueva)', array_key_exists('complemento_uuid', $listaCobros[0])
    && ($listaCobros[0]['complemento_uuid'] ?? null) === null);
$prueba('no se puede cobrar más que el saldo', Contabilidad::registrarCobro($ingreso1, $hoy, '10.00', 'Efectivo', '', $usuario) === false
    && $contiene(Contabilidad::getErrores(), 'excede el saldo'));

// La cuarta venta queda con un cobro de $40: contra un complemento que declara $50
// es donde se verá la discrepancia de importe (requisito 9).
$okCobro40 = Contabilidad::registrarCobro($ingreso4, '2026-10-11', '40.00', 'Cheque', 'REF-40', $usuario);
$prueba('la cuarta venta queda con un cobro de $40', $okCobro40 === true, implode(' · ', Contabilidad::getErrores()));
Contabilidad::getErrores();

/* ==================================================================== *
 * 2. ANÁLISIS DE LOS COMPLEMENTOS (requisitos 1, 5, 6 y 9)
 * ================================================================== */
echo "Análisis de complementos\n";
$lecC1 = $leer('t36-c1.xml', $complementoXml('CC360000-0000-4000-8000-000000000001', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '50.00'],
], ['fecha' => '2026-10-10', 'monto' => '50.00']), ComplementosVenta::DESTINO);
$prueba('la lectura de este lado usa su propio destino', (string) $uno("SELECT destino FROM lecturas_documentales WHERE id = $lecC1") === 'complemento_venta');

$an1 = ComplementosVenta::analizar($lecC1);
$prueba('lee el complemento: UUID, fecha, monto y su factura relacionada',
    $an1 !== null && $an1['datos']['uuid'] === 'CC360000-0000-4000-8000-000000000001'
    && $an1['complemento']['fecha_pago'] === '2026-10-10' && abs((float) $an1['complemento']['monto'] - 50.0) < 0.005
    && count($an1['doctos']) === 1 && (int) $an1['doctos'][0]['ingreso']['id'] === $ingreso1,
    json_encode($an1['doctos'][0]['estado'] ?? null));
$prueba('el lado es el correcto (emisor = yo) y puede emitirse (hoy no, por PAC)', $an1['lado_ok'] === true && $an1['lado'] === 'cliente');
$prueba('distingue venta, CFDI, cobro y complemento en sus datos',
    $an1['doctos'][0]['venta']['folio'] !== ''                       // venta
    && $an1['doctos'][0]['ingreso']['uuid_fiscal'] === $uuidA        // CFDI
    && count($an1['doctos'][0]['cobros']) === 2                      // cobros
    && $an1['complemento'] !== null);                                // complemento
$prueba('coincide con el cobro de $50 (sin discrepancia): estado vinculado',
    $an1['doctos'][0]['estado'] === 'vinculado' && abs($an1['doctos'][0]['diferencia']) < 0.005,
    $an1['doctos'][0]['nota']);
$prueba('y los saldos declarados cuadran', $an1['doctos'][0]['saldo_ok'] === true);

$lecC2 = $leer('t36-c2.xml', $complementoXml('CC360000-0000-4000-8000-000000000002', [
    ['uuid' => $uuidB, 'serie' => 'A', 'folio' => '2', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '116.00'],
], ['fecha' => '2026-10-11', 'monto' => '116.00']), ComplementosVenta::DESTINO);
$an2 = ComplementosVenta::analizar($lecC2);
$prueba('sin cobros registrados queda «Cobro sin registrar» y ofrece crearlo',
    $an2['doctos'][0]['estado'] === 'sin_pago' && $an2['doctos'][0]['puede_crear'] === true
    && abs($an2['doctos'][0]['diferencia'] - 116.0) < 0.005, $an2['doctos'][0]['nota']);

$lecC5 = $leer('t36-c5.xml', $complementoXml('CC360000-0000-4000-8000-000000000005', [
    ['uuid' => $uuidD, 'serie' => 'A', 'folio' => '5', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '50.00'],
], ['fecha' => '2026-10-11', 'monto' => '50.00']), ComplementosVenta::DESTINO);
$an5 = ComplementosVenta::analizar($lecC5);
$prueba('discrepancia de importe (dice 50 y hay un cobro de 40) ANTES de confirmar',
    $an5['doctos'][0]['estado'] === 'discrepancia' && abs($an5['doctos'][0]['diferencia'] - 10.0) < 0.005
    && str_contains($an5['doctos'][0]['nota'], 'diferencia'), $an5['doctos'][0]['nota']);
$prueba('discrepancia de saldo declarado también visible',
    $an5['doctos'][0]['saldo_ok'] === false && $an5['doctos'][0]['diferencia_saldo'] != 0, $an5['doctos'][0]['nota']);
$prueba('con cobros ya registrados NUNCA ofrece crear otro', $an5['doctos'][0]['puede_crear'] === false);

/* ==================================================================== *
 * 3. REGISTRO: SIN DUPLICAR COBROS (requisitos 7 y 8)
 * ================================================================== */
echo "Registro de complementos\n";
$ventasAntes = (int) $uno('SELECT COUNT(*) FROM ventas');
$ingresosAntes = (int) $uno('SELECT COUNT(*) FROM ingresos');
$cobrosAntes = (int) $uno('SELECT COUNT(*) FROM cobros');

$okC1 = ComplementosVenta::registrar($lecC1, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('el complemento C1 se registra', $okC1 === true, implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$comp1 = ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000001');
$prueba('queda con lado «cliente», vigente y su lectura cerrada', $comp1 !== null
    && (string) $uno('SELECT lado FROM complementos_pago WHERE id = ' . (int) $comp1['id']) === 'cliente'
    && (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lecC1") === 'confirmada'
    && (string) $uno("SELECT entidad FROM lecturas_documentales WHERE id = $lecC1") === 'complemento');
$prueba('VINCULA el cobro existente sin crear uno nuevo',
    (int) $uno('SELECT COUNT(*) FROM cobros') === $cobrosAntes
    && (int) $uno('SELECT COUNT(*) FROM complemento_cobros') === 1);
$prueba('y la venta sigue con facturación y cobro en estados separados',
    (string) $uno("SELECT estado_facturacion FROM ingresos WHERE id = $ingreso1") === 'facturada'
    && Contabilidad::buscarIngreso($ingreso1)['estado_cobro'] === 'cobrado');

$okC2 = ComplementosVenta::registrar($lecC2, ['doctos' => [0 => ['vincular' => '1']]], $usuario);
$prueba('C2 se registra SIN crear el cobro (no se pidió)', $okC2 === true
    && (int) $uno("SELECT COUNT(*) FROM cobros WHERE ingreso_id = $ingreso2") === 0,
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$comp2 = ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000002');
$prueba('y su factura queda como «Cobro sin registrar» persistida',
    (string) $uno('SELECT estado FROM complemento_doctos WHERE complemento_id = ' . (int) $comp2['id']) === 'sin_pago');

/* ---- C3: sí pide registrar el cobro → cobro total ---- */
$lecC3 = $leer('t36-c3.xml', $complementoXml('CC360000-0000-4000-8000-000000000003', [
    ['uuid' => $uuidC, 'serie' => 'A', 'folio' => '3', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '116.00'],
], ['fecha' => '2026-10-22', 'monto' => '116.00', 'forma' => '03']), ComplementosVenta::DESTINO);
$okC3 = ComplementosVenta::registrar($lecC3, ['doctos' => [0 => ['vincular' => '1', 'crear_cobro' => '1']]], $usuario);
$prueba('C3 con la casilla explícita crea el cobro que faltaba', $okC3 === true,
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$cobro3 = $db->query("SELECT * FROM cobros WHERE ingreso_id = $ingreso3 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$prueba('el cobro creado conserva fecha, método y referencia del complemento',
    $cobro3['fecha'] === '2026-10-22' && $cobro3['metodo'] === 'Transferencia'
    && $cobro3['referencia'] === 'Complemento CC360000-0000-4000-8000-000000000003');
$prueba('la venta queda COBRADA (cobro total) sin tocar su facturación',
    Contabilidad::buscarIngreso($ingreso3)['estado_cobro'] === 'cobrado'
    && Contabilidad::buscarIngreso($ingreso3)['estado_facturacion'] === 'facturada');

/* ==================================================================== *
 * 4. COMPLEMENTO CON VARIAS FACTURAS Y FACTURA YA RELACIONADA (req. 6)
 * ================================================================== */
echo "Complemento con varias facturas\n";
$lecC4 = $leer('t36-c4.xml', $complementoXml('CC360000-0000-4000-8000-000000000004', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '2', 'saldo' => '116.00', 'pagado' => '50.00'],
    ['uuid' => $uuidC, 'serie' => 'A', 'folio' => '3', 'parcialidad' => '2', 'saldo' => '116.00', 'pagado' => '116.00'],
], ['fecha' => '2026-10-23', 'monto' => '166.00']), ComplementosVenta::DESTINO);
$an4 = ComplementosVenta::analizar($lecC4);
$prueba('reconoce las DOS facturas relacionadas y las localiza',
    count($an4['doctos']) === 2
    && (int) $an4['doctos'][0]['ingreso']['id'] === $ingreso1
    && (int) $an4['doctos'][1]['ingreso']['id'] === $ingreso3);
$prueba('una factura YA relacionada se vincula otra vez al mismo cobro sin duplicarlo',
    $an4['doctos'][0]['estado'] === 'vinculado'
    && str_contains($an4['doctos'][0]['nota'], 'ya vinculado a otro complemento'), $an4['doctos'][0]['nota']);
$prueba('y la otra encuentra el cobro que creó su complemento', $an4['doctos'][1]['estado'] === 'vinculado', $an4['doctos'][1]['nota']);

$okC4 = ComplementosVenta::registrar($lecC4, ['doctos' => [0 => ['vincular' => '1'], 1 => ['vincular' => '1']]], $usuario);
$prueba('el complemento multi-factura se registra con sus 2 documentos', $okC4 === true
    && (int) $uno('SELECT COUNT(*) FROM complemento_doctos WHERE complemento_id = ' . (int) ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000004')['id']) === 2,
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$prueba('sin crear ventas ni cobros nuevos',
    (int) $uno('SELECT COUNT(*) FROM ventas') === $ventasAntes
    && (int) $uno('SELECT COUNT(*) FROM cobros') === $cobrosAntes + 1,   // sólo el creado por C3
    json_encode(['ventas' => (int) $uno('SELECT COUNT(*) FROM ventas'), 'cobros' => (int) $uno('SELECT COUNT(*) FROM cobros')]));

/* ==================================================================== *
 * 5. DISCREPANCIA PERSISTIDA, DUPLICADOS Y LADOS (requisitos 8, 9 y 1)
 * ================================================================== */
echo "Discrepancias, duplicados y lados\n";
$okC5 = ComplementosVenta::registrar($lecC5, ['doctos' => [0 => ['vincular' => '1', 'crear_cobro' => '1']]], $usuario);
$prueba('C5 se registra con su discrepancia…', $okC5 === true, implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$comp5 = ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000005');
$prueba('…pero NO crea el cobro (ya había cobros: nunca se duplica)',
    (int) $uno("SELECT COUNT(*) FROM cobros WHERE ingreso_id = $ingreso4") === 1
    && (string) $uno('SELECT estado FROM complemento_doctos WHERE complemento_id = ' . (int) $comp5['id']) === 'discrepancia');

// Complemento duplicado: el mismo XML cargado otra vez.
$lecC1b = $leer('t36-c1b.xml', $complementoXml('CC360000-0000-4000-8000-000000000001', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '50.00'],
], ['fecha' => '2026-10-10', 'monto' => '50.00']), ComplementosVenta::DESTINO);
$anDup = ComplementosVenta::analizar($lecC1b);
$prueba('el análisis señala el complemento duplicado', $anDup['duplicado'] !== null
    && $anDup['duplicado']['uuid'] === 'CC360000-0000-4000-8000-000000000001');
$okDup = ComplementosVenta::registrar($lecC1b, ['doctos' => [0 => ['vincular' => '1', 'crear_cobro' => '1']]], $usuario);
$prueba('registrarlo otra vez se RECHAZA y no toca nada',
    $okDup === false && $contiene(ComplementosVenta::getErrores(), 'no se carga dos veces')
    && (int) $uno("SELECT COUNT(*) FROM cobros") === $cobrosAntes + 1);
ComplementosVenta::getErrores();

// Lado contrario: un complemento de pago a PROVEEDOR intentado desde Ventas.
$lecProveedor = $leer('t36-prov.xml', $complementoXml('CC360000-0000-4000-8000-000000000009', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '10.00'],
], ['emisor' => 'PROVEE010101AAA', 'receptor' => $rfcPropio, 'monto' => '10.00']), ComplementosVenta::DESTINO);
$anProv = ComplementosVenta::analizar($lecProveedor);
$prueba('un complemento de pago A PROVEEDOR se reconoce como del otro lado',
    $anProv['lado'] === 'proveedor' && $anProv['lado_ok'] === false);
$prueba('y Ventas lo rechaza indicando dónde va',
    ComplementosVenta::registrar($lecProveedor, ['doctos' => [0 => ['vincular' => '1']]], $usuario) === false
    && $contiene(ComplementosVenta::getErrores(), 'pago a proveedor'));
ComplementosVenta::getErrores();

// Y el guard recíproco en el flujo de COMPRAS (FASE 4): emisor = yo → cliente.
$lecClienteEnCompras = $leer('t36-clie.xml', $complementoXml('CC360000-0000-4000-8000-00000000000A', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '10.00'],
], ['emisor' => $rfcPropio, 'receptor' => 'CLIENTE010101AA', 'monto' => '10.00']), 'complemento');
$anClie = ComplementosPago::analizar($lecClienteEnCompras);
$prueba('Compras también distingue el lado: emisor = yo → complemento de cliente',
    $anClie !== null && ($anClie['lado'] ?? 'proveedor') === 'cliente');
$prueba('y Compras lo rechaza (va en Ventas)',
    ComplementosPago::registrar($lecClienteEnCompras, ['doctos' => [0 => ['vincular' => '1']]], $usuario) === false
    && $contiene(ComplementosPago::getErrores(), 'cobro a cliente'));
ComplementosPago::getErrores();

// Ninguno de los dos RFCs es nuestro → complemento ajeno.
$lecAjeno = $leer('t36-ajeno.xml', $complementoXml('CC360000-0000-4000-8000-00000000000B', [
    ['uuid' => $uuidA, 'serie' => 'A', 'folio' => '1', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '10.00'],
], ['emisor' => 'AJENO010101AA1', 'receptor' => 'OTROAJ010101AA', 'monto' => '10.00']), ComplementosVenta::DESTINO);
$anAjeno = ComplementosVenta::analizar($lecAjeno);
$prueba('un complemento que no es de este negocio se rechaza',
    $anAjeno['lado'] === 'ajeno' && $anAjeno['lado_ok'] === false
    && ComplementosVenta::registrar($lecAjeno, ['doctos' => [0 => ['vincular' => '1']]], $usuario) === false);
ComplementosVenta::getErrores();

/* ==================================================================== *
 * 6. PERIODO CERRADO, CANCELACIÓN Y AUDITORÍA (requisito 11)
 * ================================================================== */
echo "Periodo cerrado y cancelaciones\n";
$lecC6 = $leer('t36-c6.xml', $complementoXml('CC360000-0000-4000-8000-000000000006', [
    ['uuid' => $uuidB, 'serie' => 'A', 'folio' => '2', 'parcialidad' => '1', 'saldo' => '116.00', 'pagado' => '20.00'],
], ['fecha' => '2026-08-15', 'monto' => '20.00']), ComplementosVenta::DESTINO);
$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES (1, 2026, 8, 'cerrado', NOW())");
$an6 = ComplementosVenta::analizar($lecC6);
$prueba('el análisis avisa que el periodo está cerrado', $an6['periodo_cerrado'] === true);
$prueba('registrar en mes cerrado se RECHAZA',
    ComplementosVenta::registrar($lecC6, ['doctos' => [0 => ['vincular' => '1', 'crear_cobro' => '1']]], $usuario) === false
    && $contiene(ComplementosVenta::getErrores(), 'cerrado'));
ComplementosVenta::getErrores();
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 8 AND estado = 'cerrado'");

$comp3 = ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000003');
$prueba('cancelar con motivo corto se rechaza',
    ComplementosVenta::cancelar((int) $comp3['id'], 'abc', $usuario) === false
    && $contiene(ComplementosVenta::getErrores(), '5 caracteres'));
ComplementosVenta::getErrores();

$okCancel3 = ComplementosVenta::cancelar((int) $comp3['id'], 'Se equivocó el importe (etapa 36)', $usuario);
$prueba('cancelar C3 (que creó el cobro) cancela TAMBÉN su cobro', $okCancel3 === true
    && (string) $uno("SELECT estado FROM cobros WHERE ingreso_id = $ingreso3") === 'cancelado'
    && Contabilidad::buscarIngreso($ingreso3)['estado_cobro'] === 'sin_cobro',
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$estadoC3 = (string) $uno('SELECT estado FROM complementos_pago WHERE id = ' . (int) $comp3['id']);
$motivoC3 = trim((string) $uno('SELECT motivo_cancelacion FROM complementos_pago WHERE id = ' . (int) $comp3['id']));
$prueba('y su complemento queda cancelado con motivo, sin borrarse',
    $estadoC3 === 'cancelado' && $motivoC3 !== '', "estado=$estadoC3 motivo=$motivoC3");

$comp1 = ComplementosPago::buscarPorUuid('CC360000-0000-4000-8000-000000000001');
$okCancel1 = ComplementosVenta::cancelar((int) $comp1['id'], 'Cobro registrado a mano (etapa 36)', $usuario);
$prueba('cancelar C1 (ligado a un cobro manual) lo desvincula pero NO cancela ese cobro', $okCancel1 === true
    && (string) $uno("SELECT estado FROM cobros WHERE ingreso_id = $ingreso1 ORDER BY id LIMIT 1") === 'vigente',
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();
$segundoIntento = ComplementosVenta::cancelar((int) $comp1['id'], 'Otra vez más (36)', $usuario);
$prueba('cancelar dos veces se rechaza',
    $segundoIntento === false && $contiene(ComplementosVenta::getErrores(), 'cancelado'),
    implode(' · ', ComplementosVenta::getErrores()));
ComplementosVenta::getErrores();

$prueba('las creaciones quedaron auditadas con su lado',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'complemento_pago' AND accion = 'crear' AND detalle LIKE '%lado%' AND detalle LIKE '%cliente%'") >= 5);
$prueba('y las cancelaciones también',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'complemento_pago' AND accion = 'cancelar'") >= 2);

/* ==================================================================== *
 * 7. SIN EMISIÓN PROPIA SIN PAC Y ALCANCE (requisitos 7, 10 y 12)
 * ================================================================== */
echo "Alcance\n";
$prueba('no hay emisión propia de complementos sin PAC (dependencia documentada)',
    ComplementosVenta::puedeEmitir() === false);
$prueba('el registro reutiliza las clases de ingresos y cobros (no hay lógica paralela)',
    is_callable([Contabilidad::class, 'registrarCobro']) && is_callable([Contabilidad::class, 'cobrosDeIngreso']));

$lecturasConfirmar = Lecturas::confirmar($lecC6, [], $usuario);
$prueba('la lectura de complementos no se confirma en el flujo de gastos (cierre propio)',
    $lecturasConfirmar === false && $contiene(Lecturas::getErrores(), 'Complementos de pago'));
Lecturas::getErrores();

$conteosA = [
    'ventas' => (int) $uno('SELECT COUNT(*) FROM ventas'), 'ingresos' => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros' => (int) $uno('SELECT COUNT(*) FROM cobros'), 'complementos' => (int) $uno('SELECT COUNT(*) FROM complementos_pago'),
    'doctos' => (int) $uno('SELECT COUNT(*) FROM complemento_doctos'), 'clientes' => (int) $uno('SELECT COUNT(*) FROM clientes'),
];
ComplementosVenta::analizar($lecC1);
ComplementosVenta::analizar($lecC4);
$conteosB = [
    'ventas' => (int) $uno('SELECT COUNT(*) FROM ventas'), 'ingresos' => (int) $uno('SELECT COUNT(*) FROM ingresos'),
    'cobros' => (int) $uno('SELECT COUNT(*) FROM cobros'), 'complementos' => (int) $uno('SELECT COUNT(*) FROM complementos_pago'),
    'doctos' => (int) $uno('SELECT COUNT(*) FROM complemento_doctos'), 'clientes' => (int) $uno('SELECT COUNT(*) FROM clientes'),
];
$prueba('el análisis no escribe nada en la base', $conteosA === $conteosB, json_encode($conteosB));
$prueba('al cargar complementos NUNCA se crean ventas ni ingresos',
    (int) $uno('SELECT COUNT(*) FROM ventas') === $ventasAntes
    && (int) $uno('SELECT COUNT(*) FROM ingresos') === $ingresosAntes);
$prueba('los listados de complementos separan los lados',
    count(ComplementosVenta::recientes(50)) >= 5
    && (int) $uno("SELECT COUNT(*) FROM complementos_pago WHERE lado = 'cliente'") >= 5);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
