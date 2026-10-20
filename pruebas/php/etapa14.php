<?php
/**
 * pruebas/php/etapa14.php — Registro de gastos: «Pagado a» (proveedor / estación / otro) y factura explícita.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa14.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Archivos, Gastos};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$hoy = date('Y-m-d');
$base = ['fecha' => $hoy, 'concepto' => 'Prueba', 'total' => '116', 'iva_tasa' => '16', 'estado_pago' => 'pagado'];

foreach (['documentos', 'gasto_reparto', 'gastos'] as $t) { $db->query("DELETE FROM $t"); }
$prov = $uno('SELECT id FROM proveedores LIMIT 1');
if (!$prov) { $db->query("INSERT INTO proveedores (nombre) VALUES ('Proveedor de prueba')"); $prov = $db->insert_id; }
$nombreProv = $uno("SELECT nombre FROM proveedores WHERE id = $prov");

echo "Pagado a\n";
$prueba('proveedor sin elegir uno → error', !Gastos::crear($base + ['beneficiario_tipo' => 'proveedor', 'comprobante_fiscal' => '0'], $usuario));
Gastos::getErrores();
$prueba('proveedor del directorio → guarda su nombre', Gastos::crear($base + ['beneficiario_tipo' => 'proveedor', 'proveedor_id' => $prov, 'comprobante_fiscal' => '0'], $usuario));
$g = Gastos::buscarID(Gastos::$ultimoId);
$prueba('  tipo proveedor y nombre copiado', $g['beneficiario_tipo'] === 'proveedor' && $g['beneficiario'] === $nombreProv && (int) $g['proveedor_id'] === (int) $prov);
$prueba('estación sin nombre → «Estación de servicio»', Gastos::crear($base + ['beneficiario_tipo' => 'estacion', 'comprobante_fiscal' => '0'], $usuario)
    && Gastos::buscarID(Gastos::$ultimoId)['beneficiario'] === 'Estación de servicio');
$prueba('otro con descripción, sin proveedor', Gastos::crear($base + ['beneficiario_tipo' => 'otro', 'beneficiario' => 'Señor de las piedras', 'proveedor_id' => $prov, 'comprobante_fiscal' => '0'], $usuario)
    && ($x = Gastos::buscarID(Gastos::$ultimoId)) && $x['proveedor_id'] === null && $x['beneficiario_tipo'] === 'otro');
$prueba('otro sin descripción → error', !Gastos::crear($base + ['beneficiario_tipo' => 'otro', 'comprobante_fiscal' => '0'], $usuario));
Gastos::getErrores();
$prueba('tipo inválido → error', !Gastos::crear($base + ['beneficiario_tipo' => 'xx', 'beneficiario' => 'A', 'comprobante_fiscal' => '0'], $usuario));
Gastos::getErrores();

echo "Factura explícita\n";
$prueba('factura sin responder → error', !Gastos::crear($base + ['beneficiario_tipo' => 'otro', 'beneficiario' => 'A', 'comprobante_fiscal' => ''], $usuario));
Gastos::getErrores();
$prueba('«sin factura» se guarda en 0', Gastos::crear($base + ['beneficiario_tipo' => 'otro', 'beneficiario' => 'A', 'comprobante_fiscal' => '0'], $usuario)
    && (int) Gastos::buscarID(Gastos::$ultimoId)['comprobante_fiscal'] === 0);
$prueba('«con factura» se guarda en 1, por validar', Gastos::crear($base + ['beneficiario_tipo' => 'otro', 'beneficiario' => 'B', 'comprobante_fiscal' => '1'], $usuario)
    && ($f = Gastos::buscarID(Gastos::$ultimoId)) && (int) $f['comprobante_fiscal'] === 1 && $f['revision_fiscal'] === 'por_validar');
$idFactura = Gastos::$ultimoId;

echo "Adjunto ≠ CFDI validado\n";
$st = Gastos::estadoFactura($f);
$prueba('con factura y sin archivos → falta adjuntarla', $st['clave'] === 'factura_pendiente', $st['etiqueta']);

$png = sys_get_temp_dir() . '/e13.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$ok = Archivos::subir('gasto', $idFactura, ['name' => 'ticket.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => 0], 'auto', $usuario, null, '', false);
$st = Gastos::estadoFactura(Gastos::buscarID($idFactura));
$prueba('imagen adjunta NO cuenta como CFDI', $ok && $st['clave'] === 'factura_pendiente' && (int) Gastos::buscarID($idFactura)['comprobante_fiscal'] === 1 && Gastos::buscarID($idFactura)['revision_fiscal'] === 'por_validar', $st['clave']);

$uuid = '12345678-ABCD-4321-9876-0123456789AB';
$xml = '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="' . $hoy . 'T10:00:00" SubTotal="100.00" Total="116.00">'
     . '<cfdi:Emisor Rfc="AAA010101AAA" Nombre="Emisor SA"/><cfdi:Receptor Rfc="BBB010101BBB"/><cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$rutaXml = sys_get_temp_dir() . '/e13.xml';
file_put_contents($rutaXml, $xml);
$ok = Archivos::subir('gasto', $idFactura, ['name' => 'f.xml', 'tmp_name' => $rutaXml, 'size' => filesize($rutaXml), 'error' => 0], 'auto', $usuario, null, '', false);
$st = Gastos::estadoFactura(Gastos::buscarID($idFactura));
$prueba('XML con UUID → CFDI leído (sin verificar SAT)', $ok && $st['clave'] === 'cfdi_leido', $st['clave'] . ' ' . implode('|', Archivos::getErrores()));
$prueba('  y aun así sigue «por validar» (no deducible por sí solo)', Gastos::buscarID($idFactura)['revision_fiscal'] === 'por_validar');

$sin = Gastos::buscarID($db->query("SELECT id FROM gastos WHERE comprobante_fiscal = 0 LIMIT 1")->fetch_row()[0]);
$prueba('sin factura → estado «Sin factura»', Gastos::estadoFactura($sin)['clave'] === 'sin_factura');

echo "Edición\n";
$prueba('editar sin tocar la factura la conserva', Gastos::actualizar($idFactura, ['fecha' => $hoy, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'B2', 'concepto' => 'Prueba', 'total' => '116', 'iva_tasa' => '16', 'estado_pago' => 'pagado'], $usuario)
    && (int) Gastos::buscarID($idFactura)['comprobante_fiscal'] === 1);

foreach ([$png, $rutaXml] as $a) { @unlink($a); }
echo $fallos ? "\n$fallos FALLA(S)\n" : "\nTODO OK\n";
exit($fallos ? 1 : 0);
