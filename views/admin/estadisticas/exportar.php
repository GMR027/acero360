<?php
/**
 * views/admin/estadisticas/exportar.php — Estadísticas en PDF, Excel o CSV (solo lectura).
 * Respeta los filtros de la pantalla y deja fecha, filtros y aviso de periodo incompleto.
 */

soloAdmin();

$p = \App\Estadisticas::parametros($_GET);
$r = \App\Estadisticas::reporte($p);
$formato = (string) ($_GET['formato'] ?? 'csv');
$sufijo = $p['tipo'] . '-' . $p['anio'] . ($p['tipo'] === 'anio' ? '' : '-' . $p['indice']);

if ($formato === 'pdf') {
    \App\Documentos::descargarEstadisticas($r);
}

[$encabezados, $filas] = \App\Estadisticas::filasExportacion($r);

if ($formato === 'xlsx') {
    $bytes = \App\Estadisticas::libroExcel($r);
    if ($bytes === null) {
        redirigir('/admin/estadisticas', 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, "estadisticas-$sufijo.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

\App\Exportador::descargar(\App\Exportador::csv($encabezados, $filas), "estadisticas-$sufijo.csv", 'text/csv; charset=utf-8');
