<?php
/**
 * pruebas/php/etapa12.php — Conversión cotización→venta y facturación comercial (IVA, CFDI, confirmación manual).
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa12.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Contabilidad, Cotizaciones, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) {
    $db->query("DELETE FROM $t");
}
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones');
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

$cotizacion = function (string $folio, int $iva) use ($db, $cliente, $usuario, $hoy) {
    $sub = 100; $imp = $iva ? 16 : 0;
    $db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total)
                VALUES ('$folio', 1, $cliente, $usuario, '$hoy', 'pendiente', $sub, $iva, $imp, " . ($sub + $imp) . ')');
    $id = (int) $db->insert_id;
    $db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($id, 36, 1, 100, 100)");
    return $id;
};
$ingresoDe = fn (int $ventaId) => Contabilidad::buscarIngreso((int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaId"));

echo "Conversión cotización → venta\n";
$c1 = $cotizacion('FOL-E12-1', 1);
$prueba('una cotización pendiente NO es venta ni ingreso', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $c1") === 0 && (int) $uno('SELECT COUNT(*) FROM ingresos') === 0);
$prueba('convertir', Cotizaciones::cambiarEstado($c1, 'vendido', $usuario));
Cotizaciones::cambiarEstado($c1, 'vendido', $usuario);
Cotizaciones::cambiarEstado($c1, 'vendido', $usuario);
$prueba('repetir la conversión no duplica la venta', (int) $uno("SELECT COUNT(*) FROM ventas WHERE cotizacion_id = $c1") === 1);
$v1 = (int) $uno("SELECT id FROM ventas WHERE cotizacion_id = $c1");
$prueba('ni el ingreso ni el stock', (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $v1") === 1 && (int) $uno('SELECT stock FROM productos WHERE id = 36') === 99);
$fila = $db->query("SELECT convertida_en, convertida_por, total, iva, aplica_iva FROM ventas WHERE id = $v1")->fetch_assoc();
$prueba('registra fecha y usuario de la conversión', $fila['convertida_en'] !== null && (int) $fila['convertida_por'] === $usuario);
$prueba('conserva IVA y total', $fila['total'] === '116.00' && $fila['iva'] === '16.00' && (int) $fila['aplica_iva'] === 1);

echo "Clasificación inicial por IVA\n";
$i1 = $ingresoDe($v1);
$prueba('con IVA → pendiente de facturar', $i1['estado_facturacion'] === 'sin_clasificar' && $i1['facturacion_origen'] === 'regla_iva');
$c2 = $cotizacion('FOL-E12-2', 0);
Cotizaciones::cambiarEstado($c2, 'vendido', $usuario);
$i2 = $ingresoDe((int) $uno("SELECT id FROM ventas WHERE cotizacion_id = $c2"));
$prueba('sin IVA → no facturada', $i2['estado_facturacion'] === 'no_facturada' && $i2['iva'] === '0.00');
$ok = Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 0, 'descuento_pct' => 0], [37 => ['cantidad' => 1, 'precio' => 50, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$prueba('venta directa sin IVA → no facturada', $ok && $ingresoDe(Ventas::$ultimoId)['estado_facturacion'] === 'no_facturada');
$ok = Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'descuento_pct' => 0], [37 => ['cantidad' => 1, 'precio' => 50, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$i3 = $ingresoDe(Ventas::$ultimoId);
$prueba('venta directa con IVA → pendiente de facturar', $ok && $i3['estado_facturacion'] === 'sin_clasificar');
$prueba('ninguna cuenta como facturada (CFDI emitido)', (int) $uno("SELECT COUNT(*) FROM ingresos WHERE estado_facturacion = 'facturada'") === 0);
$sf = Contabilidad::sinFacturar((int) date('Y'), 0, 0, 'sin_clasificar');
$prueba('apartado de pendientes solo trae las con IVA', count($sf['filas']) === 2 && $sf['totales']['no_facturada'] == 0, (string) count($sf['filas']));
$prueba('apartado de no facturadas solo trae las sin IVA', count(Contabilidad::sinFacturar((int) date('Y'), 0, 0, 'no_facturada')['filas']) === 2);

echo "Facturada: CFDI o confirmación manual\n";
$id1 = (int) $i1['id'];
$prueba('«facturada» sin CFDI ni confirmación se rechaza', !Contabilidad::actualizarFacturacion($id1, 'facturada', 'F-1', '', $hoy, $usuario));
$prueba('…y no cambió el estado', Contabilidad::buscarIngreso($id1)['estado_facturacion'] === 'sin_clasificar');
$prueba('con confirmación manual se acepta', Contabilidad::actualizarFacturacion($id1, 'facturada', 'F-1', '', $hoy, $usuario, true), implode('|', Contabilidad::getErrores()));
$m = Contabilidad::buscarIngreso($id1);
$prueba('queda marcada como manual, con usuario y fecha', (int) $m['facturacion_manual'] === 1 && (int) $m['facturacion_usuario_id'] === $usuario && $m['facturacion_en'] !== null && $m['facturacion_origen'] === 'usuario');
$prueba('regresa a pendiente', Contabilidad::actualizarFacturacion($id1, 'sin_clasificar', '', '', '', $usuario));
$prueba('al salir de «facturada» se limpia lo manual', (int) Contabilidad::buscarIngreso($id1)['facturacion_manual'] === 0);

$uuid = 'ABCDEF01-2345-6789-ABCD-EF0123456789';
$contrib = (int) $i1['contribuyente_id'];
$db->query("INSERT INTO documentos (contribuyente_id, entidad, entidad_id, tipo, nombre_original, archivo, mime, tamano, sha256, uuid_fiscal, estado_validacion, subido_por)
            VALUES ($contrib, 'ingreso', $id1, 'xml', 'cfdi.xml', 'x/cfdi.xml', 'text/xml', 10, '" . str_repeat('a', 64) . "', '$uuid', 'cfdi_con_timbre', $usuario)");
$doc = (int) $db->insert_id;
$prueba('detecta el CFDI adjunto', (int) (Contabilidad::cfdiDeIngreso($id1)['id'] ?? 0) === $doc);
$prueba('UUID distinto al del CFDI se rechaza', !Contabilidad::actualizarFacturacion($id1, 'facturada', 'F-1', '11111111-1111-1111-1111-111111111111', $hoy, $usuario));
$prueba('con CFDI se confirma sin casilla manual', Contabilidad::actualizarFacturacion($id1, 'facturada', 'F-1', '', $hoy, $usuario), implode('|', Contabilidad::getErrores()));
$m = Contabilidad::buscarIngreso($id1);
$prueba('vincula el documento y toma su UUID', (int) $m['factura_documento_id'] === $doc && $m['uuid_fiscal'] === $uuid && (int) $m['facturacion_manual'] === 0);

echo "Historial\n";
$h = Contabilidad::historialFacturacion($id1);
$prueba('conserva todos los cambios (inicial + 3)', count($h) === 4, (string) count($h));
$prueba('el más reciente es verificable con documento', ($h[0]['detalle']['documento_id'] ?? 0) == $doc && ($h[0]['usuario_nombre'] ?? '') !== '');
$prueba('el primero es la regla de IVA', ($h[3]['detalle']['origen'] ?? '') === 'regla_iva');

echo "Reclasificación automática mientras nadie la fije\n";
$id3 = (int) $i3['id'];
$ventaId3 = (int) $i3['venta_id'];
$prueba('cambiar a sin IVA reclasifica', Ventas::actualizar(['id' => $ventaId3, 'empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 0, 'descuento_pct' => 0], [37 => ['cantidad' => 1, 'precio' => 50, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario), implode('|', Ventas::getErrores()));
$prueba('…pasa a no facturada', Contabilidad::buscarIngreso($id3)['estado_facturacion'] === 'no_facturada');
Contabilidad::actualizarFacturacion($id3, 'no_facturada', '', '', '', $usuario);
Ventas::actualizar(['id' => $ventaId3, 'empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'descuento_pct' => 0], [37 => ['cantidad' => 1, 'precio' => 50, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$prueba('si el usuario la fijó, ya no se toca', Contabilidad::buscarIngreso($id3)['estado_facturacion'] === 'no_facturada');

echo "Documentos fiscales por situación\n";
$df = Contabilidad::documentosFiscales((int) date('Y'), 0);
$cuenta = array_sum(array_map(static fn ($g) => count($g), $df['grupos']));
$prueba('cada venta cae en un solo grupo', $cuenta === (int) $uno("SELECT COUNT(*) FROM ingresos WHERE estado = 'vigente' OR estado_facturacion = 'facturada'"), (string) $cuenta);
$prueba('ninguna venta pendiente o sin factura aparece como CFDI emitido', !array_intersect(array_column($df['grupos']['emitidos'], 'estado_facturacion'), ['sin_clasificar', 'no_facturada']));
$prueba('la venta con CFDI vinculado sí aparece (emitida o por validar)', in_array((int) $i1['id'], array_column(array_merge($df['grupos']['emitidos'], $df['grupos']['por_validar']), 'id')));
$prueba('filtrar por cliente inexistente no trae nada', array_sum(array_map('count', Contabilidad::documentosFiscales((int) date('Y'), 0, 0, 999999)['grupos'])) === 0);
$prueba('sinFacturar filtra por cliente', count(Contabilidad::sinFacturar((int) date('Y'), 0, 0, '', 999999)['filas']) === 0);
$idA = (int) $i2['id'];
$uuidPrueba = '11111111-2222-3333-4444-555555555555';
$prueba('confirmación manual con UUID', Contabilidad::actualizarFacturacion($idA, 'facturada', 'F-1', $uuidPrueba, $hoy, $usuario, true), implode('|', Contabilidad::getErrores()));
$prueba('manual → «por validar», no «emitidos»', count(Contabilidad::documentosFiscales((int) date('Y'), 0)['grupos']['por_validar']) === 1);
$prueba('el mismo UUID no se reutiliza en otra venta', !Contabilidad::actualizarFacturacion((int) $i3['id'], 'facturada', 'F-2', $uuidPrueba, $hoy, $usuario, true));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos fallas\n";
exit($fallos === 0 ? 0 : 1);
