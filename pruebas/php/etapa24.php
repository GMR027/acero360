<?php
/**
 * pruebas/php/etapa24.php — Registro inteligente: motor documental y cruce contra la plataforma.
 *
 * Comprueba lo que comparten los botones «Registro Venta Inteligente» y «Registro compra inteligente»:
 *   · el motor lee descuento y código de producto (NoIdentificacion) del CFDI;
 *   · lee los renglones de una tabla dentro de un PDF con texto;
 *   · nombra bien al emisor y al receptor (la etiqueta larga manda sobre la corta);
 *   · coteja productos por SKU, por nombre exacto y por nombre parecido;
 *   · deja en «sin equivalente» lo que no coincide, sin tocar el catálogo;
 *   · deduce la tasa de IVA y avisa cuando el folio fiscal ya está registrado;
 *   · el aviso de duplicado exige folio fiscal, folio + (fecha o importe) o
 *     importe + (fecha o RFC): la fecha sola no basta y un registro que ya trae
 *     otro folio fiscal se descarta aunque coincida lo demás.
 *
 * Los RFC y los nombres llevan el «24» para no chocar con los de las otras etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa24.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CoincidenciasFactura;
use App\LectorDocumental;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

/* Datos de prueba propios: se borran al final, en orden inverso a las llaves foráneas. */
$usuario = (int) $uno('SELECT IFNULL(MAX(id), 1) FROM usuarios');
$hoy = date('Y-m-d');
$_SESSION['id'] = $usuario;

$db->query("INSERT INTO empresas (nombre, rfc, activa) VALUES ('Negocio de la etapa 24', 'ZZZ990101AAA', 1)");
$empresaId = (int) $db->insert_id;

$productos = [];
foreach ([
    'Tornillo de pruba ciento catorce'    => ['P24-1', 360.00],
    'Placa de referencia ciento catorce'  => ['P24-2', 650.00],
    'Soporte de pruba ciento catorce'     => ['', 390.00],
] as $nombre => [$sku, $precio]) {
    $sqlProducto = 'INSERT INTO productos (empresa_id, nombre, sku, unidad, precio, activo) VALUES ('
        . $empresaId . ", '" . $nombre . "', '" . $sku . "', 'pza', " . $precio . ', 1)';
    $db->query($sqlProducto);
    $productos[$nombre] = (int) $db->insert_id;
}

$db->query("INSERT INTO clientes (nombre, rfc) VALUES ('Cliente de la etapa 24', 'ZZY880101AAA')");
$clienteId = (int) $db->insert_id;
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor) VALUES ('Ferretería de la etapa 24', 'ZZX770101AA1', 'Material')");
$proveedorId = (int) $db->insert_id;

$tmp = sys_get_temp_dir() . '/e24_' . bin2hex(random_bytes(4));
mkdir($tmp);

$uuidVenta = 'A1B2C3D4-0000-4000-8000-111122223333';

/** CFDI de venta: el emisor soy yo, el receptor es el cliente, y un renglón trae código de producto. */
$cfdi = '<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Serie="A" Folio="9"
  Fecha="2026-10-15T10:00:00" SubTotal="1370.00" Descuento="0.00" Total="1589.20" Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="I">
  <cfdi:Emisor Rfc="ZZZ990101AAA" Nombre="Negocio de la etapa 24"/>
  <cfdi:Receptor Rfc="ZZY880101AAA" Nombre="Cliente de la etapa 24"/>
  <cfdi:Conceptos>
    <cfdi:Concepto NoIdentificacion="P24-1" Cantidad="2" Descripcion="Pieza de catalogo" ValorUnitario="360.00" Importe="720.00"/>
    <cfdi:Concepto Cantidad="1" Descripcion="Placa de referencia ciento catorce alfa" ValorUnitario="650.00" Importe="650.00"/>
  </cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="219.20"><cfdi:Traslados><cfdi:Traslado Impuesto="002" Importe="219.20"/></cfdi:Traslados></cfdi:Impuestos>
  <cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuidVenta . '"/></cfdi:Complemento></cfdi:Comprobante>';
file_put_contents("$tmp/venta.xml", $cfdi);

echo "Motor documental: XML\n";
$lectura = LectorDocumental::leer("$tmp/venta.xml", 'xml');
$prueba('el XML se lee como fuente estructurada', $lectura['fuente'] === 'xml');
$prueba('trae el descuento', ($lectura['campos']['descuento']['valor'] ?? '') === '0.00');
$prueba('cada concepto trae su código (NoIdentificacion)', ($lectura['conceptos'][0]['sku'] ?? '') === 'P24-1');
$prueba('emisor y receptor no se confunden con su RFC', $lectura['campos']['emisor_nombre']['valor'] === 'Negocio de la etapa 24' && $lectura['campos']['receptor_nombre']['valor'] === 'Cliente de la etapa 24');

echo "\nMotor documental: PDF con texto\n";
$textoPdf = <<<TEXTO
        FACTURA
        Folio: F-44
        Fecha: 12/10/2026
        RFC Emisor: ZZX770101AA1
        Nombre del emisor: Ferreteria de la etapa 24
        RFC Receptor: ZZY880101AAA
        Nombre del receptor: Cliente de la etapa 24
                                     Descripcion        Cant      Precio     Importe
        Placa de referencia ciento catorce            1        650.00      650.00
        Tornillo hexagonal 3/8                      200          2.50      500.00
        Descuento: 50.00
        Subtotal: 1150.00
        IVA 16%: 176.00
        Total a pagar: $1,276.00
        Forma de pago: 03
        Metodo de pago: PUE
TEXTO;
$campos = LectorDocumental::analizarTexto($textoPdf, 'pdf_texto');
$prueba('el nombre del emisor manda sobre la etiqueta corta «RFC Emisor»', $campos['emisor_nombre']['valor'] === 'Ferreteria de la etapa 24', $campos['emisor_nombre']['valor']);
$prueba('el nombre del receptor se lee bien', $campos['receptor_nombre']['valor'] === 'Cliente de la etapa 24', $campos['receptor_nombre']['valor']);
$prueba('lee el descuento del PDF', $campos['descuento']['valor'] === '50.00', $campos['descuento']['valor']);
$prueba('los importes cuadran (subtotal − descuento + IVA = total)', $campos['subtotal']['valor'] === '1150.00' && $campos['total']['valor'] === '1276.00');
$ren = LectorDocumental::conceptosDeTexto($textoPdf);
$prueba('lee los renglones de la tabla del PDF', count($ren) === 2, (string) count($ren));
$prueba('el renglón cuadra solo (cantidad × precio = importe)', ($ren[0]['confianza'] ?? '') === 'alta' && ($ren[0]['cantidad'] ?? '') === '1.00');
$prueba('separan bien descripción, precio e importe', ($ren[1]['descripcion'] ?? '') === 'Tornillo hexagonal 3/8' && ($ren[1]['valor_unitario'] ?? '') === '2.50' && ($ren[1]['importe'] ?? '') === '500.00');

echo "\nCruce contra la plataforma: venta\n";
$propuesta = CoincidenciasFactura::analizar($lectura, 'venta');
$prueba('reconoce el negocio propio por RFC', $propuesta['empresa']['id'] === $empresaId, json_encode($propuesta['empresa']));
$prueba('reconoce al cliente receptor', $propuesta['cliente']['id'] === $clienteId, json_encode($propuesta['cliente']));
$prueba('coteja el producto por SKU', $propuesta['conceptos'][0]['producto']['id'] === $productos['Tornillo de pruba ciento catorce'], $propuesta['conceptos'][0]['criterio']);
$prueba('coteja el producto por nombre parecido', $propuesta['conceptos'][1]['producto']['id'] === $productos['Placa de referencia ciento catorce'], $propuesta['conceptos'][1]['criterio']);
$prueba('las líneas van en el formato del cotizador', isset($propuesta['lineas'][0]['precio_lista'], $propuesta['lineas'][0]['cantidad'], $propuesta['lineas'][0]['id']));
$prueba('ningún concepto quedó sin equivalente', $propuesta['sin_equivalente'] === []);
$prueba('deduce la tasa de IVA del documento', $propuesta['iva_tasa'] === 16.0, (string) $propuesta['iva_tasa']);
$prueba('el folio fiscal queda limpio', $propuesta['uuid'] === $uuidVenta);

echo "\nCruce contra la plataforma: compra\n";
$compra = str_replace(
    ['Rfc="ZZZ990101AAA" Nombre="Negocio de la etapa 24"', 'Rfc="ZZY880101AAA" Nombre="Cliente de la etapa 24"'],
    ['Rfc="ZZX770101AA1" Nombre="Ferreteria de la etapa 24"', 'Rfc="ZZZ990101AAA" Nombre="Negocio de la etapa 24"'],
    $cfdi
);
file_put_contents("$tmp/compra.xml", $compra);
$propuestaGasto = CoincidenciasFactura::analizar(LectorDocumental::leer("$tmp/compra.xml", 'xml'), 'gasto');
$prueba('en la compra, el emisor es el proveedor', $propuestaGasto['proveedor']['id'] === $proveedorId, json_encode($propuestaGasto['proveedor']));
$prueba('sugiere una categoría de gasto', $propuestaGasto['categoria'] !== null && $propuestaGasto['categoria']['id'] > 0, json_encode($propuestaGasto['categoria']));
$prueba('el negocio propio es el receptor', $propuestaGasto['empresa']['id'] === $empresaId);

echo "\nSin equivalente: no se toca el catálogo\n";
$sinProducto = str_replace('Placa de referencia ciento catorce alfa', 'Martillo neumatico demolador', $compra);
file_put_contents("$tmp/sin.xml", $sinProducto);
$antes = (int) $uno('SELECT COUNT(*) FROM productos');
$solo = CoincidenciasFactura::analizar(LectorDocumental::leer("$tmp/sin.xml", 'xml'), 'gasto');
$prueba('el concepto desconocido queda en «sin equivalente»', count($solo['sin_equivalente']) === 1 && $solo['sin_equivalente'][0]['descripcion'] === 'Martillo neumatico demolador');
$prueba('y no genera ningún producto nuevo', (int) $uno('SELECT COUNT(*) FROM productos') === $antes);

echo "\nDuplicados por folio fiscal\n";
$contribuyente = (int) $uno('SELECT IFNULL(MIN(id), 0) FROM contribuyentes');
// Folio y RFC del CFDI además del UUID: los otros criterios de CoincidenciasFactura
// se cotejan contra estos datos (4321.19 es un importo que no comparte ningún otro).
$db->query("INSERT INTO gastos (contribuyente_id, fecha, concepto, beneficiario_tipo, beneficiario, naturaleza, subtotal, iva, total,
            estado_pago, estado, uuid_fiscal, folio_comercial, rfc_emisor, usuario_id)
            VALUES ($contribuyente, '$hoy', 'Gasto con factura', 'otro', 'Ferretería', 'gasto', 3725.16, 596.03, 4321.19,
            'pendiente', 'vigente', '" . $uuidVenta . "', 'Z9', 'ZZX770101AA1', $usuario)");
$gastoDuplicado = (int) $db->insert_id;
$prueba('avisa que el folio fiscal ya está en un gasto', CoincidenciasFactura::analizar($lectura, 'gasto')['duplicado'] !== null);

echo "\nDuplicados: qué NO lo es\n";
$duplicadosDe = static fn (array $datos): array => CoincidenciasFactura::duplicados(
    (string) ($datos['uuid'] ?? ''),
    (string) ($datos['folio'] ?? ''),
    (string) ($datos['fecha'] ?? ''),
    (string) ($datos['destino'] ?? 'gasto'),
    (string) ($datos['sha256'] ?? ''),
    (float) ($datos['total'] ?? 0.0),
    (string) ($datos['rfc'] ?? '')
);
$ver = static fn (array $x): string => json_encode(array_map(static fn (array $r): array => ['id' => $r['id'], 'criterio' => $r['criterio']], $x));

$prueba('la misma fecha con otro importe no es duplicado',
    $duplicadosDe(['fecha' => $hoy, 'total' => 99999.99]) === [], $ver($duplicadosDe(['fecha' => $hoy, 'total' => 99999.99])));
$prueba('el mismo importe en otra fecha y sin RFC no es duplicado',
    $duplicadosDe(['fecha' => '2019-07-07', 'total' => 4321.19]) === [], $ver($duplicadosDe(['fecha' => '2019-07-07', 'total' => 4321.19])));
$prueba('el mismo importe en otra fecha con OTRO RFC no es duplicado',
    $duplicadosDe(['fecha' => '2019-07-07', 'total' => 4321.19, 'rfc' => 'ZZZ990101AAA']) === [],
    $ver($duplicadosDe(['fecha' => '2019-07-07', 'total' => 4321.19, 'rfc' => 'ZZZ990101AAA'])));

$otraFecha = $duplicadosDe(['fecha' => '2019-07-07', 'total' => 4321.19, 'rfc' => 'ZZX770101AA1']);
$prueba('el mismo importe con el mismo RFC sí avisa aunque la fecha cambie',
    count($otraFecha) === 1 && $otraFecha[0]['criterio'] === 'mismo importe y RFC', $ver($otraFecha));

$mismoDiaFolio = $duplicadosDe(['folio' => 'Z9', 'fecha' => $hoy]);
$prueba('el mismo folio con el mismo día sí avisa',
    count($mismoDiaFolio) === 1 && $mismoDiaFolio[0]['criterio'] === 'folio de factura y misma fecha', $ver($mismoDiaFolio));
$prueba('el mismo folio con otra fecha y otro importe no avisa',
    $duplicadosDe(['folio' => 'Z9', 'fecha' => '2019-07-07', 'total' => 99999.99]) === [],
    $ver($duplicadosDe(['folio' => 'Z9', 'fecha' => '2019-07-07', 'total' => 99999.99])));

// Un registro que ya trae OTRO folio fiscal: son facturas distintas aunque
// coincidan folio de factura, fecha, importe y RFC.
$contradicho = $duplicadosDe([
    'uuid' => 'FFFF0000-0000-4000-8000-000000000000', 'folio' => 'Z9', 'fecha' => $hoy,
    'total' => 4321.19, 'rfc' => 'ZZX770101AA1',
]);
$prueba('si el registro ya trae otro folio fiscal se descarta, aunque coincida lo demás', $contradicho === [], $ver($contradicho));

echo "\nTasas de IVA\n";
// Un comprobante sin el nodo de impuestos: no trae IVA desglosado.
$ivaCero = str_replace('Total="1589.20"', 'Total="1370.00"', preg_replace('/\s*<cfdi:Impuestos.*?<\/cfdi:Impuestos>/s', '', $cfdi));
file_put_contents("$tmp/cero.xml", $ivaCero);
$prueba('un comprobante sin IVA queda en 0 %', CoincidenciasFactura::analizar(LectorDocumental::leer("$tmp/cero.xml", 'xml'), 'gasto')['iva_tasa'] === 0.0);

echo "\nTabla con el orden de columnas de un facturador en línea\n";
// Mismo formato que una factura real (ejemplo): otra disposición de columnas,
// conceptos que ocupan dos líneas, clave SAT, tipo de impuesto e impuesto por renglón.
$textoFacturador = <<<'TEXTO'
  Cantidad          Unidad                             Concepto                      Descuento            Impuesto           Precio Unitario          Importe

     15         H87 - Pieza       BASE PARA BOILER DE 40X40 CM.                         $292.50    IVA Trasladado                      $390.00          $5,850.00
                                  30102304 - Perfiles de acero
                                  02 - Sí objeto de impuesto.
     98         H87 - Pieza       REGISTRO REFORZADO.- 40X60 cm; MARCO.-              $1,764.00    IVA Trasladado                      $360.00         $35,280.00
                                  Ángulo de 1 1/4" y CONTRAMARCO.- Ángulo de 1"                    16.00% $5,362.56
                                  30102304 - Perfiles de acero
                                  02 - Sí objeto de impuesto.
     10         H87 - Pieza       REGISTRO DE SOLERA GRANDE.- 65X65 cm; En              $270.00    IVA Trasladado                      $540.00          $5,400.00
                                  solera de 2"                                                     16.00% $820.80
                                  30102304 - Perfiles de acero
                                  02 - Sí objeto de impuesto.
                                    Subtotal                   $58,930.00
TEXTO;
$tabla = LectorDocumental::conceptosDeTexto($textoFacturador, '/\s{2,}/u', 'alta');
$prueba('lee la tabla aunque el orden de columnas sea otro', count($tabla) === 3, (string) count($tabla));
$prueba('lee la cantidad de su columna', ($tabla[0]['cantidad'] ?? '') === '15.00' && ($tabla[1]['cantidad'] ?? '') === '98.00', $tabla[0]['cantidad'] ?? '(vacía)');
$prueba('lee el precio unitario de su columna', ($tabla[0]['valor_unitario'] ?? '') === '390.00', $tabla[0]['valor_unitario'] ?? '(vacío)');
$prueba('lee el descuento del renglón', ($tabla[0]['descuento'] ?? '') === '292.50', $tabla[0]['descuento'] ?? '(vacío)');
$prueba('une el concepto que ocupa dos líneas', str_contains($tabla[1]['descripcion'] ?? '', 'CONTRAMARCO'), $tabla[1]['descripcion'] ?? '');
$prueba('el impuesto de la derecha no entra en la descripción', !str_contains($tabla[1]['descripcion'] ?? '', '16.00%'));
$prueba('descarta la clave SAT y el tipo de objeto de impuesto', !str_contains($tabla[0]['descripcion'] ?? '', '30102304') && !str_contains($tabla[0]['descripcion'] ?? '', 'Sí objeto'));
$prueba('descarta la línea del subtotal', !array_filter($tabla, static fn (array $r): bool => stripos($r['descripcion'], 'subtotal') === 0));
$prueba('los importes cuadran con cantidad × precio', $tabla[0]['confianza'] === 'alta');

echo "\nCotejo: la descripción narrativa sí encuentra su producto\n";
$decir = static function (string $descripcion) use ($tmp): int {
    $xml = '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="2026-10-15T10:00:00" SubTotal="100.00" Total="116.00" TipoDeComprobante="I">'
        . '<cfdi:Emisor Rfc="ZZZ990101AAA" Nombre="Negocio de la etapa 24"/><cfdi:Receptor Rfc="ZZY880101AAA" Nombre="Cliente de la etapa 24"/>'
        . '<cfdi:Conceptos><cfdi:Concepto Cantidad="1" Descripcion="' . htmlspecialchars($descripcion, ENT_XML1) . '" ValorUnitario="100.00" Importe="100.00"/></cfdi:Conceptos>'
        . '</cfdi:Comprobante>';
    file_put_contents("$tmp/cotejo.xml", $xml);
    $p = CoincidenciasFactura::analizar(LectorDocumental::leer("$tmp/cotejo.xml", 'xml'), 'venta');

    return (int) ($p['lineas'][0]['id'] ?? 0);
};
$placaId = (int) $uno("SELECT id FROM productos WHERE sku = 'P24-2'");
$tornilloId = (int) $uno("SELECT id FROM productos WHERE sku = 'P24-1'");
$prueba('el concepto narrativo encuentra su producto', $placaId > 0 && $decir('Placa de referencia ciento catorce con marco') === $placaId, "id esperado=" . $placaId);
$prueba('el producto completo dentro del concepto pesa más que el parecido', $tornilloId > 0 && $decir('Tornillo de pruba ciento catorce para trabajo') === $tornilloId, "id esperado=" . $tornilloId);
$prueba('una sola palabra en común no alcanza con el umbral del 85 %', $decir('Tornillo') === 0);

/* Limpieza de los datos de esta corrida. */
$db->query("DELETE FROM gastos WHERE id = $gastoDuplicado");
foreach ($productos as $id) {
    $db->query('DELETE FROM productos WHERE id = ' . (int) $id);
}
$db->query("DELETE FROM clientes WHERE id = $clienteId");
$db->query("DELETE FROM proveedores WHERE id = $proveedorId");
$db->query("DELETE FROM empresas WHERE id = $empresaId");

echo $fallos ? "\n$fallos prueba(s) con fallo\n" : "\nTodas las pruebas de la etapa 24 pasaron\n";
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
exit($fallos ? 1 : 0);
