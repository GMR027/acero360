<?php
/**
 * pruebas/php/etapa18.php — Compras y Proveedores unificados: semiterminados, insumos, materiales,
 * recepciones, pagos parciales, enlace único con el gasto y facturas (CFDI).
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa18.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
$almacen = sys_get_temp_dir() . '/docs-prueba-' . bin2hex(random_bytes(4));
putenv("DOCUMENTOS_PATH=$almacen");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Archivos, CompraContable, Contabilidad, Gastos, Inventario, Materiales, OrdenesCompra, PagosCompra, PorTerminar};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');
$token = fn () => bin2hex(random_bytes(16));
$errores = fn () => implode('|', array_merge(OrdenesCompra::getErrores(), PagosCompra::getErrores(), CompraContable::getErrores(), Materiales::getErrores(), PorTerminar::getErrores(), Archivos::getErrores()));

// --- Limpieza: solo lo que esta prueba creó (referencia T18-…, materiales «T18 …», entidad/negocio T18). Corre al inicio y al final
//     para no dejar rastro (otras pruebas cuentan negocios, gastos y existencias).
$limpiar = function () use ($db) {
    $viejas = array_column($db->query("SELECT id FROM ordenes_compra WHERE referencia LIKE 'T18-%'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($viejas) {
        $ids = implode(',', array_map('intval', $viejas));
        $gastos = array_filter(array_column($db->query("SELECT gasto_id FROM ordenes_compra WHERE id IN ($ids)")->fetch_all(MYSQLI_ASSOC), 'gasto_id'));
        $db->query("DELETE FROM pagos_compra WHERE orden_id IN ($ids)");
        $db->query("DELETE FROM movimientos_por_terminar WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN ($ids))");
        $db->query("DELETE FROM movimientos_material WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN ($ids))");
        $db->query("DELETE FROM movimientos_inventario WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN ($ids))");
        $db->query("DELETE FROM recepciones WHERE orden_id IN ($ids)");
        $db->query("DELETE FROM ordenes_compra WHERE id IN ($ids)");
        if ($gastos) {
            $g = implode(',', array_map('intval', $gastos));
            $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN ($g)");
            $db->query("DELETE FROM gasto_reparto WHERE gasto_id IN ($g)");
            $db->query("DELETE FROM gastos WHERE id IN ($g)");
        }
    }
    $db->query("DELETE FROM periodos_contables WHERE contribuyente_id IN (SELECT id FROM contribuyentes WHERE rfc = 'T18XAXX010101')");
    $db->query("DELETE FROM empresas WHERE nombre = 'T18 Empresa 2'");
    $db->query("DELETE FROM contribuyentes WHERE rfc = 'T18XAXX010101'");
    $db->query("DELETE FROM materiales WHERE nombre LIKE 'T18 %'");
    $db->query('UPDATE productos SET stock = 10, stock_por_terminar = 0 WHERE id IN (36, 37)');
};
$limpiar();

$proveedor = 10;   // Agustín
$stock = fn (int $id) => (int) $uno("SELECT stock FROM productos WHERE id = $id");
$porTerminar = fn (int $id) => (int) $uno("SELECT stock_por_terminar FROM productos WHERE id = $id");
$gastosDe = fn (int $ordenId) => (int) $uno("SELECT COUNT(*) FROM gastos g JOIN ordenes_compra o ON o.gasto_id = g.id WHERE o.id = $ordenId AND g.estado = 'vigente'");
$totalGastos = fn () => (int) $uno('SELECT COUNT(*) FROM gastos');
$nombreCatalogo = (string) $uno('SELECT nombre FROM productos WHERE id = 36');

$carpeta = sys_get_temp_dir() . '/e18-' . bin2hex(random_bytes(4));
mkdir($carpeta);
$archivo = function (string $nombre, string $contenido) use ($carpeta): array {
    $ruta = $carpeta . '/' . bin2hex(random_bytes(3));
    file_put_contents($ruta, $contenido);
    return ['name' => $nombre, 'tmp_name' => $ruta, 'size' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
};
$rfcContribuyente = Contabilidad::contribuyente(Contabilidad::contribuyentePrincipal())['rfc'] ?: 'XAXX010101000';
$cfdi = fn (string $uuid, string $total) => '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="A" Folio="77" Fecha="' . $hoy . 'T10:00:00" SubTotal="' . $total . '" Total="' . $total . '" Moneda="MXN" TipoDeComprobante="I">'
    . '<cfdi:Emisor Rfc="AAA010101AAA" Nombre="Proveedor"/><cfdi:Receptor Rfc="' . $rfcContribuyente . '" Nombre="Receptor"/>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$uuidNuevo = fn () => strtoupper(sprintf('%08s-%04s-%04s-%04s-%012s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6))));
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

echo "1-2. Semiterminado de Agustín ligado al catálogo, con recepción parcial\n";
$ok = OrdenesCompra::crear(
    ['tipo' => 'semiterminado', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-A'],
    [['producto_id' => 36, 'cantidad' => '10', 'costo' => '100.00', 'descripcion' => 'Canastilla sin pintar']],
    $usuario
);
$A = OrdenesCompra::$ultimoId;
$prueba('crear compra de semiterminado', $ok && $A > 0, $errores());
$partidaA = OrdenesCompra::partidas($A)[0];
$prueba('conserva el nombre del proveedor y no cambia el del catálogo', $partidaA['descripcion'] === 'Canastilla sin pintar' && (string) $uno('SELECT nombre FROM productos WHERE id = 36') === $nombreCatalogo);
$prueba('el pedido no incrementa existencias', $stock(36) === 10 && $porTerminar(36) === 0);

$r = OrdenesCompra::recibir($A, $hoy, [(int) $partidaA['id'] => ['cantidad' => 4, 'costo' => '100.00']], $t = $token(), false, '', $usuario);
$prueba('recepción parcial de 4', $r, $errores());
$prueba('solo lo recibido va a «por terminar»', $porTerminar(36) === 4);
$prueba('NO aumenta la existencia vendible', $stock(36) === 10);
$p = OrdenesCompra::partidas($A)[0];
$prueba('comprado 10 · recibido 4 · pendiente 6', $p['solicitada'] === 10 && $p['recibida'] === 4 && $p['pendiente'] === 6);
$prueba('estado parcial', OrdenesCompra::buscarID($A)['estado'] === 'parcial');
$rs = OrdenesCompra::resumen($A);
$prueba('guardar y recibir NO cambian pago ni factura (pagado 0, saldo = total, CFDI pendiente)', $rs['pagado'] == 0.0 && $rs['saldo'] == $rs['total'] && $rs['estado_pago'] === 'pendiente' && $rs['estado_cfdi'] === 'pendiente_cfdi');

echo "10. Recepción repetida no duplica\n";
$r = OrdenesCompra::recibir($A, $hoy, [(int) $partidaA['id'] => ['cantidad' => 4, 'costo' => '100.00']], $t, false, '', $usuario);
$prueba('el mismo formulario no suma otra vez', !$r && $porTerminar(36) === 4, $errores());
OrdenesCompra::getErrores();

echo "7. Excedentes y piezas terminadas\n";
$r = OrdenesCompra::recibir($A, $hoy, [(int) $partidaA['id'] => ['cantidad' => 7, 'costo' => '100.00']], $token(), false, '', $usuario);
$prueba('rechaza recibir más de lo pendiente sin confirmación', !$r && $porTerminar(36) === 4);
OrdenesCompra::getErrores();
$r = OrdenesCompra::recibir($A, $hoy, [(int) $partidaA['id'] => ['cantidad' => 7, 'costo' => '100.00']], $token(), false, '', $usuario, true);
$prueba('con confirmación explícita lo acepta y lo audita', $r && $porTerminar(36) === 11
    && (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad='orden_compra' AND entidad_id=$A AND accion='excedente'") === 1, $errores());

$antesVendible = $stock(36);
$suma = $stock(36) + $porTerminar(36);
$prueba('terminar más de lo que hay por terminar se rechaza', !PorTerminar::terminar(36, 99, $usuario) && $porTerminar(36) === 11);
PorTerminar::getErrores();
$prueba('terminar 3 piezas', PorTerminar::terminar(36, 3, $usuario, 'Pintadas y listas'), $errores());
$prueba('pasan de «por terminar» a vendible sin contarse dos veces', $porTerminar(36) === 8 && $stock(36) === $antesVendible + 3 && $stock(36) + $porTerminar(36) === $suma);

echo "Relación flexible con el catálogo\n";
OrdenesCompra::crear(
    ['tipo' => 'semiterminado', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-B'],
    [['producto_id' => 0, 'cantidad' => '5', 'costo' => '50.00', 'descripcion' => 'Reja chica sin soldar']],
    $usuario
);
$B = OrdenesCompra::$ultimoId;
$partidaB = OrdenesCompra::partidas($B)[0];
$prueba('se puede capturar sin producto relacionado', $B > 0 && $partidaB['producto_id'] === null, $errores());
$r = OrdenesCompra::recibir($B, $hoy, [(int) $partidaB['id'] => ['cantidad' => 2, 'costo' => '50.00']], $token(), false, '', $usuario);
$prueba('no se puede recibir sin relacionar (no inventa asociaciones)', !$r && $porTerminar(37) === 0);
OrdenesCompra::getErrores();
$prueba('se relaciona después', OrdenesCompra::relacionarProducto((int) $partidaB['id'], 37, $usuario), $errores());
$r = OrdenesCompra::recibir($B, $hoy, [(int) $partidaB['id'] => ['cantidad' => 5, 'costo' => '50.00', 'terminado' => true]], $token(), false, '', $usuario);
$prueba('«ya viene terminado» sí suma a existencia vendible', $r && $stock(37) === 15 && $porTerminar(37) === 0, $errores());

echo "3. Insumo (pintura) sin producto comercial\n";
$productosAntes = (int) $uno('SELECT COUNT(*) FROM productos');
$movAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_inventario');
$movMatAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_material');
$movPtAntes = (int) $uno('SELECT COUNT(*) FROM movimientos_por_terminar');
$ok = OrdenesCompra::crear(
    ['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-C', 'estado_cfdi' => 'no_facturada', 'iva' => '160.00'],
    [['descripcion' => 'Pintura esmalte negro', 'unidad' => 'L', 'cantidad' => '20', 'costo' => '50.00', 'controla' => 0],
     ['descripcion' => 'Discos de corte 4 1/2', 'unidad' => 'pza', 'cantidad' => '10', 'costo' => '30.00', 'controla' => 0]],
    $usuario
);
$C = OrdenesCompra::$ultimoId;
$prueba('registra pintura y discos sin crear productos', $ok && (int) $uno('SELECT COUNT(*) FROM productos') === $productosAntes, $errores());
$oC = OrdenesCompra::buscarID($C);
$prueba('total 1300 y sin recepción', $oC['total_esperado'] === '1300.00' && $oC['estado'] === 'sin_recepcion');
$r = OrdenesCompra::recibir($C, $hoy, [(int) OrdenesCompra::partidas($C)[0]['id'] => ['cantidad' => 1, 'costo' => '50']], $token(), false, '', $usuario);
$prueba('un consumible no genera movimientos artificiales', !$r && (int) $uno('SELECT COUNT(*) FROM movimientos_inventario') === $movAntes && (int) $uno('SELECT COUNT(*) FROM movimientos_material') === $movMatAntes && (int) $uno('SELECT COUNT(*) FROM movimientos_por_terminar') === $movPtAntes);
OrdenesCompra::getErrores();

echo "4. Materia prima controlada en inventario\n";
$ok = OrdenesCompra::crear(
    ['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-D'],
    [['descripcion' => 'Varilla 3/8', 'unidad' => 'kg', 'cantidad' => '12.5', 'costo' => '24.00', 'controla' => 1, 'material_nuevo' => 'T18 Varilla 3/8']],
    $usuario
);
$D = OrdenesCompra::$ultimoId;
$prueba('crea la compra y el material', $ok && (int) $uno("SELECT COUNT(*) FROM materiales WHERE nombre = 'T18 Varilla 3/8'") === 1, $errores());
$partidaD = OrdenesCompra::partidas($D)[0];
$t = $token();
$r = OrdenesCompra::recibir($D, $hoy, [(int) $partidaD['id'] => ['cantidad' => '12.5', 'costo' => '24.00']], $t, false, '', $usuario);
$mat = Materiales::buscarID((int) $partidaD['material_id']);
$prueba('suma 12.5 al material', $r && (float) $mat['existencia'] === 12.5, $errores());
$prueba('no crea ni toca productos de venta', (int) $uno('SELECT COUNT(*) FROM productos') === $productosAntes);
$r = OrdenesCompra::recibir($D, $hoy, [(int) $partidaD['id'] => ['cantidad' => '12.5', 'costo' => '24.00']], $t, false, '', $usuario);
$prueba('reenviar no duplica el material', !$r && (float) Materiales::buscarID((int) $partidaD['material_id'])['existencia'] === 12.5);
OrdenesCompra::getErrores();
$prueba('la compra queda completa', OrdenesCompra::buscarID($D)['estado'] === 'completa');

echo "5-6. Factura (CFDI) y contabilidad sin duplicar\n";
$gastos0 = $totalGastos();
$g1 = CompraContable::enviarAContabilidad($C, $usuario);
$prueba('enviar a contabilidad crea UN gasto', $g1 > 0 && $totalGastos() === $gastos0 + 1, $errores());
$g2 = CompraContable::enviarAContabilidad($C, $usuario);
$prueba('enviarla otra vez reutiliza el mismo gasto', $g2 === $g1 && $totalGastos() === $gastos0 + 1);
$gasto = Gastos::buscarID($g1);
$prueba('el gasto refleja la compra (total 1460, por validar)', $gasto['total'] === '1460.00' && $gasto['revision_fiscal'] === 'por_validar' && $gasto['naturaleza'] === 'insumo');
$prueba('misma entidad fiscal', (int) $gasto['contribuyente_id'] === (int) $oC['contribuyente_id']);

$uuid = $uuidNuevo();
$ok = CompraContable::adjuntarComprobante($C, $archivo('factura.xml', $cfdi($uuid, '1460.00')), 'auto', $usuario, false);
$prueba('el CFDI llega después y se asocia', $ok, $errores());
$prueba('sigue habiendo un solo gasto', $totalGastos() === $gastos0 + 1 && $gastosDe($C) === 1);
$prueba('la compra pasa a «con CFDI»', OrdenesCompra::buscarID($C)['estado_cfdi'] === 'con_cfdi');
$prueba('el gasto toma el UUID pero NO se vuelve deducible', Gastos::buscarID($g1)['uuid_fiscal'] === $uuid && Gastos::buscarID($g1)['revision_fiscal'] === 'por_validar');
$res = OrdenesCompra::resumen($C);
$prueba('comprado 1460 · facturado 1460 · pendiente de documentar 0', $res['total'] == 1460.0 && $res['facturado'] == 1460.0 && $res['pendiente_documentar'] == 0.0);

echo "9. La misma factura no se incorpora dos veces\n";
OrdenesCompra::crear(
    ['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-E'],
    [['descripcion' => 'Guantes de corte', 'unidad' => 'par', 'cantidad' => '4', 'costo' => '100.00', 'controla' => 0]],
    $usuario
);
$E = OrdenesCompra::$ultimoId;
$ok = CompraContable::adjuntarComprobante($E, $archivo('copia.xml', $cfdi($uuid, '400.00')), 'auto', $usuario, false);
$prueba('el mismo UUID en otra compra se rechaza', !$ok && OrdenesCompra::buscarID($E)['estado_cfdi'] === 'pendiente_cfdi', $errores());
$prueba('quedó un solo gasto con ese UUID', (int) $uno("SELECT COUNT(*) FROM gastos WHERE uuid_fiscal = '$uuid' AND estado = 'vigente'") === 1);
CompraContable::getErrores();

echo "Un PDF no se toma por CFDI\n";
$ok = CompraContable::adjuntarComprobante($E, $archivo('nota.pdf', $pdf), 'factura_pdf', $usuario, false);
$prueba('el PDF se adjunta pero la compra sigue pendiente de CFDI', $ok && OrdenesCompra::buscarID($E)['estado_cfdi'] === 'pendiente_cfdi', $errores());
$prueba('«con CFDI» no se puede fijar a mano', !CompraContable::definirEstadoCfdi($E, 'con_cfdi', $usuario));
CompraContable::getErrores();
$prueba('«sin factura» sí', CompraContable::definirEstadoCfdi($E, 'no_facturada', $usuario) && OrdenesCompra::buscarID($E)['estado_cfdi'] === 'no_facturada');

echo "Los documentos del gasto se reflejan en la compra (venga el cambio de donde venga)\n";
$gastosAntesDoc = $totalGastos();
$docCfdi = (int) $uno("SELECT id FROM documentos WHERE entidad='gasto' AND entidad_id=$g1 AND estado_validacion='cfdi_con_timbre' AND activo=1 LIMIT 1");
$prueba('dar de baja el XML devuelve la compra a «CFDI pendiente»', Archivos::baja($docCfdi, 'Se subió el XML equivocado', $usuario)
    && OrdenesCompra::buscarID($C)['estado_cfdi'] === 'pendiente_cfdi', $errores());
$ok = Archivos::subir('gasto', $g1, $archivo('correcto.xml', $cfdi($uuid, '1460.00')), 'auto', $usuario, null, '', false);
$prueba('subir el XML correcto desde la pantalla genérica la vuelve a «Con CFDI»', $ok && OrdenesCompra::buscarID($C)['estado_cfdi'] === 'con_cfdi', $errores());
$prueba('sin duplicar el gasto', $totalGastos() === $gastosAntesDoc && $gastosDe($C) === 1);

echo "7-8. Pagos parciales: saldo correcto y sin gastos duplicados\n";
$gastosAntesPagos = $totalGastos();
$prueba('saldo inicial 1460', OrdenesCompra::resumen($C)['saldo'] == 1460.0 && OrdenesCompra::resumen($C)['estado_pago'] === 'pendiente');
$tp = $token();
$prueba('pago parcial de 460', PagosCompra::registrar($C, $hoy, '460.00', 'Transferencia', 'SPEI 1', $tp, $usuario), $errores());
$prueba('saldo 1000 · parcial', OrdenesCompra::resumen($C)['saldo'] == 1000.0 && OrdenesCompra::resumen($C)['estado_pago'] === 'parcial');
$prueba('reenviar el pago no lo duplica', !PagosCompra::registrar($C, $hoy, '460.00', 'Transferencia', 'SPEI 1', $tp, $usuario) && OrdenesCompra::resumen($C)['saldo'] == 1000.0);
PagosCompra::getErrores();
$prueba('no se paga más que el saldo', !PagosCompra::registrar($C, $hoy, '1000.01', 'Efectivo', '', $token(), $usuario));
PagosCompra::getErrores();
$prueba('pago final de 1000', PagosCompra::registrar($C, $hoy, '1000.00', 'Efectivo', '', $token(), $usuario), $errores());
$prueba('pagada y saldo 0', OrdenesCompra::resumen($C)['estado_pago'] === 'pagada' && OrdenesCompra::resumen($C)['saldo'] == 0.0);
$prueba('los pagos NO crearon gastos', $totalGastos() === $gastosAntesPagos && $gastosDe($C) === 1);
$prueba('el gasto único quedó marcado como pagado', Gastos::buscarID($g1)['estado_pago'] === 'pagado');
$pagoId = (int) $uno("SELECT id FROM pagos_compra WHERE orden_id = $C ORDER BY id LIMIT 1");
$prueba('cancelar un pago exige motivo', !PagosCompra::cancelar($pagoId, '', $usuario));
PagosCompra::getErrores();
$prueba('cancelar un pago reabre el saldo', PagosCompra::cancelar($pagoId, 'Transferencia rechazada', $usuario) && OrdenesCompra::resumen($C)['saldo'] == 460.0 && Gastos::buscarID($g1)['estado_pago'] === 'pendiente');
$prueba('el pago cancelado se conserva', (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE id = $pagoId AND estado = 'cancelado'") === 1);

echo "Edición protegida\n";
$prueba('con gasto vigente no se edita la compra', !OrdenesCompra::actualizar($C, ['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy],
    [['descripcion' => 'x', 'cantidad' => '1', 'costo' => '1', 'controla' => 0]], $usuario));
OrdenesCompra::getErrores();

echo "6. Compra sin factura primero y documento después\n";
$ok = OrdenesCompra::crear(
    ['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-F'],
    [['descripcion' => 'Electrodos', 'unidad' => 'kg', 'cantidad' => '5', 'costo' => '200.00', 'controla' => 0]],
    $usuario
);
$F = OrdenesCompra::$ultimoId;
$prueba('compra sin factura: queda pendiente de CFDI y sin gasto', $ok && OrdenesCompra::buscarID($F)['estado_cfdi'] === 'pendiente_cfdi' && $gastosDe($F) === 0, $errores());
$antes = $totalGastos();
$ok = CompraContable::adjuntarComprobante($F, $archivo('f.xml', $cfdi($uuidNuevo(), '1000.00')), 'auto', $usuario, false);
$prueba('al llegar el CFDI se crea/usa un solo gasto', $ok && $totalGastos() === $antes + 1 && $gastosDe($F) === 1, $errores());

echo "11. Costos históricos\n";
$db->query('UPDATE productos SET costo_actual = 999 WHERE id = 36');
$prueba('cambiar el costo vigente no altera la compra', OrdenesCompra::partidas($A)[0]['costo_final'] === 100.0 && (string) OrdenesCompra::partidas($A)[0]['costo_esperado'] === '100.00');
$prueba('recibir sin la opción no cambió el costo del catálogo', (string) $uno('SELECT costo_actual FROM productos WHERE id = 36') === '999.00');

echo "12. Historial por proveedor\n";
$filas = OrdenesCompra::listar(['proveedor' => $proveedor, 'q' => 'T18-'], 50, 0);
$prueba('lista las 6 compras del proveedor', count($filas) === 6, (string) count($filas));  // A–F (G se crea después)
$prueba('filtra por tipo insumo', count(OrdenesCompra::listar(['proveedor' => $proveedor, 'q' => 'T18-', 'tipo' => 'insumo'], 50, 0)) === 4);
$prueba('filtra por pago pendiente/parcial/pagada', count(OrdenesCompra::listar(['q' => 'T18-', 'pago' => 'parcial'], 50, 0)) === 1);
$prueba('filtra por CFDI pendiente', count(OrdenesCompra::listar(['q' => 'T18-', 'cfdi' => 'pendiente_cfdi'], 50, 0)) >= 2);
$tot = OrdenesCompra::totalesFiltrados(['proveedor' => $proveedor, 'q' => 'T18-']);
$prueba('totales coherentes (comprado = facturado + pendiente + sin factura)', $tot['compras'] === 6 && $tot['comprado'] > 0 && $tot['saldo'] === $tot['comprado'] - $tot['pagado']);

echo "Cancelación\n";
$prueba('una compra con gasto vigente no se cancela', !OrdenesCompra::cancelar($C, $usuario));
OrdenesCompra::getErrores();
$prueba('una compra con recepciones no se cancela', !OrdenesCompra::cancelar($A, $usuario));
OrdenesCompra::getErrores();
OrdenesCompra::crear(['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-G'],
    [['descripcion' => 'Thinner', 'unidad' => 'L', 'cantidad' => '2', 'costo' => '40', 'controla' => 0]], $usuario);
$G = OrdenesCompra::$ultimoId;
$prueba('una compra limpia sí se cancela y deja constancia', OrdenesCompra::cancelar($G, $usuario) && OrdenesCompra::buscarID($G)['estado'] === 'cancelada'
    && (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad='orden_compra' AND entidad_id=$G AND accion='cancelar'") === 1);
$prueba('no se registran pagos de una compra cancelada', !PagosCompra::registrar($G, $hoy, '10', 'Efectivo', '', $token(), $usuario));
PagosCompra::getErrores();

echo "15. Separación por entidad fiscal y periodos cerrados\n";
$db->query("INSERT INTO contribuyentes (rfc, razon_social) VALUES ('T18XAXX010101', 'T18 Segunda entidad')");
$contrib2 = (int) $db->insert_id;
$db->query("INSERT INTO empresas (contribuyente_id, nombre) VALUES ($contrib2, 'T18 Empresa 2')");
$empresa2 = (int) $db->insert_id;
$contrib1 = Contabilidad::contribuyentePrincipal();
OrdenesCompra::crear(['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-H', 'empresa_id' => $empresa2],
    [['descripcion' => 'Soldadura', 'unidad' => 'kg', 'cantidad' => '2', 'costo' => '100', 'controla' => 0]], $usuario);
$H = OrdenesCompra::$ultimoId;
OrdenesCompra::crear(['tipo' => 'insumo', 'proveedor_id' => $proveedor, 'fecha_solicitud' => $hoy, 'referencia' => 'T18-I'],
    [['descripcion' => 'Lija', 'unidad' => 'pza', 'cantidad' => '5', 'costo' => '10', 'controla' => 0]], $usuario);
$I = OrdenesCompra::$ultimoId;
$prueba('cada compra queda en la entidad de su negocio', (int) OrdenesCompra::buscarID($H)['contribuyente_id'] === $contrib2 && (int) OrdenesCompra::buscarID($I)['contribuyente_id'] === $contrib1);
$gH = CompraContable::enviarAContabilidad($H, $usuario);
$gI = CompraContable::enviarAContabilidad($I, $usuario);
$prueba('el gasto de cada compra pertenece a la misma entidad y negocio',
    $gH > 0 && (int) Gastos::buscarID($gH)['contribuyente_id'] === $contrib2 && (int) Gastos::buscarID($gH)['empresa_id'] === $empresa2
    && $gI > 0 && (int) Gastos::buscarID($gI)['contribuyente_id'] === $contrib1, $errores());
$mesActual = (int) date('n');
$anioActual = (int) date('Y');
$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES ($contrib1, $anioActual, $mesActual, 'cerrado', NOW())");
$prueba('con el periodo cerrado de la entidad A, no se paga una compra de A',
    !PagosCompra::registrar($I, $hoy, '10', 'Efectivo', '', $token(), $usuario) && (float) OrdenesCompra::resumen($I)['pagado'] === 0.0);
PagosCompra::getErrores();
$prueba('pero sí una compra de la entidad B (periodos independientes)', PagosCompra::registrar($H, $hoy, '50', 'Efectivo', '', $token(), $usuario), $errores());
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = $contrib1 AND anio = $anioActual AND mes = $mesActual");

$limpiar();

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
