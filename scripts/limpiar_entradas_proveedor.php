<?php
/**
 * scripts/limpiar_entradas_proveedor.php — borra el historial de «entradas de proveedor» (proveedor_entradas)
 * y sus fotos. NO toca proveedores, gastos, inventario, órdenes de compra ni ninguna otra tabla.
 *
 * Uso:
 *   php scripts/limpiar_entradas_proveedor.php                          simula: lista lo que se borraría
 *   php scripts/limpiar_entradas_proveedor.php --aplicar --respaldo=DIR borra de verdad
 *
 * Seguridad:
 *   • Aplicar exige una carpeta de respaldo con SHA256SUMS que verifique (scripts/respaldar*.sh).
 *   • Se detiene si cualquier otra tabla tiene una clave foránea hacia proveedor_entradas.
 *   • Todo ocurre en una transacción; las fotos se borran del disco solo después de confirmar.
 *   • Cada entrada borrada queda en la bitácora de auditoría con sus datos.
 * La base se toma de DB_NAME (.env); en el clúster se ejecuta dentro del pod de la aplicación.
 */

require __DIR__ . '/../config/entorno.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$aplicar = in_array('--aplicar', $argv, true);
$respaldo = '';
foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--respaldo=')) {
        $respaldo = substr($argumento, 11);
    }
}

$db = database();
\App\Auditoria::setDB($db);
echo 'Base: ' . $db->query('SELECT DATABASE()')->fetch_row()[0] . ($aplicar ? '  [APLICAR]' : '  [simulación]') . "\n";

// 1. Dependencias: ninguna otra tabla puede apuntar a proveedor_entradas.
$dependientes = $db->query(
    "SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'proveedor_entradas'"
)->fetch_all(MYSQLI_ASSOC);
if ($dependientes) {
    fwrite(STDERR, 'Se detiene: dependen de proveedor_entradas ' . implode(', ', array_column($dependientes, 'TABLE_NAME')) . "\n");
    exit(2);
}

// 2. Lo que se borraría.
$entradas = $db->query(
    'SELECT e.id, e.proveedor_id, p.nombre AS proveedor, e.fecha, e.numero_pedido, e.monto, e.pagado, e.foto
       FROM proveedor_entradas e JOIN proveedores p ON p.id = e.proveedor_id ORDER BY e.id'
)->fetch_all(MYSQLI_ASSOC);
$total = 0.0;
foreach ($entradas as $e) {
    $total += (float) $e['monto'];
    printf("  #%-4d %-22s %s  pedido %-14s %12s  %s  %s\n", $e['id'], $e['proveedor'], $e['fecha'], $e['numero_pedido'] ?: '-',
        number_format((float) $e['monto'], 2), $e['pagado'] ? 'pagada' : 'pendiente', $e['foto'] ?: '(sin foto)');
}
printf("Entradas: %d · importe %s · fotos: %d\n", count($entradas), number_format($total, 2), count(array_filter(array_column($entradas, 'foto'))));

if (!$aplicar) {
    echo "Simulación: no se borró nada. Para borrar: --aplicar --respaldo=<carpeta del respaldo>\n";
    exit(0);
}
if (!$entradas) {
    echo "No hay nada que borrar.\n";
    exit(0);
}

// 3. Respaldo verificado.
if ($respaldo === '' || !is_file("$respaldo/SHA256SUMS") || !is_file("$respaldo/base.sql.gz")) {
    fwrite(STDERR, "Se detiene: --respaldo debe ser una carpeta con base.sql.gz y SHA256SUMS.\n");
    exit(2);
}
exec('cd ' . escapeshellarg($respaldo) . ' && sha256sum -c SHA256SUMS 2>&1', $salida, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, "Se detiene: el respaldo no verifica.\n" . implode("\n", $salida) . "\n");
    exit(2);
}
echo "Respaldo verificado: $respaldo\n";

// 4. Borrado en una transacción, con constancia en la bitácora.
$db->begin_transaction();
try {
    foreach ($entradas as $e) {
        \App\Auditoria::registrar('proveedor_entrada', (int) $e['id'], 'eliminar', null,
            'Limpieza de historial de entradas (reestructura de Compras)', $e);
    }
    $db->query('DELETE FROM proveedor_entradas');
    $quedan = (int) $db->query('SELECT COUNT(*) FROM proveedor_entradas')->fetch_row()[0];
    if ($quedan !== 0) {
        throw new RuntimeException('Quedaron entradas sin borrar.');
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollback();
    fwrite(STDERR, 'Se revirtió: ' . $error->getMessage() . "\n");
    exit(1);
}

// 5. Fotos, solo tras confirmar (y solo las de estas entradas).
$borradas = 0;
foreach ($entradas as $e) {
    if ($e['foto']) {
        \App\Imagenes::eliminar($e['foto']);
        $borradas++;
    }
}
printf("Listo: %d entradas borradas, %d fotos retiradas. Proveedores y demás módulos intactos.\n", count($entradas), $borradas);
