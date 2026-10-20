<?php
/**
 * pruebas/php/etapa11.php — Centro de seguimiento (notificaciones).
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa11.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{Contabilidad, Seguimiento};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$cliente = (int) $uno('SELECT id FROM clientes LIMIT 1');
$empresa = (int) $uno('SELECT id FROM empresas LIMIT 1');
$contrib = Contabilidad::contribuyentePrincipal();
$_SESSION['id'] = $usuario;
$db->query('DELETE FROM seguimiento_lecturas');

$ingreso = function (string $folio, int $diasAtras, string $facturacion, float $total = 1000.0) use ($db, $contrib, $empresa, $cliente) {
    $fecha = date('Y-m-d', strtotime("-$diasAtras days"));
    $db->query("INSERT INTO ingresos (contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto, subtotal, iva, total, estado, estado_facturacion)
                VALUES ($contrib, $empresa, $cliente, '$fecha', '$folio', 'Prueba seguimiento', $total, 0, $total, 'vigente', '$facturacion')");
    return (int) $db->insert_id;
};
$aviso = function (string $clave) {
    foreach (Seguimiento::avisos() as $a) { if ($a['clave'] === $clave) { return $a; } }
    return null;
};

echo "Categorías: cobro vs. factura\n";
$sinFact = $ingreso('SEG-A', 3, 'sin_clasificar');
$facturada = $ingreso('SEG-B', 3, 'facturada');
$cobro = $aviso("cobro:$sinFact");
$prueba('venta sin cobrar aparece en «cobro»', $cobro !== null && $cobro['categoria'] === 'cobro');
$prueba('venta sin definir factura aparece en «factura»', ($aviso("factura:$sinFact")['categoria'] ?? '') === 'factura');
$prueba('venta ya facturada NO aparece como pendiente de factura', $aviso("factura:$facturada") === null);
$prueba('venta facturada sin cobrar sigue en «cobro» (son cosas distintas)', ($aviso("cobro:$facturada")['categoria'] ?? '') === 'cobro');
$noFact = $ingreso('SEG-C', 3, 'no_facturada');
$prueba('venta marcada «no facturada» tampoco se pide', $aviso("factura:$noFact") === null);

echo "Prioridad según fechas\n";
$viejo = $ingreso('SEG-D', 40, 'facturada'); $medio = $ingreso('SEG-E', 20, 'facturada'); $nuevo = $ingreso('SEG-F', 2, 'facturada');
$prueba('cobro de 40 días sin fecha de vencimiento → normal (no se inventa)', $aviso("cobro:$viejo")['prioridad'] === 'normal');
$prueba('cobro de 20 días → normal', $aviso("cobro:$medio")['prioridad'] === 'normal');
$prueba('cobro de 2 días → normal', $aviso("cobro:$nuevo")['prioridad'] === 'normal');
$orden = array_column(array_filter(Seguimiento::avisos(), fn ($a) => in_array($a['clave'], ["cobro:$viejo", "cobro:$medio", "cobro:$nuevo"], true)), 'clave');
$prueba('mismo nivel: del más antiguo al más reciente', $orden === ["cobro:$viejo", "cobro:$medio", "cobro:$nuevo"], json_encode($orden));

echo "Abrir el registro de origen\n";
$prueba('el cobro apunta al detalle del ingreso', $cobro['enlace'] === "/admin/contabilidad/ingresos/detalle?id=$sinFact");
$prueba('toda alerta trae enlace', count(array_filter(Seguimiento::avisos(), fn ($a) => $a['enlace'] === '')) === 0);

echo "Marcar como leída no cambia la operación\n";
$huella = fn () => md5(json_encode([
    $db->query('SELECT * FROM ingresos ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    $db->query('SELECT * FROM cobros ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    $db->query('SELECT * FROM ventas ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    $db->query('SELECT id, stock FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    $db->query('SELECT * FROM gastos ORDER BY id')->fetch_all(MYSQLI_ASSOC),
]));
$antes = $huella();
$n0 = Seguimiento::contarNoLeidas($usuario);
Seguimiento::marcarLeida($usuario, "cobro:$sinFact");
$prueba('el aviso queda leído', array_values(array_filter(Seguimiento::paraUsuario($usuario), fn ($a) => $a['clave'] === "cobro:$sinFact"))[0]['leida'] === true);
$prueba('baja el contador de no leídas', Seguimiento::contarNoLeidas($usuario) === $n0 - 1);
Seguimiento::marcarLeida($usuario, null);
$prueba('«marcar todo» deja 0 sin leer', Seguimiento::contarNoLeidas($usuario) === 0);
$prueba('ventas, ingresos, cobros, gastos e inventario quedan idénticos', $huella() === $antes);

echo "Si la operación cambia, el aviso vuelve\n";
Contabilidad::registrarCobro($sinFact, date('Y-m-d'), 400.0, 'transferencia', '', $usuario);
$despues = array_values(array_filter(Seguimiento::paraUsuario($usuario), fn ($a) => $a['clave'] === "cobro:$sinFact"))[0] ?? null;
$prueba('un pago parcial reabre el aviso como nuevo', $despues !== null && $despues['leida'] === false && str_contains($despues['titulo'], 'Pago parcial'), json_encode($despues));

$db->query("DELETE FROM cobros WHERE ingreso_id IN ($sinFact, $facturada, $noFact, $viejo, $medio, $nuevo)");
$db->query("DELETE FROM ingresos WHERE folio LIKE 'SEG-%'");
$db->query('DELETE FROM seguimiento_lecturas');

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLO(S)\n";
exit($fallos === 0 ? 0 : 1);
