<?php
/**
 * scripts/foto_inventario.php — fotografía automática del inventario (para programar al cierre de cada mes).
 *
 * Uso:  php scripts/foto_inventario.php [AAAA-MM]     (sin argumento: el mes ANTERIOR)
 * Cron sugerido (día 1 de cada mes, 00:10):  10 0 1 * *  php /var/www/html/scripts/foto_inventario.php
 * No modifica existencias. Si la fotografía del mes ya existe, no hace nada y termina con éxito.
 */
require __DIR__ . '/../includes/app.php';

$periodo = $argv[1] ?? date('Y-m', strtotime('first day of last month'));
if (!preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m)) {
    fwrite(STDERR, "Formato: AAAA-MM\n");
    exit(2);
}

if (\App\InventarioReportes::generarCorte((int) $m[1], (int) $m[2], null)) {
    echo "Fotografía de $periodo generada.\n";
    exit(0);
}

$e = implode(' ', \App\InventarioReportes::getErrores());
echo $e . "\n";
exit(str_contains($e, 'Ya existe') ? 0 : 1);
