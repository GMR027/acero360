<?php
/**
 * pruebas/php/etapa13.php — Lectura inteligente de facturas: XML, PDF con texto, revisión obligatoria, duplicados y asociación.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa13.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Archivos, CfdiXml, LectorDocumental, Lecturas};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$_SESSION['id'] = $usuario;
$rfc = (string) $uno('SELECT rfc FROM contribuyentes ORDER BY id LIMIT 1');
$hoy = date('Y-m-d');

foreach (['lecturas_documentales', 'documentos', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables'] as $t) {
    $db->query("DELETE FROM $t");
}
$tmp = sys_get_temp_dir() . '/e13_' . bin2hex(random_bytes(4));
mkdir($tmp);
$uuid = '6128396F-C09B-4EC6-8699-43C5F7E3B230';

$xml = static fn (string $uuid, string $total = '116.00') => '<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Serie="A" Folio="123"
  Fecha="' . $GLOBALS['hoy'] . 'T10:00:00" SubTotal="100.00" Total="' . $total . '" Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="I">
  <cfdi:Emisor Rfc="AAA010101AAA" Nombre="Aceros del Norte SA"/><cfdi:Receptor Rfc="' . $GLOBALS['rfc'] . '" Nombre="Mi Taller"/>
  <cfdi:Conceptos><cfdi:Concepto Descripcion="Lámina cal. 18" Cantidad="2" ValorUnitario="50.00" Importe="100.00"/></cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="16.00"><cfdi:Traslados><cfdi:Traslado Impuesto="002" Importe="16.00"/></cfdi:Traslados></cfdi:Impuestos>
  <cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$archivo = fn (string $ruta, string $nombre) => ['name' => $nombre, 'tmp_name' => $ruta, 'size' => filesize($ruta), 'error' => 0];

echo "Extracción del XML\n";
$d = CfdiXml::leer($xml($uuid))['datos'];
$prueba('IVA, forma y método de pago, conceptos', $d['iva'] === '16.00' && $d['forma_pago'] === '03' && $d['metodo_pago'] === 'PUE' && count($d['conceptos']) === 1);
$prueba('folio comercial ≠ UUID', $d['folio_comercial'] === 'A123' && $d['uuid'] === $uuid && $d['folio_comercial'] !== $d['uuid']);

echo "Texto de PDF y OCR\n";
$texto = "FACTURA\nEmisor: Ferretería Sol SA de CV\nRFC emisor: BBB020202BBB\nRFC receptor: $rfc\nFolio: B-77\nFolio fiscal: $uuid\nFecha de emisión: 15/03/2026\nSubtotal \$1,000.00\nIVA 16% \$160.00\nTotal \$1,160.00\nMoneda: MXN\nForma de pago: 03 Transferencia\nMétodo de pago: PUE";
$c = LectorDocumental::analizarTexto($texto, 'pdf_texto');
$prueba('lee folio, UUID, fecha, RFC e importes', $c['folio_comercial']['valor'] === 'B-77' && $c['uuid']['valor'] === $uuid && $c['fecha']['valor'] === '2026-03-15'
    && $c['emisor_rfc']['valor'] === 'BBB020202BBB' && $c['subtotal']['valor'] === '1000.00' && $c['iva']['valor'] === '160.00' && $c['total']['valor'] === '1160.00', json_encode($c));
$prueba('folio comercial no se toma por el UUID', $c['folio_comercial']['valor'] !== $c['uuid']['valor']);
$prueba('en PDF solo el UUID es «alta»', $c['uuid']['confianza'] === 'alta' && $c['total']['confianza'] === 'media');
$o = LectorDocumental::analizarTexto($texto, 'ocr');
$prueba('en OCR nada es «alta»', !in_array('alta', array_column($o, 'confianza'), true));

echo "XML → lectura → confirmación\n";
file_put_contents("$tmp/f.xml", $xml($uuid));
$id = Lecturas::preparar([$archivo("$tmp/f.xml", 'factura.xml')], 'gasto', $usuario, false);
$prueba('prepara la lectura sin crear ningún gasto', $id !== null && (int) $uno('SELECT COUNT(*) FROM gastos') === 0, implode(' ', Lecturas::getErrores()));
$l = Lecturas::buscarID($id);
$prueba('fuente XML y confianza alta', $l['fuente'] === 'xml' && $l['leidos']['campos']['total']['confianza'] === 'alta');
$post = Lecturas::valoresIniciales($l) + ['beneficiario' => 'Aceros del Norte SA', 'concepto' => 'Lámina', 'modo' => 'nuevo'];
$ok = Lecturas::confirmar($id, $post, $usuario);
$prueba('confirma y crea UN gasto', $ok && (int) $uno('SELECT COUNT(*) FROM gastos') === 1, implode(' ', Lecturas::getErrores()));
$g = $db->query('SELECT * FROM gastos')->fetch_assoc();
$prueba('guarda UUID y folio comercial por separado', $g['uuid_fiscal'] === $uuid && $g['folio_comercial'] === 'A123');
$prueba('forma/método SAT, RFC y total', $g['forma_pago_sat'] === '03' && $g['metodo_pago_sat'] === 'PUE' && $g['rfc_emisor'] === 'AAA010101AAA' && $g['total'] === '116.00');
$prueba('queda el original adjunto', (int) $uno("SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = {$g['id']} AND activo = 1") === 1);
$l = Lecturas::buscarID($id);
$prueba('registra quién y cuándo confirmó', $l['estado'] === 'confirmada' && (int) $l['confirmado_por'] === $usuario && $l['confirmado_en'] !== null && (int) $l['entidad_id'] === (int) $g['id']);

echo "Duplicados\n";
$id2 = Lecturas::preparar([$archivo("$tmp/f.xml", 'factura-otra-vez.xml')], 'gasto', $usuario, false);
$l2 = Lecturas::buscarID($id2);
$prueba('detecta el mismo archivo/UUID', count(Lecturas::duplicados(Lecturas::valoresIniciales($l2), $l2)) >= 1);
$prueba('no se confirma un duplicado', !Lecturas::confirmar($id2, Lecturas::valoresIniciales($l2) + ['modo' => 'nuevo', 'beneficiario' => 'x', 'concepto' => 'x'], $usuario) && (int) $uno('SELECT COUNT(*) FROM gastos') === 1);

echo "PDF con texto: revisión obligatoria\n";
$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml('<pre>' . htmlspecialchars(str_replace(['BBB020202BBB', '15/03/2026'], ['CCC030303CCC', date('d/m/Y')], $texto)) . '</pre>');
$dompdf->render();
file_put_contents("$tmp/f.pdf", $dompdf->output());
// el UUID del PDF es otro para que no choque con el gasto ya creado
$id3 = Lecturas::preparar([$archivo("$tmp/f.pdf", 'factura.pdf')], 'gasto', $usuario, false);
$prueba('el PDF se lee como texto', $id3 !== null && Lecturas::buscarID($id3)['fuente'] === 'pdf_texto', implode(' ', Lecturas::getErrores()));
$db->query("UPDATE lecturas_documentales SET sha256 = SHA2('otro', 256) WHERE id = $id3");
$l3 = Lecturas::buscarID($id3);
$v3 = Lecturas::valoresIniciales($l3);
$v3['uuid'] = '11111111-2222-3333-4444-555555555555';
$base = $v3 + ['beneficiario' => 'Ferretería Sol', 'concepto' => 'Tornillos', 'modo' => 'nuevo'];
$prueba('sin marcar lo dudoso NO se confirma', !Lecturas::confirmar($id3, $base, $usuario) && (int) $uno('SELECT COUNT(*) FROM gastos') === 1);
$prueba('el mensaje pide revisar campos', str_contains(Lecturas::getErrores()[0] ?? '', 'revisados'));
$revisados = array_fill_keys(array_keys(LectorDocumental::CAMPOS), 1);
$ok = Lecturas::confirmar($id3, $base + ['revisado' => $revisados], $usuario);
$prueba('con la revisión marcada, confirma', $ok, implode(' ', Lecturas::getErrores()));

echo "Asociar a un registro existente (sin duplicar)\n";
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, subtotal, iva_tasa, iva, total, estado_pago, comprobante_fiscal, usuario_id)
            SELECT id, '$hoy', 'Proveedor X', 'Compra X', 200, 16, 32, 232, 'pendiente', 0, $usuario FROM contribuyentes LIMIT 1");
$existente = (int) $db->insert_id;
$uuidC = 'ABCDEF01-2345-6789-ABCD-EF0123456789';
file_put_contents("$tmp/c.xml", str_replace(['SubTotal="100.00"', 'Importe="16.00"', 'TotalImpuestosTrasladados="16.00"'], ['SubTotal="200.00"', 'Importe="32.00"', 'TotalImpuestosTrasladados="32.00"'], $xml($uuidC, '232.00')));
$id4 = Lecturas::preparar([$archivo("$tmp/c.xml", 'c.xml')], 'gasto', $usuario, false);
$l4 = Lecturas::buscarID($id4);
$v4 = Lecturas::valoresIniciales($l4);
$prueba('encuentra el gasto parecido por importe y fecha', in_array($existente, array_column(Lecturas::coincidencias($v4, 'gasto'), 'id')));
$antes = (int) $uno('SELECT COUNT(*) FROM gastos');
$prueba('crear uno nuevo exige confirmar que es distinto', !Lecturas::confirmar($id4, $v4 + ['modo' => 'nuevo', 'beneficiario' => 'x', 'concepto' => 'x'], $usuario));
$prueba('asociar al existente', Lecturas::confirmar($id4, $v4 + ['modo' => 'existente', 'objetivo_id' => $existente], $usuario), implode(' ', Lecturas::getErrores()));
$prueba('no crea gasto nuevo', (int) $uno('SELECT COUNT(*) FROM gastos') === $antes);
$g = $db->query("SELECT * FROM gastos WHERE id = $existente")->fetch_assoc();
$prueba('completa UUID y folio sin tocar el importe', $g['uuid_fiscal'] === $uuidC && $g['folio_comercial'] === 'A123' && $g['total'] === '232.00' && (int) $g['comprobante_fiscal'] === 1);

echo "Factura de una venta\n";
$db->query("INSERT INTO ingresos (venta_id, contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto, subtotal, iva, total, estado_facturacion)
            SELECT NULL, (SELECT MIN(id) FROM contribuyentes), 1, (SELECT MIN(id) FROM clientes), '$hoy', 'VEN-E13-1', 'Venta', 100, 16, 116, 'sin_clasificar'");
$ing = (int) $db->insert_id;
$uuidV = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
// para una venta el emisor es el contribuyente
file_put_contents("$tmp/v.xml", str_replace(['Rfc="AAA010101AAA"', 'Nombre="Aceros del Norte SA"/><cfdi:Receptor Rfc="' . $rfc . '"'], ['Rfc="' . $rfc . '"', 'Nombre="Aceros del Norte SA"/><cfdi:Receptor Rfc="XAXX010101000"'], $xml($uuidV)));
$id5 = Lecturas::preparar([$archivo("$tmp/v.xml", 'v.xml')], 'venta', $usuario, false);
$v5 = Lecturas::valoresIniciales(Lecturas::buscarID($id5));
$prueba('no se confirma sin elegir la venta', !Lecturas::confirmar($id5, $v5, $usuario));
$prueba('confirma contra la venta elegida', Lecturas::confirmar($id5, $v5 + ['objetivo_id' => $ing], $usuario), implode(' ', Lecturas::getErrores()));
$i = $db->query("SELECT * FROM ingresos WHERE id = $ing")->fetch_assoc();
$prueba('la venta queda facturada con folio y UUID separados', $i['estado_facturacion'] === 'facturada' && $i['uuid_fiscal'] === $uuidV && $i['factura_folio'] === 'A123');
$prueba('no se creó ninguna venta ni ingreso nuevo', (int) $uno('SELECT COUNT(*) FROM ingresos') === 1);

echo "Sin lectura posible: captura manual\n";
$img = imagecreatetruecolor(40, 40); imagepng($img, "$tmp/foto.png");
$id6 = Lecturas::preparar([$archivo("$tmp/foto.png", 'ticket.png')], 'gasto', $usuario, false);
$l6 = Lecturas::buscarID($id6);
$prueba('imagen sin texto legible → manual, campos vacíos', $l6['fuente'] === 'manual' && Lecturas::valoresIniciales($l6)['total'] === '' && $l6['avisos_lista'] !== []);
$v6 = ['fecha' => $hoy, 'total' => '58.00', 'moneda' => 'MXN'];
$prueba('se puede confirmar capturando a mano', Lecturas::confirmar($id6, $v6 + ['beneficiario' => 'Tienda', 'concepto' => 'Ticket', 'modo' => 'nuevo'], $usuario), implode(' ', Lecturas::getErrores()));
$prueba('descartar no guarda nada', Lecturas::descartar((int) Lecturas::preparar([$archivo("$tmp/foto.png", 'otra.png')], 'gasto', $usuario, false), $usuario));

echo $fallos ? "\n$fallos prueba(s) con fallo\n" : "\nTodas las pruebas de la etapa 13 pasaron\n";
array_map('unlink', glob("$tmp/*")); rmdir($tmp);
exit($fallos ? 1 : 0);
