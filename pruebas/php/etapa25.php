<?php
/**
 * pruebas/php/etapa25.php — Centro de seguimiento: compras, proveedores, materiales y stock alineado.
 *
 * Comprueba que el centro de seguimiento vigila también el lado de las compras:
 *   · compra por recibir (partidas controladas sin recepción completa) y su huella al recibir;
 *   · factura del proveedor pendiente y su desaparición al marcar «sin factura»;
 *   · saldo por pagar, con pago parcial y con pago total;
 *   · que una compra ya enviada a contabilidad SÍ sigue avisando su saldo (no se deduce del gasto);
 *   · entrada a proveedor sin pagar y su desaparición al marcarla pagada;
 *   · material por debajo del mínimo (urgente cuando ya no queda nada);
 *   · que un producto agotado SIN mínimo definido ahora genera aviso (como en el Panel);
 *   · claves únicas y etiquetas de botón de los tipos nuevos.
 *
 * Los nombres llevan el «25» para no chocar con los de las otras etapas.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa25.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CompraContable;
use App\Materiales;
use App\OrdenesCompra as OC;
use App\PagosCompra;
use App\ProveedorEntradas;
use App\Seguimiento;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$hoy = date('Y-m-d');

/** Limpia en orden de dependencias (las llaves foráneas de recepciones y pagos son RESTRICT). */
$limpiar = function () use ($db) {
    $db->query("DELETE mm FROM movimientos_material mm JOIN recepciones r ON r.id = mm.recepcion_id
                 JOIN ordenes_compra o ON o.id = r.orden_id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE mp FROM movimientos_por_terminar mp JOIN recepciones r ON r.id = mp.recepcion_id
                 JOIN ordenes_compra o ON o.id = r.orden_id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE rp FROM recepcion_partidas rp JOIN recepciones r ON r.id = rp.recepcion_id
                 JOIN ordenes_compra o ON o.id = r.orden_id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE r FROM recepciones r JOIN ordenes_compra o ON o.id = r.orden_id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE pc FROM pagos_compra pc JOIN ordenes_compra o ON o.id = pc.orden_id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE g FROM gastos g JOIN ordenes_compra o ON o.gasto_id = g.id WHERE o.referencia LIKE 'T25-%'");
    $db->query("DELETE FROM ordenes_compra WHERE referencia LIKE 'T25-%'");
    $db->query("DELETE FROM proveedor_entradas WHERE numero_pedido LIKE 'T25-%'");
    $db->query("DELETE FROM materiales WHERE nombre LIKE 'T25 %'");
    $db->query("DELETE FROM productos WHERE nombre LIKE 'P25 %'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T25 %'");
};
$limpiar();

$aviso = function (string $clave) { foreach (Seguimiento::avisos() as $a) { if ($a['clave'] === $clave) { return $a; } } return null; };
$claves = fn () => array_column(Seguimiento::avisos(), 'clave');

$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T25 Aceros', 1)");
$prov = (int) $uno("SELECT id FROM proveedores WHERE nombre = 'T25 Aceros'");

// Partida de insumo controlada en inventario (nace un material con mínimo).
$controlada = fn (string $descripcion, string $cantidad, string $costo) => [
    'descripcion' => $descripcion, 'cantidad' => $cantidad, 'costo' => $costo, 'unidad' => 'pza', 'controla' => 1,
    'aplica_iva' => 0, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => 'T25 ' . $descripcion, 'proveedor_producto_id' => 0,
];
$cab = fn (string $ref, array $extra = []) => ['tipo' => 'insumo', 'proveedor_id' => $prov, 'fecha_solicitud' => $hoy, 'referencia' => $ref] + $extra;

echo "Compra por recibir\n";
$prueba('crear compra con partida controlada', OC::crear($cab('T25-A'), [$controlada('Varilla', '10', '12.00')], $usuario));
$ocA = (int) OC::$ultimoId;
$a = $aviso("compra_recibir:$ocA");
$prueba('la compra abierta genera aviso por recibir', $a !== null && $a['categoria'] === 'compra');
$prueba('  prioridad normal y botón específico', $a !== null && $a['prioridad'] === 'normal' && Seguimiento::etiquetaAbrir("compra_recibir:$ocA") === 'Ver compra por recibir');
$prueba('  el detalle dice cuánto falta de cuánto', $a !== null && str_contains($a['detalle'], 'faltan 10 de 10'), $a['detalle'] ?? '(sin aviso)');

$partidaId = (int) OC::partidas($ocA)[0]['id'];
$prueba('recibir parte cambia la huella (vuelve a ser nuevo)', OC::recibir($ocA, $hoy, [$partidaId => ['cantidad' => '4', 'costo' => '12.00']], bin2hex(random_bytes(16)), false, '', $usuario)
    && str_contains($aviso("compra_recibir:$ocA")['detalle'], 'faltan 6 de 10'));
$prueba('  la compra sigue viva: sigue en la categoría', OC::buscarID($ocA)['estado'] === 'parcial');

echo "Factura del proveedor pendiente\n";
$prueba('toda compra nueva nace con el CFDI pendiente', $aviso("compra_cfdi:$ocA") !== null
    && Seguimiento::etiquetaAbrir("compra_cfdi:$ocA") === 'Abrir compra para facturar');
$prueba('marcar «no se facturará» la quita del centro', CompraContable::definirEstadoCfdi($ocA, 'no_facturada', $usuario) && $aviso("compra_cfdi:$ocA") === null);

echo "Saldo por pagar\n";
$prueba('una compra sin pagar genera aviso de saldo', $aviso("compra_saldo:$ocA") !== null);
$prueba('  dice cuánto falta y a quién', str_contains($aviso("compra_saldo:$ocA")['detalle'], 'falta pagar $120.00'), $aviso("compra_saldo:$ocA")['detalle'] ?? '');
$prueba('pago parcial cambia el título y la huella', PagosCompra::registrar($ocA, $hoy, '30', 'Efectivo', '', bin2hex(random_bytes(16)), $usuario)
    && str_contains($aviso("compra_saldo:$ocA")['titulo'], 'Pago parcial a proveedor')
    && str_contains($aviso("compra_saldo:$ocA")['detalle'], 'falta pagar $90.00'));
$prueba('pagar todo la quita del centro', PagosCompra::registrar($ocA, $hoy, '90', 'Efectivo', '', bin2hex(random_bytes(16)), $usuario) && $aviso("compra_saldo:$ocA") === null);

echo "Compra ya en contabilidad: el saldo no se pierde\n";
$prueba('crear segunda compra', OC::crear($cab('T25-B'), [$controlada('Perfil', '5', '40.00')], $usuario));
$ocB = (int) OC::$ultimoId;
$prueba('enviarla a contabilidad la convierte en gasto', CompraContable::enviarAContabilidad($ocB, $usuario) > 0);
$prueba('  aparece el gasto por pagar (camino de contabilidad)', $aviso('gasto_pago:' . (int) $uno("SELECT gasto_id FROM ordenes_compra WHERE id = $ocB")) !== null);
$prueba('  Y el aviso de compra por pagar sigue ahí (no se deduce)', $aviso("compra_saldo:$ocB") !== null,
    'si se dedujera, pagar el gasto desde Contabilidad dejaría la deuda sin aviso');

echo "Entrada a proveedor sin pagar\n";
$db->query("INSERT INTO proveedor_entradas (proveedor_id, fecha, numero_pedido, monto, descripcion, usuario_id)
            VALUES ($prov, '$hoy', 'T25-REM-1', 1500, 'Remisión de prueba etapa 25', $usuario)");
$ent = (int) $db->insert_id;
$e = $aviso("entrada:$ent");
$prueba('la entrada sin pagar genera aviso', $e !== null && $e['categoria'] === 'compra');
$prueba('  nombra el pedido y el proveedor', $e !== null && str_contains($e['titulo'], 'T25-REM-1') && str_contains($e['detalle'], 'T25 Aceros'));
$prueba('marcarla pagada la quita del centro', ProveedorEntradas::alternarPagado($ent, true) && $aviso("entrada:$ent") === null);

echo "Materiales bajo el mínimo\n";
$mat = Materiales::crear('T25 Tubo', 'pza', '10');
$prueba('crear material con mínimo', $mat > 0);
$m = $aviso("material:$mat");
$prueba('sin existencias y con mínimo → urgente', $m !== null && $m['prioridad'] === 'urgente', $m['prioridad'] ?? '(sin aviso)');
$prueba('  el detalle trae unidad y mínimo', $m !== null && str_contains($m['detalle'], 'mínimo es 10'));
$prueba('bajo el mínimo pero con piezas → normal', Materiales::corregir($mat, '4', 'prueba etapa 25', $usuario)
    && $aviso("material:$mat")['prioridad'] === 'normal');
$prueba('al superar el mínimo desaparece', Materiales::corregir($mat, '25', 'prueba etapa 25', $usuario) && $aviso("material:$mat") === null);

$db->query("INSERT INTO materiales (nombre, unidad, existencia, minimo, activo) VALUES ('T25 Sin mínimo', 'pza', 0, NULL, 1)");
$sinMinimo = (int) $db->insert_id;
$prueba('material sin mínimo definido no genera aviso (no se inventa)', $aviso("material:$sinMinimo") === null);

echo "Stock alineado con el Panel\n";
$db->query("INSERT INTO productos (empresa_id, nombre, activo, stock, stock_minimo, precio) VALUES ($empresa, '[P25] agotado sin mínimo', 1, 0, NULL, 10)");
$agotado = (int) $db->insert_id;
$prueba('producto agotado SIN mínimo ahora genera aviso urgente', ($p = $aviso("stock:$agotado")) !== null && $p['prioridad'] === 'urgente', $p['prioridad'] ?? '(sin aviso)');
$prueba('  el detalle no inventa un mínimo', str_contains($p['detalle'], 'no tiene mínimo definido'));
$db->query("INSERT INTO productos (empresa_id, nombre, activo, stock, stock_minimo, precio) VALUES ($empresa, '[P25] sobra', 1, 9, 3, 10)");
$prueba('producto por encima del mínimo no genera aviso', $aviso('stock:' . (int) $db->insert_id) === null);

echo "Catálogo y claves\n";
$prueba('la categoría Compras existe con su icono', array_key_exists('compra', Seguimiento::CATEGORIAS) && Seguimiento::CATEGORIAS['compra'][1] === 'shopping_cart');
$prueba('el botón de compra con saldo es el de siempre', Seguimiento::etiquetaAbrir('compra_saldo:1') === 'Abrir compra');
$prueba('el botón de entrada es el de siempre', Seguimiento::etiquetaAbrir('entrada:1') === 'Abrir entrada');
$lista = $claves();
$prueba('claves únicas', count($lista) === count(array_unique($lista)));
$prueba('recalcular da las mismas claves', $lista === $claves());
$prueba('toda alerta trae enlace', count(array_filter(Seguimiento::avisos(), fn ($x) => $x['enlace'] === '')) === 0);

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
