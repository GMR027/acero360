<?php
/**
 * pruebas/php/etapa43.php — Módulo de seguimiento de entregas.
 *
 * Cubre el camino completo de «qué pasó después de entregar»:
 *   · listado con filtros por estado, texto y negocio;
 *   · registro de una incidencia: el producto regresa al inventario (kardex
 *     «entrada/devolucion»), queda anotado en venta_devoluciones y el pendiente
 *     de entrega se ajusta solo;
 *   · estados generales de la venta (pendiente → entregado con incidencias →
 *     incidencias resueltas) y fecha/observaciones del seguimiento;
 *   · cierre: resolver y cancelar, con sus rechazos (dos veces, motivos
 *     inválidos, cantidades de más, sin renglones);
 *   · sustitución de la factura: sube el PDF y el XML correctos, da de baja
 *     los anteriores y sincroniza folio, UUID, fecha, subtotal, IVA y total
 *     del ingreso con los datos del XML (el importe de la venta no cambia);
 *   · aviso nuevo en el Centro de seguimiento.
 *
 * Todo lo que crea se borra al final: la etapa deja la base como estaba.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa43.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Contabilidad;
use App\Entregas;
use App\IncidenciasEntrega;
use App\Inventario;
use App\Seguimiento;
use App\Ventas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios ORDER BY id LIMIT 1');
$_SESSION['id'] = $usuario;
$_SESSION['rol'] = 1;
$correlativo = bin2hex(random_bytes(3));

/* -------------------------------------------------------------------- *
 * ENTORNO: venta PROPIA de la etapa (5 piezas del producto con más stock),
 * creada aquí y borrada en la limpieza. Antes se reusaba una venta real de
 * la base y, cuando no tenía 2 piezas disponibles, la sustitución de factura
 * se omitía: prueba no determinista.
 * ------------------------------------------------------------------ */
$empresaId = (int) $uno('SELECT id FROM empresas ORDER BY id LIMIT 1');
$clienteId = (int) $uno('SELECT id FROM clientes ORDER BY id LIMIT 1');
$producto = $db->query('SELECT id, stock FROM productos ORDER BY stock DESC LIMIT 1')->fetch_assoc();
$prueba('hay un producto con stock para la etapa', $producto !== null && (int) $producto['stock'] >= 10,
    $producto ? ('stock ' . $producto['stock']) : 'sin productos');
if ($producto === null || (int) $producto['stock'] < 10) {
    exit(1);
}
$productoId   = (int) $producto['id'];
$stockOriginal = (int) $producto['stock'];   // el de la base, que se restaura al final
$db->query("UPDATE productos SET stock = 100 WHERE id = $productoId");

$piezas = 5;
$creada = Ventas::crear([
    'empresa_id' => $empresaId, 'cliente_id' => $clienteId, 'fecha' => date('Y-m-d'),
    'aplica_iva' => 0, 'descuento_pct' => 0,
], [$productoId => ['cantidad' => $piezas, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
$ventaId = (int) Ventas::$ultimoId;
$prueba('venta propia de la etapa creada', $creada && $ventaId > 0, implode(' · ', Ventas::getErrores()));
if (!$creada || $ventaId <= 0) {
    exit(1);
}

/* La venta queda marcada como facturada: así el motivo «faltante» fuerza la
 * corrección de factura (IncidenciasEntrega::MOTIVOS_CORRECCION). */
$db->query("UPDATE ingresos SET estado_facturacion = 'facturada', uuid_fiscal = 'T43-FACT-"
    . $db->real_escape_string($correlativo) . "' WHERE venta_id = $ventaId");

$venta = Ventas::buscarID($ventaId);
$stockAntes = (int) $uno("SELECT stock FROM productos WHERE id = $productoId");   // tras el descuento de la venta
$fechaVentaAntes = (string) $venta->fecha;
$ingreso = Contabilidad::ingresoDeVenta($ventaId);
$ingresoId = $ingreso ? (int) $ingreso['id'] : 0;

/* -------------------------------------------------------------------- *
 * 1. LISTADO Y FILTROS
 * ------------------------------------------------------------------ */
echo "— listado —\n";
$conteo = IncidenciasEntrega::conteoEstados();
$prueba('conteoEstados con sus cuatro claves', array_keys($conteo) === ['pendiente', 'entregado', 'entregado_con_incidencias', 'entregado_incidencias_resueltas'], implode(',', array_keys($conteo)));
$total = IncidenciasEntrega::contarListado([]);
$filas = IncidenciasEntrega::listar([], 50, 0);
$prueba('contarListado y listar coinciden', $total > 0 && count($filas) === min($total, 50), "$total vs " . count($filas));
$prueba('filtro por estado', IncidenciasEntrega::contarListado(['estado' => 'pendiente']) === $conteo['pendiente']);
$prueba('filtro por texto sin coincidencias', IncidenciasEntrega::contarListado(['q' => 'zzz-' . $correlativo]) === 0);
$folio = (string) $uno("SELECT folio FROM ventas WHERE id = $ventaId");
$prueba('filtro por texto real', IncidenciasEntrega::contarListado(['q' => $folio]) > 0, $folio);

/* -------------------------------------------------------------------- *
 * 2. REGISTRAR INCIDENCIA (devuelve el producto al inventario)
 * ------------------------------------------------------------------ */
echo "— incidencia —\n";
$registrada = IncidenciasEntrega::registrar(
    $ventaId, 'producto_faltante', [$productoId => ['cantidad' => 1, 'cambio' => 0]],
    'T43 no llegó la pieza ' . $correlativo, false, $usuario
);
$prueba('registrar incidencia', $registrada, implode(' · ', IncidenciasEntrega::getErrores()));
$incidenciaId = IncidenciasEntrega::$ultimoId;
$prueba('la pieza regresó al inventario', (int) $uno("SELECT stock FROM productos WHERE id = $productoId") === $stockAntes + 1,
    "$stockAntes → " . $uno("SELECT stock FROM productos WHERE id = $productoId"));
$prueba('kardex: entrada con motivo devolucion',
    (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $ventaId AND producto_id = $productoId AND tipo = 'entrada' AND motivo = 'devolucion' AND notas LIKE 'Incidencia de entrega%'") > 0);
$prueba('anotado en venta_devoluciones',
    (int) $uno("SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id = $ventaId AND producto_id = $productoId AND nota LIKE 'Incidencia de entrega%'") > 0);
$prueba('faltante en venta facturada fuerza la corrección de factura',
    (int) $uno("SELECT factura_requerida FROM entrega_incidencias WHERE id = $incidenciaId") === 1);
$prueba('la incidencia queda abierta con su renglón',
    (int) $uno("SELECT COUNT(*) FROM entrega_incidencias WHERE id = $incidenciaId AND estado = 'abierta'") === 1
    && (int) $uno("SELECT COUNT(*) FROM entrega_incidencia_renglones WHERE incidencia_id = $incidenciaId") === 1);
$prueba('buscar() y deVenta() la encuentran',
    IncidenciasEntrega::buscar($incidenciaId) !== null && count(IncidenciasEntrega::deVenta($ventaId)) > 0);

$faltan = array_sum(array_column(Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? [], 'pendiente'));
$estado = (string) $uno("SELECT entrega_estado FROM ventas WHERE id = $ventaId");
$esperado = $faltan > 0 ? 'pendiente' : 'entregado_con_incidencias';
$prueba('estado general coherente tras registrar', $estado === $esperado, "$estado (faltan $faltan)");
$prueba('el listado ya muestra la incidencia abierta',
    (int) $uno("SELECT incidencias_abiertas FROM (" .
        "SELECT (SELECT COUNT(*) FROM entrega_incidencias e WHERE e.venta_id = v.id AND e.estado = 'abierta') AS incidencias_abiertas
           FROM ventas v WHERE v.id = $ventaId) t") === 1);

/* -------------------------------------------------------------------- *
 * 3. FECHA Y OBSERVACIONES DEL SEGUIMIENTO
 * ------------------------------------------------------------------ */
echo "— seguimiento —\n";
$fecha = date('Y-m-d');
$observaciones = 'T43 recibe el cliente en almacén ' . $correlativo;
$prueba('actualizarDatos', IncidenciasEntrega::actualizarDatos($ventaId, $fecha, $observaciones, $usuario), implode(' · ', IncidenciasEntrega::getErrores()));
$revisada = Ventas::buscarID($ventaId);
$prueba('fecha y observaciones guardadas', $revisada->entrega_fecha === $fecha && (string) $revisada->entrega_observaciones === $observaciones);
$prueba('rechaza una fecha inválida', !IncidenciasEntrega::actualizarDatos($ventaId, 'no-es-fecha', '', $usuario));

/* -------------------------------------------------------------------- *
 * 4. RECHAZOS Y CIERRES
 * ------------------------------------------------------------------ */
echo "— rechazos —\n";
$prueba('rechaza motivo inválido',
    !IncidenciasEntrega::registrar($ventaId, 'motivo_falso', [$productoId => ['cantidad' => 1, 'cambio' => 0]], '', false, $usuario));
$prueba('rechaza cantidad mayor a lo vendido',
    !IncidenciasEntrega::registrar($ventaId, 'devolucion', [$productoId => ['cantidad' => 99999, 'cambio' => 0]], '', false, $usuario));
$prueba('rechaza un registro sin renglones',
    !IncidenciasEntrega::registrar($ventaId, 'devolucion', [], '', false, $usuario));
$prueba('resolver la incidencia funciona',
    IncidenciasEntrega::cerrar($incidenciaId, 'resuelta', 'T43 se resolvió', false, [], $usuario), implode(' · ', IncidenciasEntrega::getErrores()));
$prueba('rechaza cerrar dos veces',
    !IncidenciasEntrega::cerrar($incidenciaId, 'cancelada', 'Ya cerrada', false, [], $usuario));

/* -------------------------------------------------------------------- *
 * 4b. NOTIFICACIÓN SIN INVENTARIO (devolución / dañado / cambio)
 *     Sólo faltante e incompleto devuelven piezas al almacén; el resto
 *     sólo queda anotado en la venta.
 * ------------------------------------------------------------------ */
echo "— notificación sin inventario —\n";
$stockNotificado = (int) $uno("SELECT stock FROM productos WHERE id = $productoId");
$kardexNotificado = (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $ventaId");
$registradaDevol = IncidenciasEntrega::registrar(
    $ventaId, 'devolucion', [$productoId => ['cantidad' => 1, 'cambio' => 0]],
    'T43 devolución notificada ' . $correlativo, false, $usuario
);
$incDevol = IncidenciasEntrega::$ultimoId;
$prueba('la devolución se registra como notificación', $registradaDevol, implode(' · ', IncidenciasEntrega::getErrores()));
$prueba('NO mueve el inventario (stock)',
    (int) $uno("SELECT stock FROM productos WHERE id = $productoId") === $stockNotificado,
    $stockNotificado . ' → ' . $uno("SELECT stock FROM productos WHERE id = $productoId"));
$prueba('NO mueve el inventario (kardex)',
    (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $ventaId") === $kardexNotificado);
$prueba('queda anotada en venta_devoluciones',
    (int) $uno("SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id = $ventaId AND producto_id = $productoId AND nota LIKE 'Incidencia de entrega (Devolución)%'") > 0);
$prueba('en devolución NO se fuerza la corrección de factura',
    (int) $uno("SELECT factura_requerida FROM entrega_incidencias WHERE id = $incDevol") === 0);

/* -------------------------------------------------------------------- *
 * 5. HISTORIAL Y CENTRO DE SEGUIMIENTO
 * ------------------------------------------------------------------ */
echo "— historial —\n";
$historial = IncidenciasEntrega::historial($ventaId);
$prueba('historial con al menos la incidencia', count($historial) >= 1 && isset($historial[0]['fecha']), count($historial) . ' líneas');
try {
    $avisos = Seguimiento::avisos();
    $abiertos = array_filter($avisos, static fn ($a) => str_starts_with($a['clave'], 'incidencia:'));
    $prueba('Centro de seguimiento calcula sus avisos', is_array($avisos), count($avisos) . ' avisos, ' . count($abiertos) . ' de incidencias');
} catch (\Throwable $e) {
    $prueba('Centro de seguimiento calcula sus avisos', false, $e->getMessage());
}

/* -------------------------------------------------------------------- *
 * 6. SUSTITUCIÓN DE LA FACTURA
 * ------------------------------------------------------------------ */
echo "— factura —\n";
$piezasRestantes = (int) (Inventario::devolvibles($ventaId)[$productoId]['disponible'] ?? 0);
if (!$ingreso || $ingreso['estado'] !== 'vigente') {
    $prueba('la venta tiene ingreso vigente', false, 'sin ingreso vigente: se omite la sustitución');
} elseif ($piezasRestantes < 1) {
    echo "  omitida (la venta sólo tenía una pieza disponible para dos incidencias)\n";
} else {
    $origen = [
        'subtotal' => $ingreso['subtotal'], 'descuento' => $ingreso['descuento'], 'iva' => $ingreso['iva'],
        'total' => $ingreso['total'], 'factura_folio' => $ingreso['factura_folio'], 'uuid_fiscal' => $ingreso['uuid_fiscal'],
        'fecha_facturacion' => $ingreso['fecha_facturacion'], 'estado_facturacion' => $ingreso['estado_facturacion'],
        'factura_documento_id' => $ingreso['factura_documento_id'], 'facturacion_origen' => $ingreso['facturacion_origen'],
        'facturacion_manual' => $ingreso['facturacion_manual'],
    ];

    // Incidencia que pide cancelar la factura y subir la correcta.
    $conFactura = IncidenciasEntrega::registrar(
        $ventaId, 'devolucion', [$productoId => ['cantidad' => 1, 'cambio' => 0]],
        'T43 factura ' . $correlativo, true, $usuario
    );
    $incFactura = IncidenciasEntrega::$ultimoId;
    $prueba('incidencia que pide corregir la factura', $conFactura, implode(' · ', IncidenciasEntrega::getErrores()));

    // Documentos «anteriores» (la factura mal emitida).
    $pdfViejo = sys_get_temp_dir() . '/t43_viejo_' . $correlativo . '.pdf';
    $xmlViejo = sys_get_temp_dir() . '/t43_viejo_' . $correlativo . '.xml';
    file_put_contents($pdfViejo, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $uuidViejo = strtoupper('AAAA' . substr($correlativo, 0, 4) . '-1111-2222-3333-444444444444');
    file_put_contents($xmlViejo, cfdiDePrueba($uuidViejo, '2026-01-15T09:00:00', '116.00', '116.00', 'A', '0001', 't43 viejo'));
    $pdfOk = App\Archivos::subir('ingreso', $ingresoId, ['name' => 't43_vieja.pdf', 'tmp_name' => $pdfViejo, 'error' => 0, 'size' => filesize($pdfViejo)], 'factura_pdf', $usuario, null, 'T43 factura anterior', false);
    $pdfViejoId = (int) App\Archivos::$ultimoId;
    $xmlOk = App\Archivos::subir('ingreso', $ingresoId, ['name' => 't43_vieja.xml', 'tmp_name' => $xmlViejo, 'error' => 0, 'size' => filesize($xmlViejo)], 'xml', $usuario, null, 'T43 factura anterior', false);
    $xmlViejoId = (int) App\Archivos::$ultimoId;
    $prueba('documentos anteriores subidos', $pdfOk && $xmlOk, implode(' · ', array_merge(App\Archivos::getErrores(), App\Archivos::getErrores())));

    // Cierre con la factura correcta (PDF y XML distintos de los anteriores).
    $uuidNuevo = strtoupper('BBBB' . substr($correlativo, 0, 4) . '-1111-2222-3333-444444444444');
    $pdfNuevo = sys_get_temp_dir() . '/t43_nuevo_' . $correlativo . '.pdf';
    $xmlNuevo = sys_get_temp_dir() . '/t43_nuevo_' . $correlativo . '.xml';
    file_put_contents($pdfNuevo, "%PDF-1.4\n1 0 obj<</Type/Catalog /Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    file_put_contents($xmlNuevo, cfdiDePrueba($uuidNuevo, '2026-01-20T11:30:00', '86.21', '100.00', 'A', '1002', 't43 correcto'));
    $cerrada = IncidenciasEntrega::cerrar($incFactura, 'resuelta', 'T43 se factura solo lo entregado', true, [
        'pdf' => ['name' => 't43_correcta.pdf', 'tmp_name' => $pdfNuevo, 'error' => 0, 'size' => filesize($pdfNuevo)],
        'xml' => ['name' => 't43_correcta.xml', 'tmp_name' => $xmlNuevo, 'error' => 0, 'size' => filesize($xmlNuevo)],
        'fecha' => '2026-01-20',
    ], $usuario, false);
    $prueba('cerrar con sustitución de la factura', $cerrada, implode(' · ', IncidenciasEntrega::getErrores()));

    $despues = Contabilidad::ingresoDeVenta($ventaId);
    $prueba('UUID sincronizado con el XML nuevo', strtoupper((string) $despues['uuid_fiscal']) === $uuidNuevo, (string) $despues['uuid_fiscal']);
    $prueba('folio comercial sincronizado', $despues['factura_folio'] === 'A1002', (string) $despues['factura_folio']);
    $prueba('fecha de facturación sincronizada', $despues['fecha_facturacion'] === '2026-01-20', (string) $despues['fecha_facturacion']);
    $prueba('subtotal / IVA / total sincronizados',
        Contabilidad::centavos($despues['subtotal']) === Contabilidad::centavos('86.21')
        && Contabilidad::centavos($despues['iva']) === Contabilidad::centavos('13.79')
        && Contabilidad::centavos($despues['total']) === Contabilidad::centavos('100.00'),
        $despues['subtotal'] . ' / ' . $despues['iva'] . ' / ' . $despues['total']);
    $prueba('sigue clasificada como facturada', $despues['estado_facturacion'] === 'facturada', $despues['estado_facturacion']);

    $docs = App\Archivos::deOperacion('ingreso', $ingresoId);
    $viejoPdf = App\Archivos::buscarID($pdfViejoId);
    $viejoXml = App\Archivos::buscarID($xmlViejoId);
    $prueba('los documentos antiguos quedaron dados de baja con motivo',
        $viejoPdf && $viejoXml && (int) $viejoPdf['activo'] === 0 && (int) $viejoXml['activo'] === 0
        && $viejoPdf['motivo_baja'] !== null, 'pdf#' . $pdfViejoId . ' xml#' . $xmlViejoId);
    $activosNuevos = array_filter($docs, static fn ($d) => (int) $d['activo'] === 1
        && in_array($d['nombre_original'], ['t43_correcta.pdf', 't43_correcta.xml'], true));
    $prueba('los documentos nuevos siguen activos', count($activosNuevos) === 2,
        implode(', ', array_map(static fn ($d) => $d['nombre_original'], $activosNuevos)));
    $docXml = $despues['factura_documento_id'] ? App\Archivos::buscarID((int) $despues['factura_documento_id']) : null;
    $prueba('el ingreso apunta al XML correcto activo',
        $docXml && (int) $docXml['activo'] === 1 && $docXml['nombre_original'] === 't43_correcta.xml');
    $prueba('la incidencia quedó resuelta con su documento',
        (int) $uno("SELECT COUNT(*) FROM entrega_incidencias WHERE id = $incFactura AND estado = 'resuelta' AND factura_documento_id IS NOT NULL") === 1);

    /* Editar la venta después (aquí, cambiarle la fecha) ya NO pisa los montos
     * corregidos por la factura sustituida: se sincroniza lo demás y se avisa. */
    Contabilidad::getAvisos();   // limpia avisos previos
    $db->query("UPDATE ventas SET fecha = DATE_ADD(fecha, INTERVAL 1 DAY) WHERE id = $ventaId");
    $sincronizada = Contabilidad::sincronizarIngreso($ventaId, $usuario);
    $trasEdicion = Contabilidad::ingresoDeVenta($ventaId);
    $avisosEdicion = Contabilidad::getAvisos();
    $prueba('sincronizar la venta editada no falla', $sincronizada, implode(' · ', Contabilidad::getErrores()));
    $prueba('los montos de la factura sobreviven a la edición de la venta',
        $trasEdicion !== null
        && Contabilidad::centavos($trasEdicion['total']) === Contabilidad::centavos('100.00')
        && Contabilidad::centavos($trasEdicion['subtotal']) === Contabilidad::centavos('86.21'),
        $trasEdicion ? ($trasEdicion['subtotal'] . ' / ' . $trasEdicion['total']) : 'sin ingreso');
    $prueba('la fecha de la venta SÍ se propagó al ingreso',
        $trasEdicion !== null && $trasEdicion['fecha'] === date('Y-m-d', strtotime($fechaVentaAntes . ' +1 day')),
        $trasEdicion ? (string) $trasEdicion['fecha'] : 'sin ingreso');
    $prueba('dejó el aviso de importes distintos',
        count($avisosEdicion) === 1 && str_contains($avisosEdicion[0], 'importes distintos'),
        implode(' | ', $avisosEdicion));
    $db->query("UPDATE ventas SET fecha = '" . $db->real_escape_string($fechaVentaAntes) . "' WHERE id = $ventaId");
    Contabilidad::sincronizarIngreso($ventaId, $usuario);   // la fecha del ingreso vuelve a su sitio
    Contabilidad::getAvisos();                              // (el aviso de montos se descarta aquí)

    // Restauración contable: la etapa no deja rastro en el ingreso.
    $db->begin_transaction();
    $db->query("UPDATE ingresos SET subtotal = '" . $origen['subtotal'] . "', descuento = '" . $origen['descuento']
        . "', iva = '" . $origen['iva'] . "', total = '" . $origen['total'] . "',"
        . " factura_folio = " . ($origen['factura_folio'] === null ? 'NULL' : "'" . $db->real_escape_string($origen['factura_folio']) . "'") . ","
        . " uuid_fiscal = " . ($origen['uuid_fiscal'] === null ? 'NULL' : "'" . $db->real_escape_string($origen['uuid_fiscal']) . "'") . ","
        . " fecha_facturacion = " . ($origen['fecha_facturacion'] === null ? 'NULL' : "'" . $origen['fecha_facturacion'] . "'") . ","
        . " estado_facturacion = '" . $origen['estado_facturacion'] . "', factura_documento_id = "
        . ((int) $origen['factura_documento_id'] ?: 'NULL') . ", facturacion_origen = '" . $origen['facturacion_origen']
        . "', facturacion_manual = " . (int) $origen['facturacion_manual'] . " WHERE id = $ingresoId");
    $db->commit();

    // Documentos de prueba (los reales de la etapa se identifican por «t43»).
    $db->query("DELETE FROM auditoria WHERE entidad = 'documento' AND entidad_id IN
        (SELECT id FROM documentos WHERE entidad = 'ingreso' AND nombre_original LIKE '%t43%')");
    $db->query("DELETE FROM documentos WHERE entidad = 'ingreso' AND nombre_original LIKE '%t43%'");
    @unlink($pdfViejo);
    @unlink($xmlViejo);
    @unlink($pdfNuevo);
    @unlink($xmlNuevo);
}

/* -------------------------------------------------------------------- *
 * LIMPIEZA Y VERIFICACIÓN FINAL (la venta es de la etapa: se borra entera)
 * ------------------------------------------------------------------ */
$db->begin_transaction();
$db->query("DELETE r FROM entrega_incidencia_renglones r JOIN entrega_incidencias i ON i.id = r.incidencia_id WHERE i.venta_id = $ventaId");
$db->query("DELETE FROM entrega_incidencias WHERE venta_id = $ventaId");
$db->query("DELETE FROM venta_devoluciones WHERE venta_id = $ventaId");
$db->query("DELETE FROM movimientos_inventario WHERE venta_id = $ventaId");
$db->query("DELETE FROM auditoria WHERE entidad = 'seguimiento'
    OR (entidad = 'venta' AND entidad_id = $ventaId)
    OR (entidad = 'ingreso' AND entidad_id = $ingresoId)");
$db->query("DELETE FROM venta_detalles WHERE venta_id = $ventaId");
$db->query("DELETE FROM ingresos WHERE venta_id = $ventaId");
$db->query("DELETE FROM ventas WHERE id = $ventaId");
$db->commit();
$db->query("UPDATE productos SET stock = $stockOriginal WHERE id = $productoId");

echo "— limpieza —\n";
$prueba('stock restaurado al original', (int) $uno("SELECT stock FROM productos WHERE id = $productoId") === $stockOriginal);
$prueba('sin incidencias ni renglones', (int) $uno('SELECT COUNT(*) FROM entrega_incidencias') === 0
    && (int) $uno('SELECT COUNT(*) FROM entrega_incidencia_renglones') === 0);
$prueba('sin devoluciones ni movimientos residuales',
    (int) $uno("SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id = $ventaId") === 0
    && (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id = $ventaId") === 0);
$prueba('sin documentos de prueba en contabilidad',
    (int) $uno("SELECT COUNT(*) FROM documentos WHERE entidad = 'ingreso' AND nombre_original LIKE '%t43%'") === 0);
$prueba('la venta propia de la etapa quedó borrada con su ingreso',
    (int) $uno("SELECT COUNT(*) FROM ventas WHERE id = $ventaId") === 0
    && (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $ventaId") === 0);

echo $fallos ? "\nFALLAS: $fallos\n" : "\nEtapa 43: todas las pruebas correctas\n";
exit($fallos ? 1 : 0);

/**
 * CFDI 4.0 mínimo para probar la lectura: root «Comprobante» (se consulta por
 * local-name), timbre con UUID y totales; el IVA va en los Traslados.
 */
function cfdiDePrueba(string $uuid, string $fecha, string $subtotal, string $total, string $serie, string $folio, string $descripcion): string
{
    $iva = number_format(max(0, (float) $total - (float) $subtotal), 2, '.', '');

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="' . $fecha . '"'
        . ' SubTotal="' . $subtotal . '" Total="' . $total . '" TipoDeComprobante="I" Serie="' . $serie . '" Folio="' . $folio . '">'
        . '<cfdi:Emisor Rfc="XAXX010101000" Nombre="EMISOR DE PRUEBA" RegimenFiscal="601"/>'
        . '<cfdi:Receptor Rfc="XAXX010101000" Nombre="RECEPTOR DE PRUEBA" RegimenFiscalReceptor="612" DomicilioFiscalReceptor="00000" UsoCFDI="G01"/>'
        . '<cfdi:Conceptos><cfdi:Concepto Cantidad="1" Unidad="pieza" Descripcion="' . $descripcion . '" ValorUnitario="' . $subtotal . '" Importe="' . $subtotal . '"/></cfdi:Conceptos>'
        . '<cfdi:Impuestos TotalImpuestosTrasladados="' . $iva . '"><cfdi:Traslados><cfdi:Traslado Impuesto="002" TipoFactor="Tasa" TasaCuota="0.160000" Importe="' . $iva . '"/></cfdi:Traslados></cfdi:Impuestos>'
        . '<tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" version="1.1"'
        . ' UUID="' . $uuid . '" FechaTimbrado="' . $fecha . '" RfcProvCertif="AAA010101AAA"/>'
        . '</cfdi:Comprobante>';
}
