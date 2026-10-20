<?php
/**
 * scripts/purgar_papelera.php — borra de la papelera lo que lleva más de 90 días.
 * La aplicación ya lo hace sola al eliminar y al abrir Ajustes → Papelera; este script
 * permite programarlo (cron) si se quiere que ocurra aunque nadie entre.
 *
 * Uso:  php scripts/purgar_papelera.php
 */

require __DIR__ . '/../config/entorno.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
\App\Papelera::setDB(database());
echo 'Elementos borrados de la papelera: ' . \App\Papelera::purgarVencidos() . "\n";
