<?php
/**
 * pruebas/php/etapa40.php — FASE 9 (paso 3): clientes con varios RFC.
 *
 * Un cliente puede tener varios RFC de facturación (p. ej. su persona física y
 * su empresa): clientes.rfc es el ACTIVO con el que se factura y clientes_rfc
 * registra TODOS, cada uno con su razón social y datos fiscales.
 *
 * Cubre: backfill de la migración 037; agregar/quitar RFC con validaciones;
 * resolución de un cliente por su RFC SECUNDARIO (venta inteligente, alta rápida
 * y facturación); elegir RFC al facturar (cambia el activo y sincroniza el
 * registro); y la detección de «el mismo cliente con otro RFC» por nombre/correo.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa40.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Clientes;
use App\ClientesRfc;
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
$_SESSION['id'] = $usuario;
$contrib = \App\Contabilidad::contribuyentePrincipal();
$rfcPropio = trim((string) $uno('SELECT COALESCE(rfc, "") FROM contribuyentes WHERE id = ' . $contrib)) ?: 'DEMO000101A01';
$raiz = dirname(__DIR__, 2);

/* -------------------------------------------------------------------- *
 * ENTORNO
 * ------------------------------------------------------------------ */
$tmp = sys_get_temp_dir() . '/t40_' . bin2hex(random_bytes(4));
@mkdir($tmp);

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
    $db->query("DELETE FROM lecturas_documentales WHERE nombre_original LIKE 't40-%'");
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T40 %'");
    $misVentas = [];
};
$limpiar();
$db->query('UPDATE productos SET stock = 500 WHERE id = 37');

/* ==================================================================== *
 * 1. BACKFILL DE LA MIGRACIÓN 037
 * ================================================================== */
echo "Migración 037: el RFC de cada ficha queda registrado\n";
$prueba('la tabla clientes_rfc existe y queda coherente con el RFC activo de cada ficha registrada',
    (int) $uno('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "clientes_rfc"') === 1
    && (int) $uno('SELECT COUNT(*) FROM clientes_rfc r JOIN clientes c ON c.id = r.cliente_id
                   WHERE r.es_principal = 1
                     AND UPPER(REPLACE(REPLACE(TRIM(COALESCE(c.rfc, "")), " ", ""), "-", "")) <> r.rfc') === 0);
$unCliente = $db->query('SELECT id, rfc FROM clientes WHERE COALESCE(TRIM(rfc), "") <> "" LIMIT 1')->fetch_assoc();
$prueba('cada RFC existente quedó como principal de su ficha',
    (int) $uno('SELECT COUNT(*) FROM clientes_rfc WHERE cliente_id = ' . (int) $unCliente['id']
        . ' AND es_principal = 1 AND rfc = UPPER(REPLACE(REPLACE(TRIM("' . $unCliente['rfc'] . '"), " ", ""), "-", ""))') === 1);

$cliente40 = new Clientes([
    'nombre' => 'T40 Contacto', 'nombre_empresa' => 'T40 Cliente Dos RFC SA',
    'rfc' => 'QQDD010101A40', 'codigo_postal' => '23407', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03',
]);
$prueba('el cliente de prueba se crea (con su RFC activo)', $cliente40->validar() === [] && $cliente40->crear());
$id40 = (int) $cliente40->id;
$prueba('su RFC activo quedó registrado en la tabla de RFCs',
    (int) $uno("SELECT COUNT(*) FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQDD010101A40' AND es_principal = 1") === 1);

/* ==================================================================== *
 * 2. AGREGAR Y QUITAR RFC
 * ================================================================== */
echo "Administrar los RFC de la ficha\n";
$agregado = ClientesRfc::agregar($id40, [
    'rfc' => 'qqee 010101-a41', 'razon_social' => 'T40 Empresa del mismo cliente',
    'codigo_postal' => '23088', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03',
], $usuario);
$prueba('se agrega un segundo RFC (normalizado, con sus datos fiscales)',
    $agregado['ok'] === true && (int) ($agregado['rfc_id'] ?? 0) > 0, json_encode($agregado));
$prueba('queda registrado como SECUNDARIO (el activo no cambió)',
    (int) $uno("SELECT COUNT(*) FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQEE010101A41' AND es_principal = 0") === 1
    && (string) $uno("SELECT rfc FROM clientes WHERE id = $id40") === 'QQDD010101A40');
$prueba('y el alta del RFC quedó auditada',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'cliente_rfc' AND accion = 'agregar' AND entidad_id = " . (int) $agregado['rfc_id']) === 1);

$lista = ClientesRfc::de($id40);
$prueba('la ficha lista sus RFC con el activo primero', count($lista) === 2 && (int) $lista[0]['es_principal'] === 1
    && $lista[0]['rfc'] === 'QQDD010101A40' && $lista[1]['rfc'] === 'QQEE010101A41');

$repetido = ClientesRfc::agregar($id40, ['rfc' => 'QQEE010101A41'], $usuario);
$prueba('agregar dos veces el mismo RFC a la misma ficha se rechaza',
    $repetido['ok'] === false && (bool) array_filter($repetido['errores'], static fn ($e) => str_contains($e, 'ya está registrado a este cliente')));

$deOtro = ClientesRfc::agregar($id40, ['rfc' => (string) $unCliente['rfc']], $usuario);
$prueba('agregar un RFC que pertenece a OTRO cliente se rechaza señalando al dueño',
    $deOtro['ok'] === false && (bool) array_filter($deOtro['errores'], static fn ($e) => str_contains($e, 'ya pertenece al cliente')));

$mal = ClientesRfc::agregar($id40, ['rfc' => 'ABC123'], $usuario);
$prueba('un RFC mal formado se rechaza', $mal['ok'] === false);

/* ==================================================================== *
 * 3. DETECCIÓN: el RFC SECUNDARIO también identifica al cliente
 * ================================================================== */
echo "Un RFC secundario identifica al cliente en todas partes\n";
$porSecundario = VentasFiscal::buscarClientePorRfc('QQEE010101A41');
$prueba('la búsqueda por RFC resuelve al cliente por su RFC SECUNDARIO',
    $porSecundario !== null && (int) $porSecundario['id'] === $id40
    && (string) $porSecundario['codigo_postal'] === '23088' && (string) $porSecundario['regimen_fiscal'] === '612',
    json_encode($porSecundario));

// Venta inteligente: una factura con el RFC secundario encuentra al cliente.
$xmlSecundario = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
    . ' Version="4.0" Serie="SS" Folio="40" Fecha="2026-10-08T08:45:48" SubTotal="1000.00" Total="1160.00"'
    . ' Moneda="MXN" TipoDeComprobante="I" LugarExpedicion="23088" Exportacion="01" FormaPago="03" MetodoPago="PUE" CondicionesDePago="Contado">'
    . '<cfdi:Emisor Rfc="' . $rfcPropio . '" Nombre="Negocio de la etapa 40" RegimenFiscal="612"/>'
    . '<cfdi:Receptor Rfc="QQEE010101A41" Nombre="T40 Empresa del mismo cliente" DomicilioFiscalReceptor="23088"'
    . ' RegimenFiscalReceptor="612" UsoCFDI="G03"/>'
    . '<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="30102304" Cantidad="2.000000" ClaveUnidad="H87"'
    . ' Descripcion="Canasta" ValorUnitario="500.000000" Importe="1000.000000" ObjetoImp="02"/></cfdi:Conceptos>'
    . '<cfdi:Impuestos TotalImpuestosTrasladados="160.00"><cfdi:Traslados><cfdi:Traslado Base="1000.00" Impuesto="002"'
    . ' TipoFactor="Tasa" TasaOCuota="0.160000" Importe="160.00"/></cfdi:Traslados></cfdi:Impuestos>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="FA400000-0000-4000-8000-000000000001"/></cfdi:Complemento></cfdi:Comprobante>';
file_put_contents("$tmp/t40-factura.xml", $xmlSecundario);
$lectura40 = Lecturas::preparar([
    ['name' => 't40-factura.xml', 'tmp_name' => "$tmp/t40-factura.xml", 'size' => (int) filesize("$tmp/t40-factura.xml"), 'error' => UPLOAD_ERR_OK],
], 'venta', $usuario, false);
$propuesta = Lecturas::propuesta((int) $lectura40, 'venta');
$prueba('la VENTA INTELIGENTE reconoce al cliente por su RFC secundario',
    $propuesta !== null && (int) ($propuesta['cliente']['id'] ?? 0) === $id40, json_encode($propuesta['cliente'] ?? null));

$altaSecundario = Lecturas::altaClienteDesdeLectura((int) $lectura40, ['rfc' => 'QQEE010101A41', 'nombre' => 'T40 Contacto'], $usuario);
$prueba('el ALTA RÁPIDA tampoco duplica: reutiliza la ficha (ya_existia)',
    $altaSecundario['ok'] === true && !empty($altaSecundario['ya_existia']) && (int) $altaSecundario['cliente_id'] === $id40
    && (int) $uno('SELECT COUNT(*) FROM clientes WHERE nombre_empresa LIKE "T40 %"') === 1);

/* ==================================================================== *
 * 4. FACTURAR CON UN RFC SECUNDARIO (cambia el activo y sincroniza)
 * ================================================================== */
echo "Elegir el RFC al facturar\n";
$okVenta = Ventas::crear(
    ['empresa_id' => 2, 'cliente_id' => $id40, 'fecha' => '2026-10-10', 'aplica_iva' => 1,
        'descuento_pct' => 0, 'condiciones_compra' => ''],
    [37 => ['cantidad' => 1, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario
);
$prueba('la venta de facturación se crea', $okVenta === true, implode(' · ', Ventas::getErrores()));
$misVentas[] = (int) Ventas::$ultimoId;
$venta40 = (int) Ventas::$ultimoId;

$okReceptor = VentasFiscal::completarReceptor($venta40, [
    'rfc' => 'QQEE010101A41', 'codigo_postal' => '23088', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03',
], $usuario);
$prueba('declarar el RFC SECUNDARIO al facturar se acepta', $okReceptor === true, implode(' · ', VentasFiscal::getErrores()));
$prueba('y queda como RFC ACTIVO de la ficha (los dos conservados)',
    (string) $uno("SELECT rfc FROM clientes WHERE id = $id40") === 'QQEE010101A41'
    && (int) $uno("SELECT COUNT(*) FROM clientes_rfc WHERE cliente_id = $id40") === 2
    && (int) $uno("SELECT es_principal FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQEE010101A41'") === 1
    && (int) $uno("SELECT es_principal FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQDD010101A40'") === 0);
VentasFiscal::guardarPago($venta40, 'PUE', '03', $usuario);
VentasFiscal::getErrores();
$revision = VentasFiscal::validar($venta40);
$prueba('la validación aprueba con los datos del RFC elegido',
    $revision !== null && $revision['puede_facturar'] === true, implode(' · ', $revision['errores'] ?? []));

$volver = VentasFiscal::completarReceptor($venta40, [
    'rfc' => 'QQDD010101A40', 'codigo_postal' => '23407', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03',
], $usuario);
$prueba('facturar después con el RFC original lo vuelve a poner ACTIVO (ida y vuelta)',
    $volver === true && (string) $uno("SELECT rfc FROM clientes WHERE id = $id40") === 'QQDD010101A40'
    && (int) $uno("SELECT es_principal FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQDD010101A40'") === 1);

$quitarPrincipal = ClientesRfc::quitar((int) $uno("SELECT id FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQDD010101A40'"), $usuario);
$prueba('el RFC ACTIVO no se puede quitar de la ficha',
    $quitarPrincipal === false && (bool) array_filter(ClientesRfc::getErrores(), static fn ($e) => str_contains($e, 'es el activo')));
$quitarSecundario = ClientesRfc::quitar((int) $uno("SELECT id FROM clientes_rfc WHERE cliente_id = $id40 AND rfc = 'QQEE010101A41'"), $usuario);
$prueba('un RFC secundario sí se puede quitar (queda auditado)',
    $quitarSecundario === true
    && (int) $uno("SELECT COUNT(*) FROM clientes_rfc WHERE cliente_id = $id40") === 1
    && (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'cliente_rfc' AND accion = 'cancelar'") >= 1);

/* ==================================================================== *
 * 5. DETECCIÓN DE «EL MISMO CLIENTE CON OTRO RFC» (por nombre o correo)
 * ================================================================== */
echo "Detección por nombre o correo\n";
$dups = ClientesRfc::posiblesDuplicados('T40 Otro Contacto', 'T40 Cliente Dos RFC SA', '', 0);
$prueba('una ficha nueva con el mismo NOMBRE se detecta como posible duplicado',
    $dups !== [] && (int) $dups[0]['id'] === $id40 && $dups[0]['por'] === 'nombre', json_encode($dups));
$correo40 = $db->query("SELECT email FROM clientes WHERE id = $id40")->fetch_row()[0] ?? '';
if ((string) $correo40 === '') {
    $db->query("UPDATE clientes SET email = 't40-correo@test.local' WHERE id = $id40");
    $correo40 = 't40-correo@test.local';
}
$dupsCorreo = ClientesRfc::posiblesDuplicados('T40 Otro Contacto', 'T40 Otra Empresa', (string) $correo40, 0);
$prueba('y con el mismo CORREO también se detecta', $dupsCorreo !== [] && (int) $dupsCorreo[0]['id'] === $id40 && $dupsCorreo[0]['por'] === 'correo');

/* ==================================================================== *
 * 6. Vistas, rutas y migración reversible
 * ================================================================== */
echo "Vistas, rutas y reversiones\n";
$editar40 = file_get_contents($raiz . '/views/admin/clientes/editar.php');
$prueba('la ficha del cliente administra sus RFCs (tabla + alta + quitar)',
    str_contains($editar40, 'rfcs-facturacion') && str_contains($editar40, 'rfc-accion'));
$prueba('la ruta de acciones está registrada y el modal de facturación permite ELEGIR RFC',
    str_contains(file_get_contents($raiz . '/index.php'), "'/admin/clientes/rfc-accion'")
    && str_contains(file_get_contents($raiz . '/templates/facturacionVenta.php'), 'data-multirfc'));
$prueba('el alta de clientes avisa cuando parece el mismo cliente con otro RFC',
    str_contains(file_get_contents($raiz . '/views/admin/clientes/crear.php'), 'posiblesDuplicados'));
$prueba('la reversión de la 037 existe y limpia su registro',
    str_contains(file_get_contents($raiz . '/mysql/migraciones/037_clientes_rfc_reversion.sql'), 'DROP TABLE IF EXISTS clientes_rfc')
    && str_contains(file_get_contents($raiz . '/mysql/migraciones/037_clientes_rfc_reversion.sql'), "DELETE FROM schema_migraciones WHERE nombre = '037_clientes_rfc.sql'"));

$limpiar();
exec('rm -rf ' . escapeshellarg($tmp));
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
