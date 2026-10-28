<?php
/**
 * pruebas/php/mapa_ventas.php — agrupación de ventas por GPS para el mapa.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/mapa_ventas.php
 *   DB_NAME=cotizaciones_test php pruebas/php/mapa_ventas.php --sembrar
 *   DB_NAME=cotizaciones_test php pruebas/php/mapa_ventas.php --limpiar
 *
 * `--sembrar` deja los datos para la prueba de navegador e imprime un JSON con
 * el cliente de prueba y los números esperados; `--limpiar` los retira.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Estadisticas;

$EMAIL_CLIENTE = 'mapa.ventas@cotizaciones.local';
$USUARIO = (int) $db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_row()[0];

$limpiar = static function () use ($db, $EMAIL_CLIENTE): void {
    $ids = [];
    $consulta = $db->query("SELECT id FROM ventas WHERE folio LIKE 'MAPA-%'");
    foreach ($consulta as $fila) {
        $ids[] = (int) $fila['id'];
    }
    if ($ids) {
        $lista = implode(',', $ids);
        $db->query("DELETE FROM entrega_fotos WHERE entrega_id IN (SELECT id FROM entregas WHERE venta_id IN ($lista))");
        $db->query("DELETE FROM entregas WHERE venta_id IN ($lista)");
        $db->query("DELETE FROM ventas WHERE id IN ($lista)");
    }
    $db->query("DELETE FROM clientes WHERE email = '" . $db->real_escape_string($EMAIL_CLIENTE) . "'");
};

$crearVenta = static function (int $empresa, string $fecha, array $fotos, string $estado = 'registrada') use ($db, $USUARIO, $EMAIL_CLIENTE): int {
    $clienteId = (int) $db->query("SELECT id FROM clientes WHERE email = '" . $db->real_escape_string($EMAIL_CLIENTE) . "'")->fetch_row()[0];
    $folio = 'MAPA-' . strtoupper(bin2hex(random_bytes(5)));
    $consulta = $db->prepare(
        "INSERT INTO ventas (folio, empresa_id, cliente_id, usuario_id, fecha, estado, aplica_iva, subtotal, iva, total, entrega_estado)
         VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'entregado')"
    );
    $consulta->bind_param('siiiss', $folio, $empresa, $clienteId, $USUARIO, $fecha, $estado);
    $consulta->execute();
    $ventaId = (int) $db->insert_id;
    $consulta->close();

    $token = bin2hex(random_bytes(16));
    $consulta = $db->prepare('INSERT INTO entregas (venta_id, fecha, token, usuario_id) VALUES (?, ?, ?, ?)');
    $consulta->bind_param('issi', $ventaId, $fecha, $token, $USUARIO);
    $consulta->execute();
    $entregaId = (int) $db->insert_id;
    $consulta->close();

    foreach ($fotos as $indice => [$lat, $lon]) {
        $archivo = 'mapa-' . $ventaId . '-' . $indice . '.jpg';
        $latSql = $lat === null ? 'NULL' : number_format((float) $lat, 7, '.', '');
        $lonSql = $lon === null ? 'NULL' : number_format((float) $lon, 7, '.', '');
        $consulta = $db->prepare(
            "INSERT INTO entrega_fotos (entrega_id, archivo, tomada_en, latitud, longitud, usuario_id)
             VALUES (?, ?, ?, $latSql, $lonSql, ?)"
        );
        $consulta->bind_param('issi', $entregaId, $archivo, $fecha, $USUARIO);
        $consulta->execute();
        $consulta->close();
    }

    return $ventaId;
};

$modo = $argv[1] ?? '';
$limpiar();

// Cliente exclusivo: así los conteos no dependen de otras ventas de la base.
$consulta = $db->prepare('INSERT INTO clientes (nombre, nombre_empresa, email) VALUES (?, ?, ?)');
$nombre = 'Mapa ventas prueba';
$consulta->bind_param('sss', $nombre, $nombre, $EMAIL_CLIENTE);
$consulta->execute();
$consulta->close();

$ancla = [24.1426667, -110.3127500];
$lejos = [24.1500000, -110.3000000];

// Grupo A: 4 ventas dentro de 50 m. La última trae una segunda foto lejana que
// no debe contar ni mover el punto.
$crearVenta(1, '2026-01-10', [$ancla]);
$crearVenta(1, '2026-02-10', [[24.1427667, -110.3127500]]);   // +11 m
$crearVenta(1, '2026-03-10', [[24.1426667, -110.3125500]]);   // +20 m
$crearVenta(1, '2026-04-10', [[24.1428667, -110.3127500], [24.2, -110.4]]);

// Grupo B: 2 ventas dentro de 50 m en otro lugar (empresa 2).
$crearVenta(2, '2026-05-10', [$lejos]);
$crearVenta(2, '2026-05-20', [[24.1501000, -110.3000000]]);   // +11 m

// Grupo C: una venta a ~67 m del ancla → lugar distinto.
$crearVenta(1, '2026-06-10', [[24.1432667, -110.3127500]]);

// Sin GPS: cuenta como «sin ubicación».
$crearVenta(1, '2026-07-10', [[null, null]]);

// Cancelada con GPS: no debe aparecer en el mapa.
$crearVenta(2, '2026-08-10', [$lejos], 'cancelada');

$clienteId = (int) $db->query("SELECT id FROM clientes WHERE email = '" . $db->real_escape_string($EMAIL_CLIENTE) . "'")->fetch_row()[0];

if ($modo === '--sembrar') {
    echo json_encode([
        'cliente' => $clienteId,
        'esperados' => [
            'puntos' => 3,
            'ventas_ubicadas' => 7,
            'ventas_sin_ubicacion' => 1,
            'por_ventas' => [4, 2, 1],
            'primera' => '2026-01-10',
            'ultima' => '2026-04-10',
        ],
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if ($modo === '--limpiar') {
    $limpiar();
    echo "limpieza ok\n";
    exit(0);
}

$fallos = 0;
$prueba = static function (string $nombre, bool $ok, string $detalle = '') use (&$fallos): void {
    echo ($ok ? '  ok   ' : '  FALLA ') . $nombre . ($ok || $detalle === '' ? '' : " → $detalle") . "\n";
    $fallos += $ok ? 0 : 1;
};

$mapa = Estadisticas::mapaVentas(0, $clienteId);
$porVentas = array_map(static fn (array $p): int => $p['ventas'], $mapa['puntos']);
$prueba('3 lugares distintos en el mapa', count($mapa['puntos']) === 3, (string) count($mapa['puntos']));
$prueba('7 ventas ubicadas (la foto doble cuenta una vez)', $mapa['ventas_ubicadas'] === 7, (string) $mapa['ventas_ubicadas']);
$prueba('el orden es 4, 2 y 1 ventas', $porVentas === [4, 2, 1], implode(', ', $porVentas));
$prueba('1 venta registrada sin GPS', $mapa['ventas_sin_ubicacion'] === 1, (string) $mapa['ventas_sin_ubicacion']);
$prueba('la venta cancelada con GPS no se cuenta', array_sum($porVentas) === 7, (string) array_sum($porVentas));
$prueba('el grupo de 4 abarca de enero a abril', $mapa['puntos'][0]['primera'] === '2026-01-10' && $mapa['puntos'][0]['ultima'] === '2026-04-10',
    $mapa['puntos'][0]['primera'] . ' a ' . $mapa['puntos'][0]['ultima']);
$prueba('la segunda foto de una venta no crea otro lugar', count(array_filter($porVentas, static fn (int $n): bool => $n === 2)) === 1);

$empresa1 = Estadisticas::mapaVentas(1, $clienteId);
$empresa2 = Estadisticas::mapaVentas(2, $clienteId);
$prueba('filtro por negocio 1: grupos de 4 y 1', array_map(static fn (array $p): int => $p['ventas'], $empresa1['puntos']) === [4, 1],
    implode(', ', array_column($empresa1['puntos'], 'ventas')));
$prueba('filtro por negocio 2: sólo el grupo de 2', array_map(static fn (array $p): int => $p['ventas'], $empresa2['puntos']) === [2],
    implode(', ', array_column($empresa2['puntos'], 'ventas')));

$limpiar();

echo $fallos === 0 ? "TODO OK\n" : "$fallos falla(s)\n";
exit($fallos === 0 ? 0 : 1);
