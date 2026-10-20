<?php
/**
 * pruebas/php/etapa5.php — contabilidad, gastos, documentos, ZIP mensual y exportación.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa5.php
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

use App\{Archivos, ArchivoMensual, Contabilidad, Cotizaciones, Exportador, Gastos, Ventas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$stock = fn (int $id) => (int) $uno("SELECT stock FROM productos WHERE id = $id");
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$clientes = array_column($db->query('SELECT id FROM clientes LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'id');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');
$anio = (int) date('Y');
$mes = (int) date('n');

// ---- estado limpio ----------------------------------------------------------
foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) {
    $db->query("DELETE FROM $t");
}
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones');
$db->query('UPDATE productos SET stock = 100 WHERE id IN (36, 37)');

$L = fn (int $c, float $p = 100) => ['cantidad' => $c, 'precio' => $p, 'descuento_pct' => 0, 'no_recordar' => 1];
$venta = function (int $cliente, array $lineas, int $empresa = 1) use ($usuario, $hoy) {
    $ok = Ventas::crear(['empresa_id' => $empresa, 'cliente_id' => $cliente, 'fecha' => $hoy, 'aplica_iva' => 1, 'descuento_pct' => 0], $lineas, $usuario);
    return $ok ? Ventas::$ultimoId : 0;
};
$ingresoDe = fn (int $ventaId) => Contabilidad::buscarIngreso((int) $uno("SELECT id FROM ingresos WHERE venta_id = $ventaId"));

echo "Ingresos automáticos desde ventas\n";
$vA = $venta((int) $clientes[0], [37 => $L(2)]);
$prueba('venta directa genera su ingreso', (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $vA") === 1);
$iA = $ingresoDe($vA);
$prueba('importes: subtotal 200, IVA 32, total 232', $iA['subtotal'] === '200.00' && $iA['iva'] === '32.00' && $iA['total'] === '232.00', json_encode([$iA['subtotal'], $iA['iva'], $iA['total']]));
$prueba('nace «sin clasificar» (no se asume no facturada)', $iA['estado_facturacion'] === 'sin_clasificar');
$prueba('lleva el negocio de origen y el contribuyente', (int) $iA['empresa_id'] === 1 && (int) $iA['contribuyente_id'] > 0);

$db->query("INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, iva, total)
            VALUES ('FOL-E5-0001', 2, {$clientes[1]}, $usuario, '$hoy', 'pendiente', 100, 16, 116)");
$cot = (int) $db->insert_id;
$db->query("INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, cantidad, precio_unitario, subtotal) VALUES ($cot, 36, 1, 100, 100)");
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$vC = (int) $uno("SELECT id FROM ventas WHERE cotizacion_id = $cot");
$prueba('cotización vendida (2 veces) → 1 solo ingreso', (int) $uno("SELECT COUNT(*) FROM ingresos WHERE venta_id = $vC") === 1
    && (int) $uno('SELECT COUNT(*) FROM ingresos') === 2);

$ok = Ventas::actualizar(['id' => $vA, 'empresa_id' => 1, 'cliente_id' => $clientes[0], 'fecha' => $hoy, 'aplica_iva' => 1, 'estado' => 'registrada', 'descuento_pct' => 0],
    [37 => $L(3)], $usuario);
$prueba('editar la venta actualiza el ingreso (348)', $ok && $ingresoDe($vA)['total'] === '348.00', implode('|', Ventas::getErrores()));
$prueba('el cambio quedó en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'ingreso' AND entidad_id = {$iA['id']} AND accion = 'actualizar'") === 1);
Cotizaciones::cambiarEstado($cot, 'pendiente', $usuario);
$prueba('quitar la cotización de vendido cancela el ingreso (no lo borra)', $ingresoDe($vC)['estado'] === 'cancelado');
Cotizaciones::cambiarEstado($cot, 'vendido', $usuario);
$prueba('al revender se reactiva el mismo ingreso', $ingresoDe($vC)['estado'] === 'vigente' && (int) $uno('SELECT COUNT(*) FROM ingresos') === 2);

echo "Periodo cerrado: sin modificaciones silenciosas\n";
$contrib = (int) $iA['contribuyente_id'];
$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES ($contrib, $anio, $mes, 'cerrado', NOW())");
$s37 = $stock(37);
$ok = Ventas::actualizar(['id' => $vA, 'empresa_id' => 1, 'cliente_id' => $clientes[0], 'fecha' => $hoy, 'aplica_iva' => 1, 'estado' => 'registrada', 'descuento_pct' => 0],
    [37 => $L(9)], $usuario);
$prueba('editar una venta de un mes cerrado se rechaza', !$ok && str_contains(implode(' ', Ventas::getErrores()), 'cerrado'));
$prueba('→ y no deja inventario ni importes a medias', $stock(37) === $s37 && $ingresoDe($vA)['total'] === '348.00');
$prueba('cobro en periodo cerrado se rechaza', !Contabilidad::registrarCobro((int) $iA['id'], $hoy, 10, 'Efectivo', '', $usuario));
Contabilidad::getErrores();
$prueba('reclasificar facturación en periodo cerrado se rechaza', !Contabilidad::actualizarFacturacion((int) $iA['id'], 'no_facturada', '', '', '', $usuario));
Contabilidad::getErrores();
$prueba('gasto en periodo cerrado se rechaza', !Gastos::crear(['fecha' => $hoy, 'beneficiario' => 'X', 'concepto' => 'Y', 'subtotal' => '100', 'iva_tasa' => '16'], $usuario));
Gastos::getErrores();
$db->query("UPDATE periodos_contables SET estado = 'abierto' WHERE contribuyente_id = $contrib");

echo "Facturación explícita\n";
$uuid = '11111111-2222-3333-4444-555555555555';
$prueba('marcar facturada con UUID', Contabilidad::actualizarFacturacion((int) $iA['id'], 'facturada', 'A-100', $uuid, $hoy, $usuario, true), implode('|', Contabilidad::getErrores()));
$iC = $ingresoDe($vC);
$prueba('el mismo UUID en otra venta se rechaza', !Contabilidad::actualizarFacturacion((int) $iC['id'], 'facturada', 'A-101', $uuid, $hoy, $usuario, true));
Contabilidad::getErrores();
$prueba('UUID mal formado se rechaza', !Contabilidad::actualizarFacturacion((int) $iC['id'], 'facturada', '', 'abc', $hoy, $usuario, true));
Contabilidad::getErrores();
$prueba('UUID con «no facturada» se rechaza', !Contabilidad::actualizarFacturacion((int) $iC['id'], 'no_facturada', '', $uuid, '', $usuario));
Contabilidad::getErrores();
$prueba('marcar la otra como no facturada', Contabilidad::actualizarFacturacion((int) $iC['id'], 'no_facturada', '', '', '', $usuario));

echo "Cobros\n";
$id = (int) $iA['id'];
$prueba('cobro parcial 100', Contabilidad::registrarCobro($id, $hoy, 100, 'Transferencia', 'REF1', $usuario));
$i = Contabilidad::buscarIngreso($id);
$prueba('estado parcial, saldo 248', $i['estado_cobro'] === 'parcial' && $i['saldo'] === 248.0 && $i['cobrado'] === 100.0, json_encode([$i['estado_cobro'], $i['saldo']]));
$prueba('no se cobra más que el saldo', !Contabilidad::registrarCobro($id, $hoy, 300, 'Efectivo', '', $usuario));
Contabilidad::getErrores();
$prueba('cobro del resto → cobrada', Contabilidad::registrarCobro($id, $hoy, 248, 'Efectivo', '', $usuario) && Contabilidad::buscarIngreso($id)['estado_cobro'] === 'cobrado');
$cobro = (int) $uno("SELECT id FROM cobros WHERE ingreso_id = $id ORDER BY id LIMIT 1");
$prueba('cancelar cobro exige motivo', !Contabilidad::cancelarCobro($cobro, '', $usuario));
Contabilidad::getErrores();
$prueba('cancelar cobro con motivo (no se borra)', Contabilidad::cancelarCobro($cobro, 'Error de captura', $usuario)
    && Contabilidad::buscarIngreso($id)['estado_cobro'] === 'parcial' && (int) $uno("SELECT COUNT(*) FROM cobros WHERE ingreso_id = $id") === 2);

echo "Gastos\n";
$g = Gastos::calcular('33.33', 16);
$prueba('IVA redondeado a centavos (33.33 → 5.33, total 38.66)', $g['iva'] === '5.33' && $g['total'] === '38.66', json_encode($g));
$ok = Gastos::crear(['fecha' => $hoy, 'beneficiario' => 'Agustín', 'concepto' => 'Renta de bodega', 'subtotal' => '1000', 'iva_tasa' => '16',
    'compartido' => 1, 'reparto' => [1 => '60', 2 => '40'], 'naturaleza' => 'servicio', 'comprobante_fiscal' => 1], $usuario);
$prueba('gasto compartido 60/40', $ok, implode('|', Gastos::getErrores()));
$gId = Gastos::$ultimoId;
$gasto = Gastos::buscarID($gId);
$prueba('total 1,160 y revisión fiscal «por validar» (no se asume deducible)', $gasto['total'] === '1160.00' && $gasto['revision_fiscal'] === 'por_validar');
$prueba('el reparto no suma 100 → se rechaza', !Gastos::crear(['fecha' => $hoy, 'beneficiario' => 'X', 'concepto' => 'Y', 'subtotal' => '100', 'compartido' => 1, 'reparto' => [1 => '60', 2 => '30']], $usuario));
Gastos::getErrores();
Gastos::crear(['fecha' => $hoy, 'beneficiario' => 'Gasolinera', 'concepto' => 'Gasolina', 'subtotal' => '500', 'iva_tasa' => '16', 'empresa_id' => 1,
    'estado_pago' => 'pagado', 'fecha_pago' => $hoy], $usuario);
$r = Contabilidad::resumenPeriodo($anio, $mes);
$prueba('total de gastos cuenta el compartido UNA vez (1,160 + 580)', $r['gastos']['total'] === 174000, (string) $r['gastos']['total']);
$suma = 0; foreach ($r['por_negocio'] as $n) { $suma += $n['gastos'] + $n['iva_gastos']; }
$prueba('el reparto por negocio suma el mismo total (sin duplicar)', abs($suma - 1740.0) < 0.001, (string) $suma);
$r1 = Contabilidad::resumenPeriodo($anio, $mes, 1);
$prueba('filtrando negocio 1: 60 % de 1,160 + 580 = 1,276', $r1['gastos']['total'] === 127600, (string) $r1['gastos']['total']);
$prueba('ventas del periodo: 348 + 116 = 464 con IVA', abs($r['ventas']['total'] - 464.0) < 0.001, (string) $r['ventas']['total']);
$prueba('subtotal de ventas 400, IVA 64', $r['ventas']['subtotal'] === 400.0 && $r['ventas']['iva'] === 64.0);
$prueba('cobrado en el periodo 248 (el otro cobro se canceló)', $r['cobrado_en_periodo'] === 248.0, (string) $r['cobrado_en_periodo']);
$prueba('cancelar un gasto exige motivo', !Gastos::cancelar($gId, '', $usuario));
Gastos::getErrores();
$prueba('cancelar con motivo lo excluye del resumen', Gastos::cancelar($gId, 'Captura duplicada', $usuario)
    && Contabilidad::resumenPeriodo($anio, $mes)['gastos']['total'] === 58000);
$prueba('el gasto cancelado sigue en la base', (int) $uno("SELECT COUNT(*) FROM gastos WHERE id = $gId") === 1);

echo "Documentos: validación y seguridad\n";
$carpeta = sys_get_temp_dir() . '/e5-' . bin2hex(random_bytes(4));
mkdir($carpeta);
$archivo = function (string $nombre, string $contenido) use ($carpeta): array {
    $ruta = $carpeta . '/' . bin2hex(random_bytes(3));
    file_put_contents($ruta, $contenido);
    return ['name' => $nombre, 'tmp_name' => $ruta, 'size' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
};
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$im = imagecreatetruecolor(8, 8);
ob_start(); imagepng($im); $png = ob_get_clean();
ob_start(); imagejpeg($im); $jpg = ob_get_clean();
ob_start(); imagewebp($im); $webp = ob_get_clean();
$cfdi = fn (string $uuid, string $emisor, string $receptor, string $total) => '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="' . $hoy . 'T10:00:00" SubTotal="300.00" Total="' . $total . '" Moneda="MXN" TipoDeComprobante="I">'
    . '<cfdi:Emisor Rfc="' . $emisor . '" Nombre="Emisor"/><cfdi:Receptor Rfc="' . $receptor . '" Nombre="Receptor"/>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$rfc = Contabilidad::contribuyente($contrib)['rfc'];
$subir = fn (string $ent, int $eid, array $a, string $tipo = 'otro') => Archivos::subir($ent, $eid, $a, $tipo, $usuario, null, '', false);

$prueba('PDF válido', $subir('ingreso', $id, $archivo('Factura A-100.pdf', $pdf), 'factura_pdf'), implode('|', Archivos::getErrores()));
$docPdf = Archivos::buscarID(Archivos::$ultimoId);
$prueba('el PDF NO cambia el estado de facturación', Contabilidad::buscarIngreso($id)['estado_facturacion'] === 'facturada'
    && $ingresoDe($vC)['estado_facturacion'] === 'no_facturada');
$prueba('un PDF adjunto a una venta sin clasificar no la vuelve facturada', $subir('ingreso', (int) $iC['id'], $archivo('x.png', $png), 'imagen')
    && Contabilidad::buscarIngreso((int) $iC['id'])['estado_facturacion'] === 'no_facturada');
$prueba('JPG y WebP válidos', $subir('gasto', (int) $uno("SELECT id FROM gastos WHERE concepto='Gasolina'"), $archivo('t.jpg', $jpg), 'ticket')
    && $subir('gasto', (int) $uno("SELECT id FROM gastos WHERE concepto='Gasolina'"), $archivo('t.webp', $webp), 'ticket'));
$prueba('archivo guardado fuera de public/, con nombre aleatorio', str_starts_with(Archivos::rutaAbsoluta($docPdf), realpath($almacen))
    && !str_contains($docPdf['archivo'], 'Factura') && !str_contains(Archivos::rutaAbsoluta($docPdf), '/public/'));
$prueba('estructura contribuyente/año/mes/ventas', (bool) preg_match('#^\d+/' . $anio . '/' . sprintf('%02d', $mes) . '/ventas/[a-f0-9]{32}\.pdf$#', $docPdf['archivo']), $docPdf['archivo']);

$malos = [
    '.php con código' => ['shell.php', '<?php system($_GET["c"]); ?>'],
    '.php renombrado .pdf' => ['shell.pdf', '<?php system($_GET["c"]); ?>'],
    'imagen falsa' => ['foto.png', 'esto no es una imagen'],
    '.svg con script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    '.html' => ['pagina.html', '<html><script>alert(1)</script></html>'],
    '.exe' => ['virus.exe', "MZ\x90\x00"],
    'PDF con JavaScript' => ['malo.pdf', "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF"],
    'XML con DOCTYPE/entidad' => ['x.xml', '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>'],
    'XML que no es CFDI' => ['y.xml', '<?xml version="1.0"?><nota>hola</nota>'],
    'XML mal formado' => ['z.xml', '<?xml version="1.0"?><a><b></a>'],
    'archivo vacío' => ['v.pdf', ''],
];
foreach ($malos as $que => [$nombre, $contenido]) {
    $a = $archivo($nombre, $contenido);
    $rechazado = !$subir('ingreso', $id, $a);
    Archivos::getErrores();
    $prueba("rechaza: $que", $rechazado);
}
putenv('DOCUMENTOS_MAX_MB=1');
$prueba('rechaza archivo mayor al máximo (2 MB con tope de 1 MB)', !$subir('ingreso', $id, $archivo('grande.pdf', $pdf . str_repeat('A', 2 * 1048576))));
Archivos::getErrores();
putenv('DOCUMENTOS_MAX_MB=10');
$prueba('rechaza duplicado exacto en la misma operación', !$subir('ingreso', $id, $archivo('otra-copia.pdf', $pdf), 'factura_pdf')
    && str_contains(implode(' ', Archivos::getErrores()), 'ya está adjunto'));

echo "CFDI XML\n";
$xml = $cfdi($uuid, $rfc, 'XAXX010101000', '348.00');
$prueba('XML CFDI válido se adjunta', $subir('ingreso', $id, $archivo('cfdi.xml', $xml)), implode('|', Archivos::getErrores()));
$d = Archivos::buscarID(Archivos::$ultimoId);
$prueba('lee el UUID y queda «cfdi con UUID (sin verificar SAT)»', $d['uuid_fiscal'] === $uuid && $d['estado_validacion'] === 'cfdi_con_timbre' && $d['tipo'] === 'xml');
$prueba('RFC y total coinciden: sin alertas', ($d['cfdi']['alertas'] ?? ['x']) === []);
$prueba('el mismo UUID en OTRA operación se rechaza', !$subir('ingreso', (int) $iC['id'], $archivo('cfdi2.xml', $xml)));
Archivos::getErrores();
$desalineado = $cfdi('AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE', 'AAA010101AAA', 'XAXX010101000', '999.00');
$subir('ingreso', (int) $iC['id'], $archivo('otro.xml', $desalineado));
$d2 = Archivos::buscarID(Archivos::$ultimoId);
$prueba('alerta si el RFC del emisor o el total no coinciden', count($d2['cfdi']['alertas'] ?? []) === 2, json_encode($d2['cfdi']['alertas'] ?? []));
$prueba('un XML sin timbre queda «CFDI incompleto»', $subir('ingreso', (int) $iC['id'], $archivo('sin.xml', str_replace('UUID=', 'Otro=', $cfdi('BBBBBBBB-BBBB-CCCC-DDDD-EEEEEEEEEEEE', $rfc, 'X', '348.00'))))
    && Archivos::buscarID(Archivos::$ultimoId)['estado_validacion'] === 'cfdi_sin_timbre');

echo "Sustituir, baja y descarga segura\n";
$pdf2 = $pdf . "% versión 2\n";
$prueba('sustituir conserva el anterior como inactivo', Archivos::subir('ingreso', $id, $archivo('v2.pdf', $pdf2), 'factura_pdf', $usuario, (int) $docPdf['id'], 'Versión corregida', false)
    && (int) Archivos::buscarID((int) $docPdf['id'])['activo'] === 0 && (int) Archivos::buscarID(Archivos::$ultimoId)['reemplaza_id'] === (int) $docPdf['id']);
$prueba('el archivo anterior sigue en disco', Archivos::rutaAbsoluta($docPdf) !== null);
$prueba('baja exige motivo', !Archivos::baja(Archivos::$ultimoId, '', $usuario));
Archivos::getErrores();
$prueba('baja con motivo', Archivos::baja(Archivos::$ultimoId, 'Documento equivocado', $usuario));
$trampa = $docPdf; $trampa['archivo'] = '../../../../etc/passwd';
$prueba('recorrido de directorios (../) no sale del almacén', Archivos::rutaAbsoluta($trampa) === null);
$trampa['archivo'] = str_replace(substr($docPdf['archivo'], 0, strrpos($docPdf['archivo'], '/')), '../..', $docPdf['archivo']);
$prueba('ruta fuera del almacén se rechaza', Archivos::rutaAbsoluta($trampa) === null);

$subir('ingreso', $id, $archivo('v3.pdf', $pdf . "% v3\n"), 'factura_pdf');
echo "Archivo mensual (ZIP) y exportación\n";
$zipV = ArchivoMensual::construir('ventas', $anio, $mes);
$za = new ZipArchive(); $za->open($zipV['ruta']);
$nombres = []; for ($k = 0; $k < $za->numFiles; $k++) { $nombres[] = $za->getNameIndex($k); }
$prueba('ZIP de ventas con carpetas XML y Ventas facturadas', (bool) array_filter($nombres, fn ($n) => str_starts_with($n, 'XML/'))
    && (bool) array_filter($nombres, fn ($n) => str_starts_with($n, 'Ventas facturadas/')), implode(', ', $nombres));
$prueba('ZIP de ventas con no facturadas y su índice', (bool) array_filter($nombres, fn ($n) => str_starts_with($n, 'Ventas no facturadas/')) && in_array('indice.csv', $nombres, true));
$prueba('nombres únicos (sin sobrescribir)', count($nombres) === count(array_unique($nombres)));
$indice = $za->getFromName('indice.csv');
$prueba('el índice trae folio, cliente y tipo de documento', str_contains($indice, 'Folio de venta') && str_contains($indice, 'VEN-') && str_contains($indice, 'CFDI (XML)'));
$prueba('el índice excluye documentos dados de baja', !str_contains($indice, 'Documento equivocado'));
$za->close(); unlink($zipV['ruta']);
$zipG = ArchivoMensual::construir('gastos', $anio, $mes);
$za = new ZipArchive(); $za->open($zipG['ruta']);
$prueba('ZIP de gastos con «Pendientes de clasificacion» o «Gastos y compras»', $za->numFiles >= 3, (string) $za->numFiles);
$za->close(); unlink($zipG['ruta']);
$antes = (int) $uno('SELECT COUNT(*) FROM documentos');
ArchivoMensual::construir('ventas', $anio, $mes);
$prueba('generar el ZIP no modifica documentos', (int) $uno('SELECT COUNT(*) FROM documentos') === $antes);

$sf = Contabilidad::sinFacturar($anio, $mes);
$prueba('ventas sin facturar: solo la no facturada, una vez', $sf['totales']['operaciones'] === 1 && $sf['filas'][0]['folio'] === $iC['folio'], json_encode($sf['totales']));
$prueba('subtotal 100, IVA 16, total 116 por negocio 2', $sf['totales']['subtotal'] === 100.0 && $sf['totales']['iva'] === 16.0 && $sf['por_negocio'][0]['nombre'] !== '');
$csv = Exportador::csv(['Folio', 'Nota'], [['V1', '=HYPERLINK("http://malo")'], ['V2', 'ok']]);
$prueba('CSV con BOM y fórmulas neutralizadas', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=HYPERLINK") && !str_contains($csv, ",=HYPERLINK"));
$xlsx = Exportador::xlsx('Prueba', ['A', 'B'], [['x', 12.5], ['y', 3]]);
$tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, $xlsx);
$z = new ZipArchive();
$prueba('XLSX es un ZIP válido con la hoja', $z->open($tmp) === true && str_contains((string) $z->getFromName('xl/worksheets/sheet1.xml'), '<v>12.5</v>'));
$z->close(); unlink($tmp);

// limpieza del almacén temporal
$limpiar = function (string $d) use (&$limpiar) {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $limpiar($f) : unlink($f); }
    @rmdir($d);
};
$limpiar($almacen); $limpiar($carpeta);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
