<?php
/**
 * pruebas/php/etapa45.php — Del «concepto sin producto equivalente» al catálogo
 * del proveedor que emitió la factura.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa45.php
 *
 * Comprueba:
 *   · ProductosProveedor::agregarDesdeFactura() da de alta el concepto con su
 *     SKU, su precio de la factura y como activo;
 *   · el duplicado NO es un error: se compara sin acentos ni mayúsculas (y por
 *     código) y devuelve «existente» con el id que ya estaba;
 *   · un proveedor desactivado no admite productos nuevos;
 *   · CoincidenciasFactura hace la 2ª PASADA: un concepto que no casó con el
 *     catálogo propio se coteja con el del proveedor reconocido y sale de
 *     «sin equivalente» con criterio «catálogo del proveedor»;
 *   · sin proveedor reconocido (o con un concepto que tampoco está en SU
 *     catálogo) el concepto sigue en «sin equivalente»;
 *   · la pasada tardía (cotejarCatalogoDeProveedor) sirve a ComprasIntel, que
 *     resuelve al proveedor por RFC DESPUÉS del motor;
 *   · las dos pantallas ofrecen el botón y la ruta JSON existe.
 *
 * Los nombres llevan «T45» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CoincidenciasFactura;
use App\ProductosProveedor;
use App\Proveedores;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$_SESSION['id'] = $usuario;

/** Borra todo lo de esta etapa (primero lo que cuelga de los productos). */
$limpiar = function () use ($db) {
    $ids = [];
    $filas = $db->query("SELECT id FROM proveedor_productos WHERE nombre LIKE 'T45 %' OR codigo LIKE 'T45-%'");
    while ($fila = $filas->fetch_row()) {
        $ids[] = (int) $fila[0];
    }
    if ($ids) {
        $lista = implode(',', $ids);
        $db->query("DELETE FROM proveedor_producto_relaciones WHERE proveedor_producto_id IN ($lista)");
        $db->query("DELETE FROM proveedor_productos WHERE id IN ($lista)");
    }
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T45 %'");
};
$limpiar();

/* --- Datos de la etapa ---------------------------------------------------- */

/** Fila de un producto del proveedor tal como está guardada. */
$producto = function (int $id) use ($db): array {
    return $db->query("SELECT * FROM proveedor_productos WHERE id = " . (int) $id)->fetch_assoc() ?: [];
};

/**
 * Concepto de factura en el formato que entrega CoincidenciasFactura.
 * Ningún nombre de estos existe en el catálogo propio de la base de pruebas.
 */
$concepto = static fn (string $desc, string $sku = ''): array => [
    'descripcion' => $desc, 'cantidad' => '10', 'valor_unitario' => '12.50',
    'importe' => '125.00', 'sku' => $sku,
];

/**
 * Lectura ya en crudo para CoincidenciasFactura::analizar().
 * Sin fecha, folio ni UUID a propósito: el motor salta la detección de
 * duplicados y el test sólo quiere medir el cotejo de conceptos.
 */
$lectura = static function (array $conceptos, string $rfc, string $nombre): array {
    return [
        'fuente'    => 'xml',
        'campos'    => [
            'emisor_rfc'     => ['valor' => $rfc, 'confianza' => 'alta', 'origen' => 'XML'],
            'emisor_nombre'  => ['valor' => $nombre, 'confianza' => 'alta', 'origen' => 'XML'],
            'receptor_rfc'   => ['valor' => 'ZZZZ010101AAA', 'confianza' => 'alta', 'origen' => 'XML'],
            'receptor_nombre'=> ['valor' => 'Cliente T45', 'confianza' => 'alta', 'origen' => 'XML'],
            'subtotal'       => ['valor' => '125.00', 'confianza' => 'alta', 'origen' => 'XML'],
            'iva'            => ['valor' => '20.00', 'confianza' => 'alta', 'origen' => 'XML'],
            'total'          => ['valor' => '145.00', 'confianza' => 'alta', 'origen' => 'XML'],
        ],
        'conceptos' => $conceptos,
        'avisos'    => [],
    ];
};

/* --- 1. Alta rápida desde la factura -------------------------------------- */

echo "Alta del concepto en el catálogo del proveedor\n";

$provActivo = new Proveedores([
    'nombre' => 'T45 Proveedor Frutas', 'rfc' => 'T45010101A1',
    'tipo_proveedor' => 'Material', 'activo' => 1,
]);
$prueba('se creó el proveedor de prueba', $provActivo->validar() === [] && $provActivo->crear(),
    implode(' · ', $provActivo->validar()));
$proveedorId = (int) $provActivo->id;

$provInactivo = new Proveedores([
    'nombre' => 'T45 Proveedor Inactivo', 'rfc' => 'T45010101A2',
    'tipo_proveedor' => 'Material', 'activo' => 0,
]);
$provInactivo->validar();
$provInactivo->crear();
$inactivoId = (int) $provInactivo->id;

$alta = ProductosProveedor::agregarDesdeFactura($proveedorId, [
    'descripcion' => 'T45 Manzana Golden', 'sku' => 'T45-MAN',
    'nota' => 'Manzana golden 10 kg', 'valor_unitario' => 12.5,
], $usuario);
$prueba('el concepto se da de alta', $alta['estado'] === 'creado' && $alta['id'] > 0, json_encode($alta));

$fila = $producto($alta['id']);
$prueba('guarda nombre, código, costo, detalle y activo',
    ($fila['nombre'] ?? '') === 'T45 Manzana Golden'
        && strtoupper((string) ($fila['codigo'] ?? '')) === 'T45-MAN'
        && (float) ($fila['costo_unitario'] ?? 0) === 12.5
        && ($fila['descripcion'] ?? '') === 'Manzana golden 10 kg'
        && (int) ($fila['activo'] ?? 0) === 1,
    json_encode($fila));
$prueba('queda ligado al proveedor que emitió la factura', (int) ($fila['proveedor_id'] ?? 0) === $proveedorId);
$prueba('queda en la bitácora de auditoría', (int) $uno(
    "SELECT COUNT(*) FROM auditoria WHERE entidad = 'proveedor_producto' AND accion = 'crear' AND entidad_id = " . (int) $alta['id']
) >= 1);

$antes = (int) $uno("SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = $proveedorId");
$repetido = ProductosProveedor::agregarDesdeFactura($proveedorId, [
    'descripcion' => '  T45 MANZANA golden  ', 'valor_unitario' => 12.5,
], $usuario);
$prueba('la misma fila dos veces no duplica (sin acentos ni mayúsculas)',
    $repetido['estado'] === 'existente' && $repetido['id'] === $alta['id']
        && (int) $uno("SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = $proveedorId") === $antes,
    json_encode($repetido));

$porCodigo = ProductosProveedor::agregarDesdeFactura($proveedorId, [
    'descripcion' => 'T45 Manzana Roja', 'sku' => 'T45-man', 'valor_unitario' => 15,
], $usuario);
$prueba('un nombre distinto con el MISMO código tampoco duplica',
    $porCodigo['estado'] === 'existente' && $porCodigo['id'] === $alta['id'], json_encode($porCodigo));

$vacia = ProductosProveedor::agregarDesdeFactura($proveedorId, ['descripcion' => '   '], $usuario);
$prueba('un concepto sin descripción se rechaza', $vacia['estado'] === 'error', json_encode($vacia));

$desactivado = ProductosProveedor::agregarDesdeFactura($inactivoId, [
    'descripcion' => 'T45 Producto Inactivo', 'valor_unitario' => 1,
], $usuario);
$prueba('a un proveedor desactivado no se le agregan productos',
    $desactivado['estado'] === 'error' && $desactivado['id'] === 0, json_encode($desactivado));
$prueba('no quedó nada guardado para el inactivo',
    (int) $uno("SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = $inactivoId") === 0);

/* --- 2. La 2ª pasada del motor de cotejo ---------------------------------- */

echo "Cotejo con el catálogo del proveedor\n";

$propuesta = CoincidenciasFactura::analizar($lectura([
    $concepto('T45 Manzana Golden', 'T45-MAN'),   // SÍ está en el catálogo del proveedor
    $concepto('T45 Pera Williams'),               // NO está en ningún catálogo
], 'T45010101A1', 'T45 Proveedor Frutas'), 'gasto');

$prueba('el emisor se reconoce como proveedor',
    $propuesta['proveedor'] !== null && (int) $propuesta['proveedor']['id'] === $proveedorId,
    json_encode($propuesta['proveedor']));
$prueba('el concepto del catálogo del proveedor sale de «sin equivalente»',
    count($propuesta['lineas']) === 1 && count($propuesta['sin_equivalente']) === 1,
    json_encode([$propuesta['lineas'], $propuesta['sin_equivalente']]));
$prueba('el que sigue sin coincidir es sólo el que no existe en ningún lado',
    $propuesta['sin_equivalente'][0]['descripcion'] === 'T45 Pera Williams');
$prueba('el renglón dice de dónde salió',
    str_starts_with($propuesta['lineas'][0]['origen'], 'catálogo del proveedor'),
    (string) $propuesta['lineas'][0]['origen']);
$prueba('el renglón no se confunde con un producto propio (id en 0)',
    (int) $propuesta['lineas'][0]['id'] === 0, json_encode($propuesta['lineas'][0]));
$prueba('trae el costo que el proveedor cobró',
    (float) $propuesta['lineas'][0]['precio_lista'] === 12.5, json_encode($propuesta['lineas'][0]));
$prueba('el concepto sin equivalente ahora entrega su SKU (para llevarlo al código)',
    array_key_exists('sku', $propuesta['sin_equivalente'][0]), json_encode($propuesta['sin_equivalente'][0]));

// Control: el mismo documento pero con un emisor que NO es proveedor.
$ajeno = CoincidenciasFactura::analizar($lectura([
    $concepto('T45 Manzana Golden', 'T45-MAN'),
], 'T45010101ZZ', 'T45 Nadie Conocido'), 'gasto');
$prueba('sin proveedor reconocido el concepto sigue sin equivalente',
    $ajeno['proveedor'] === null && $ajeno['lineas'] === [] && count($ajeno['sin_equivalente']) === 1,
    json_encode($ajeno['proveedor']));

// Control: proveedor reconocido pero un concepto que tampoco está en SU catálogo.
$otro = CoincidenciasFactura::analizar($lectura([
    $concepto('T45 Pera Williams'),
], 'T45010101A1', 'T45 Proveedor Frutas'), 'gasto');
$prueba('lo que no está en el catálogo del proveedor sigue en la lista',
    $otro['proveedor'] !== null && $otro['lineas'] === [] && count($otro['sin_equivalente']) === 1,
    json_encode($otro['sin_equivalente']));

/* --- 3. Pasada tardía (ComprasIntel resuelve al proveedor por RFC) -------- */

echo "Pasada tardía con el proveedor resuelto después\n";

$tardio = CoincidenciasFactura::analizar($lectura([
    $concepto('T45 Manzana Golden'),
], 'T45010101ZZ', 'Nombre Que No Coincide'), 'gasto');
$prueba('empieza sin proveedor y con el concepto en «sin equivalente»',
    $tardio['proveedor'] === null && count($tardio['sin_equivalente']) === 1);

CoincidenciasFactura::cotejarCatalogoDeProveedor($tardio, $proveedorId);
$prueba('la pasada tardía lo saca de «sin equivalente» y arma el renglón',
    count($tardio['lineas']) === 1 && $tardio['sin_equivalente'] === [],
    json_encode([$tardio['lineas'], $tardio['sin_equivalente']]));
$prueba('con el mismo criterio de procedencia',
    str_starts_with($tardio['lineas'][0]['origen'], 'catálogo del proveedor'),
    (string) ($tardio['lineas'][0]['origen'] ?? ''));

$sinCambio = $otro;
CoincidenciasFactura::cotejarCatalogoDeProveedor($sinCambio, $proveedorId);
$prueba('volver a correrla sin nada nuevo no altera el resultado',
    $sinCambio['lineas'] === $otro['lineas'] && count($sinCambio['sin_equivalente']) === 1);
CoincidenciasFactura::cotejarCatalogoDeProveedor($sinCambio, 0);
$prueba('con proveedor 0 no hace nada', $sinCambio['lineas'] === $otro['lineas']);

/* --- 4. Pantallas y ruta -------------------------------------------------- */

echo "Pantallas y ruta\n";
$raiz = dirname(__DIR__, 2);
$archivo = static fn (string $ruta): string => (string) file_get_contents($raiz . '/' . $ruta);

$sinEquivalente = $archivo('templates/sinEquivalente.php');
$prueba('la tabla de conceptos sin equivalente ofrece el botón',
    str_contains($sinEquivalente, 'data-agregar-proveedor')
        && str_contains($sinEquivalente, 'data-proveedor-id')
        && str_contains($sinEquivalente, 'data-cargar-busqueda'));
$prueba('el botón sólo aparece con proveedor reconocido',
    str_contains($sinEquivalente, '$sePuedeAgregar = $proveedorId > 0'));

$aviso = $archivo('templates/avisoLectura.php');
$prueba('el aviso de lectura delega en la tabla compartida',
    str_contains($aviso, "template('sinEquivalente'"));

$altaGasto = $archivo('views/admin/contabilidad/gastos/crear.php');
$prueba('el alta de gasto pasa el proveedor elegido en «Pagado a»',
    str_contains($altaGasto, "'proveedorId' => \$proveedorElegido")
        && str_contains($altaGasto, '$filaProveedor->activo === 1'));

$revision = $archivo('views/admin/compras/inteligente-revision.php');
$prueba('Compras inteligentes ofrece la misma tabla',
    str_contains($revision, "template('sinEquivalente'"));

$rutas = $archivo('index.php');
$prueba('existe la ruta JSON de alta rápida',
    str_contains($rutas, "\$router->post('/admin/compras/catalogo/agregar'"));

$js = $archivo('src/js/modulos/inteligente.js');
$prueba('el botón se maneja sin recargar la página',
    str_contains($js, "'/admin/compras/catalogo/agregar'") && str_contains($js, 'agregarAlProveedor'));

// Limpieza final.
$limpiar();

echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);
