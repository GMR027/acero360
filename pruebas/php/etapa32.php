<?php
/**
 * pruebas/php/etapa32.php — Compras Inteligentes: leer el XML de una factura de compra
 * y decidir su destino (relacionar / compra nueva / revisar / cancelar).
 *
 * Cubre: XML válido con proveedor conocido, proveedor desconocido (propuesta de alta),
 * orden coincidente, orden inexistente, diferencias de importes, documento duplicado,
 * factura cuyo proveedor no coincide con la compra, tipo de comprobante no admitido
 * y que NADA se escribe solo con analizar (inventario y recepciones intactos).
 *
 * Los RFC y el «32» en los nombres evitan chocar con las demás etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa32.php
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
use App\CompraContable;
use App\ComprasIntel;
use App\Lecturas;
use App\OrdenesCompra as OC;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;

$limpiar = function () use ($db) {
    $ids = array_column($db->query("SELECT id FROM lecturas_documentales WHERE nombre_original LIKE 't32-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $db->query("UPDATE gastos SET lectura_id = NULL WHERE lectura_id IN ($in)");
        $db->query("DELETE FROM lecturas_documentales WHERE id IN ($in)");
    }
    // Gastos que creó esta etapa (CompraContable anota «referencia T32-» en el gasto):
    // sin esto, una ejecución interrumpida deja UUIDs huérfanos que bloquean la siguiente.
    $gastos = array_column($db->query("SELECT id FROM gastos WHERE observaciones LIKE '%referencia T32-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($gastos) {
        $in = implode(',', array_map('intval', $gastos));
        $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($in)");
        $db->query("DELETE FROM gastos WHERE id IN ($in)");
    }
    $db->query("DELETE oc FROM ordenes_compra oc WHERE oc.referencia LIKE 'T32-%'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T32 %'");
};
$limpiar();

/* ---------------------------------------------------------------- helpers */

/**
 * CFDI de compra (emisor = proveedor, receptor = un negocio propio) guardado como lectura
 * destino «compra». Devuelve el id de la lectura.
 */
$leer = function (string $archivo, string $xml) use (&$tmp): int {
    if (!isset($tmp)) {
        $tmp = sys_get_temp_dir() . '/t32_' . bin2hex(random_bytes(4));
        @mkdir($tmp);
    }
    file_put_contents("$tmp/$archivo", $xml);
    $id = Lecturas::preparar([
        ['name' => $archivo, 'tmp_name' => "$tmp/$archivo", 'size' => filesize("$tmp/$archivo"), 'error' => UPLOAD_ERR_OK],
    ], 'compra', (int) $_SESSION['id'], false);
    if ($id === null) {
        throw new RuntimeException('No se pudo preparar la lectura: ' . implode(' ', Lecturas::getErrores()));
    }
    return $id;
};

$rfcPropio = (string) $uno('SELECT rfc FROM contribuyentes LIMIT 1');
$rfcPropio = $rfcPropio !== '' ? $rfcPropio : 'ZZZ990101AAA';

$cfdi = static fn (string $uuid, string $emisorRfc, string $emisorNombre, string $conceptos, string $tipo = 'I', string $total = '1160.00', string $iva = '160.00') =>
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0"'
    . ' Serie="T" Folio="32" Fecha="2026-10-15T10:00:00" SubTotal="1000.00" Descuento="0.00" Total="' . $total . '"'
    . ' Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="' . $tipo . '">'
    . '<cfdi:Emisor Rfc="' . $emisorRfc . '" Nombre="' . $emisorNombre . '"/>'
    . '<cfdi:Receptor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 32"/>'
    . '<cfdi:Conceptos>' . $conceptos . '</cfdi:Conceptos>'
    . '<cfdi:Impuestos TotalImpuestosTrasladados="' . $iva . '"><cfdi:Traslados>'
    . '<cfdi:Traslado Impuesto="002" Importe="' . $iva . '"/></cfdi:Traslados></cfdi:Impuestos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';

/* ---------------------------------------------------------------- datos */

$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T32 Ferretería del Acero', 'ZZX320101AA1', 'Material', 1)");
$proveedorId = (int) $db->insert_id;

$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T32 Proveedor Ajeno', 'ZZX320201AA2', 'Material', 1)");
$proveedorAjeno = (int) $db->insert_id;

/** Crea una compra de prueba con dos partidas (una gravada). */
$crearCompra = function (string $ref, int $prov, array $extra = []) use ($db, $usuario, $hoy): int {
    $lineas = [
        ['descripcion' => 'Tornillo de pruba 32', 'cantidad' => '2', 'costo' => '100.00', 'unidad' => 'pza', 'controla' => 0, 'aplica_iva' => 1, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0],
        ['descripcion' => 'Pintura de pruba 32', 'cantidad' => '1', 'costo' => '500.00', 'unidad' => 'pza', 'controla' => 0, 'aplica_iva' => 0, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0],
    ];
    $ok = OC::crear([
        'tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => $ref,
        'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => '16',
    ] + $extra, $lineas, $usuario);
    if (!$ok) {
        throw new RuntimeException('No se creó la compra de prueba: ' . implode(' ', OC::getErrores()));
    }
    return (int) OC::$ultimoId;
};

echo "Análisis de un XML válido\n";
$conceptosOk = '<cfdi:Concepto NoIdentificacion="T32-1" Cantidad="2" Descripcion="Tornillo de pruba 32" ValorUnitario="100.00" Importe="200.00"/>'
    . '<cfdi:Concepto Cantidad="1" Descripcion="Pintura de pruba 32" ValorUnitario="500.00" Importe="500.00"/>'
    . '<cfdi:Concepto Cantidad="1" Descripcion="Flete de pruba 32" ValorUnitario="300.00" Importe="300.00"/>';
$uuid1 = 'A1B2C3D4-3200-4000-8000-111122223333';
$lectura1 = $leer('t32-factura-1.xml', $cfdi($uuid1, 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosOk));

$orden1 = $crearCompra('T32-A', $proveedorId);

$an = ComprasIntel::analizar($lectura1);
$prueba('analizar devuelve el análisis de la lectura', $an !== null);
$prueba('lee UUID, emisor, total e IVA del XML',
    $an['datos']['uuid'] === $uuid1
    && $an['datos']['emisor_rfc'] === 'ZZX320101AA1'
    && $an['datos']['total'] === '1160.00'
    && $an['datos']['iva'] === '160.00'
    && count($an['datos']['conceptos']) === 3, json_encode($an['datos'] ?? []));
$prueba('encuentra el proveedor por RFC exacto', $an['proveedor'] !== null && (int) $an['proveedor']['id'] === $proveedorId);
$prueba('clasifica como posible compra de materiales', $an['clasificacion']['clave'] === 'material', $an['clasificacion']['motivo']);
$prueba('la factura aún no existe en ninguna operación', $an['duplicado'] === null);
$prueba('lista las compras del proveedor con comparación', $an['ordenes'] !== [] && $an['ordenes'][0]['comparacion']['coincidencias'] >= 2);

echo "Comparación partida vs concepto (coincidencias y diferencias)\n";
$comp = $an['ordenes'][0]['comparacion'];
$prueba('2 partidas de la compra encuentran su concepto (SKU y nombre)', $comp['coincidencias'] === 2, 'coincidencias=' . $comp['coincidencias']);
$prueba('el concepto «Flete» queda como factura de más', count($comp['sin_partida']) === 1 && $comp['sin_partida'][0]['descripcion'] === 'Flete de pruba 32');
$sinDiferencias = array_filter($comp['partidas'], static fn ($f) => $f['dif_importe'] !== null && abs($f['dif_importe']) > 0.005);
$prueba('sin diferencias de importe cuando los precios coinciden', $sinDiferencias === [], json_encode(array_column($comp['partidas'], 'dif_importe')));
$prueba('totales: compra $700 + IVA $32 (solo partida gravada) vs factura $1160', abs($comp['totales']['compra'] - 732.0) < 0.005 && abs($comp['totales']['iva_compra'] - 32.0) < 0.005 && abs($comp['totales']['factura'] - 1160.0) < 0.005, json_encode($comp['totales']));

// Diferencias de importes: el XML trae otros precios.
$uuid2 = 'A1B2C3D4-3200-4000-8000-111122224444';
$conceptosCaros = '<cfdi:Concepto NoIdentificacion="T32-1" Cantidad="2" Descripcion="Tornillo de pruba 32" ValorUnitario="150.00" Importe="300.00"/>'
    . '<cfdi:Concepto Cantidad="1" Descripcion="Pintura de pruba 32" ValorUnitario="500.00" Importe="500.00"/>'
    . '<cfdi:Concepto Cantidad="1" Descripcion="Flete de pruba 32" ValorUnitario="300.00" Importe="300.00"/>';
$lectura2 = $leer('t32-factura-2.xml', $cfdi($uuid2, 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosCaros, 'I', '1276.00', '176.00'));
$an2 = ComprasIntel::analizar($lectura2);
$comp2 = $an2['ordenes'][0]['comparacion'];
$filasConDiff = array_filter($comp2['partidas'], static fn ($f) => $f['dif_importe'] !== null && abs($f['dif_importe']) > 0.005);
$prueba('marca la diferencia de precio en el tornillo (+$100 por unidad)',
    count($filasConDiff) === 1 && abs($filasConDiff[0]['dif_importe'] - 100.0) < 0.005,
    json_encode(array_column($comp2['partidas'], 'dif_importe')));
$prueba('la diferencia total refleja factura ($1276) − compra ($732)', abs($comp2['totales']['diferencia'] - 544.0) < 0.005, (string) $comp2['totales']['diferencia']);

echo "Proveedor desconocido\n";
$uuid3 = 'A1B2C3D4-3200-4000-8000-111122225555';
$lectura3 = $leer('t32-factura-3.xml', $cfdi($uuid3, 'ZZX320999AA9', 'T32 Gasolinera Surtida', $conceptosOk));
$an3 = ComprasIntel::analizar($lectura3);
$prueba('sin proveedor: no propone compras candidatas', $an3['proveedor'] === null && $an3['ordenes'] === []);
$prueba('propone el alta con nombre y RFC del XML',
    $an3['propuesta']['nombre'] === 'T32 Gasolinera Surtida' && $an3['propuesta']['rfc'] === 'ZZX320999AA9', json_encode($an3['propuesta']));
$prueba('el alta NO existe hasta que se confirma', (int) $uno("SELECT COUNT(*) FROM proveedores WHERE rfc = 'ZZX320999AA9'") === 0);

$nuevoId = ComprasIntel::registrarProveedor($lectura3, [
    'nombre' => 'T32 Gasolinera Surtida', 'rfc' => 'ZZX320999AA9', 'tipo_proveedor' => 'Servicio',
], $usuario);
$prueba('registrar_proveedor (confirmado) crea el proveedor', $nuevoId !== null && (int) $uno("SELECT COUNT(*) FROM proveedores WHERE rfc = 'ZZX320999AA9'") === 1);
$prueba('quedó auditado el alta desde Compras Inteligentes',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'proveedor' AND entidad_id = " . (int) $nuevoId . " AND accion = 'crear'") === 1);
$repetido = ComprasIntel::registrarProveedor($lectura3, ['nombre' => 'Otro nombre', 'rfc' => 'ZZX320999AA9'], $usuario);
$prueba('con el mismo RFC reutiliza el proveedor existente (no duplica)', $repetido === $nuevoId && (int) $uno("SELECT COUNT(*) FROM proveedores WHERE rfc = 'ZZX320999AA9'") === 1);
ComprasIntel::getErrores();

echo "Relacionar: orden coincidente\n";
$recepcionesAntes = (int) $uno('SELECT COUNT(*) FROM recepciones');
$movimientosAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_inventario');
$gastosAntes = (int) $uno('SELECT COUNT(*) FROM gastos');

$ok = ComprasIntel::relacionar($lectura1, $orden1, $usuario);
$prueba('relacionar devuelve true', $ok, implode(' ', ComprasIntel::getErrores()));
$orden = OC::buscarID($orden1);
$prueba('la compra pasa a «Con CFDI»', $orden['estado_cfdi'] === 'con_cfdi');
$prueba('la compra queda enlazada a UN gasto', !empty($orden['gasto_id']));
$prueba('se creó exactamente UN gasto para la compra', (int) $uno('SELECT COUNT(*) FROM gastos') === $gastosAntes + 1);
$gastoId = (int) $orden['gasto_id'];
$prueba('el gasto guarda el UUID del XML', strtoupper((string) $uno("SELECT uuid_fiscal FROM gastos WHERE id = $gastoId")) === $uuid1);
$prueba('la lectura queda confirmada contra la orden',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lectura1") === 'confirmada'
    && (string) $uno("SELECT entidad FROM lecturas_documentales WHERE id = $lectura1") === 'orden_compra'
    && (int) $uno("SELECT entidad_id FROM lecturas_documentales WHERE id = $lectura1") === $orden1);
$prueba('el XML quedó adjunto a la operación',
    (int) $uno("SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = $gastoId AND activo = 1 AND uuid_fiscal = '$uuid1'") === 1);
$prueba('NO se tocó inventario ni recepciones (solo lectura del documento)',
    (int) $uno('SELECT COUNT(*) FROM recepciones') === $recepcionesAntes && (int) $uno('SELECT COUNT(*) FROM movimientos_inventario') === $movimientosAntes);
$prueba('queda en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE accion = 'factura_inteligente' AND entidad_id = $orden1") === 1);

echo "XML duplicado: se reutiliza el vínculo, no se duplica el importe\n";
$lectura1b = $leer('t32-factura-1b.xml', $cfdi($uuid1, 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosOk));
$anDup = ComprasIntel::analizar($lectura1b);
$prueba('analizar señala que la factura ya existe', $anDup['duplicado'] !== null
    && $anDup['duplicado']['tipo'] === 'compra'
    && (int) $anDup['duplicado']['orden']['id'] === $orden1, json_encode($anDup['duplicado']));

$gastosAntes2 = (int) $uno('SELECT COUNT(*) FROM gastos');
$ok2 = ComprasIntel::relacionar($lectura1b, $orden1, $usuario);
$prueba('a la MISMA compra se reutiliza sin crear otro gasto', $ok2 && (int) $uno('SELECT COUNT(*) FROM gastos') === $gastosAntes2, implode(' ', ComprasIntel::getErrores()));
$prueba('la segunda lectura también queda confirmada',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lectura1b") === 'confirmada');

$orden2 = $crearCompra('T32-B', $proveedorId);
// Otra lectura (mismo UUID) contra una compra distinta: debe bloquearse por duplicado,
// no por «ya procesada», y quedar pendiente para poder decidir otra vez.
$lectura1c = $leer('t32-factura-1c.xml', $cfdi($uuid1, 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosOk));
$ok3 = ComprasIntel::relacionar($lectura1c, $orden2, $usuario);
$prueba('a OTRA compra con el mismo UUID se bloquea (evita duplicar importe)', !$ok3 && str_contains(implode(' ', ComprasIntel::getErrores()), 'ya está registrado'));
$prueba('la lectura bloqueada sigue pendiente (se puede decidir otra vez)',
    (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lectura1c") === 'pendiente');
ComprasIntel::getErrores();

echo "Orden inexistente y proveedor que no coincide\n";
$ok4 = ComprasIntel::relacionar($lectura3, 999999, $usuario);
$prueba('orden inexistente → se rechaza', !$ok4 && ComprasIntel::getErrores() !== []);
$lecturaAjena = $leer('t32-factura-ajena.xml', $cfdi('A1B2C3D4-3200-4000-8000-111122226666', 'ZZX320201AA2', 'T32 Proveedor Ajeno', $conceptosOk));
$ok5 = ComprasIntel::relacionar($lecturaAjena, $orden2, $usuario);
$prueba('factura de OTRO proveedor no entra a la compra de este', !$ok5 && str_contains(implode(' ', ComprasIntel::getErrores()), 'es del proveedor con RFC'));
ComprasIntel::getErrores();

echo "Tipo de comprobante no admitido (p. ej. complemento de pago)\n";
$lecturaP = $leer('t32-pago.xml', $cfdi('A1B2C3D4-3200-4000-8000-111122227777', 'ZZX320101AA1', 'T32 Ferretería del Acero', '', 'P', '160.00', '0.00'));
$anP = ComprasIntel::analizar($lecturaP);
$prueba('el tipo «P» se clasifica como no admitido', $anP['clasificacion']['clave'] === 'indeterminado');
$okP = ComprasIntel::relacionar($lecturaP, $orden2, $usuario);
$prueba('un CFDI de pago no se relaciona con una compra', !$okP && str_contains(implode(' ', ComprasIntel::getErrores()), 'no es una factura de compra'));
ComprasIntel::getErrores();

echo "Cancelar (descartar) sin registrar nada\n";
$lectura4 = $leer('t32-factura-4.xml', $cfdi('A1B2C3D4-3200-4000-8000-111122228888', 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosOk));
$gastosAntes3 = (int) $uno('SELECT COUNT(*) FROM gastos');
$prueba('descartar la lectura sin escribir nada', Lecturas::descartar($lectura4, $usuario)
    && (int) $uno('SELECT COUNT(*) FROM gastos') === $gastosAntes3
    && (string) $uno("SELECT estado FROM lecturas_documentales WHERE id = $lectura4") === 'descartada');
$prueba('una lectura descartada ya no se relaciona', !ComprasIntel::relacionar($lectura4, $orden1, $usuario));
ComprasIntel::getErrores();

echo "Analizar solo lee (nada se escribe)\n";
$lectura5 = $leer('t32-factura-5.xml', $cfdi('A1B2C3D4-3200-4000-8000-111122229999', 'ZZX320101AA1', 'T32 Ferretería del Acero', $conceptosOk));
$antes = [(int) $uno('SELECT COUNT(*) FROM gastos'), (int) $uno('SELECT COUNT(*) FROM recepciones'), (int) $uno('SELECT COUNT(*) FROM ordenes_compra')];
ComprasIntel::analizar($lectura5);
ComprasIntel::propuestaOrden($lectura5);
$despues = [(int) $uno('SELECT COUNT(*) FROM gastos'), (int) $uno('SELECT COUNT(*) FROM recepciones'), (int) $uno('SELECT COUNT(*) FROM ordenes_compra')];
$prueba('analizar y propuestaOrden no crean gastos, recepciones ni compras', $antes === $despues);

echo "Precarga de compra nueva desde la lectura\n";
$propuesta = ComprasIntel::propuestaOrden($lectura5);
$prueba('propuestaOrden devuelve encabezado y líneas desde el XML', $propuesta !== null
    && (int) $propuesta['orden']['proveedor_id'] === $proveedorId
    && count($propuesta['lineas']) === 3
    && $propuesta['orden']['iva'] === '160.00', json_encode($propuesta));

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
