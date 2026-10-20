<?php
/**
 * views/admin/inventario/exportar.php — Exportaciones del inventario a PDF, Excel y CSV (solo lectura).
 * tipo: inventario (existencias actuales, con filtros) | movimientos (historial) | corte (comparación de una fotografía).
 */

soloAdmin();

$tipo    = (string) ($_GET['tipo'] ?? 'inventario');
$formato = (string) ($_GET['formato'] ?? 'csv');

if ($tipo === 'movimientos') {
    $f = ['desde' => (string) ($_GET['desde'] ?? ''), 'hasta' => (string) ($_GET['hasta'] ?? ''), 'producto' => (int) ($_GET['producto'] ?? 0),
        'motivo' => (string) ($_GET['motivo'] ?? ''), 'empresa' => (int) ($_GET['empresa'] ?? 0)];
    [$enc, $filas] = \App\InventarioReportes::filasMovimientos($f);
    $titulo = 'Historial de movimientos de inventario';
    $subtitulo = 'Desde ' . ($f['desde'] ?: 'el inicio') . ' hasta ' . ($f['hasta'] ?: 'hoy');
    $nombre = 'movimientos-inventario-' . date('Y-m-d');
    $volver = '/admin/inventario/movimientos';
} elseif ($tipo === 'corte') {
    $corte = \App\InventarioReportes::corte((int) ($_GET['id'] ?? 0));
    if (!$corte) {
        paginaNoEncontrada();
    }
    $enc = ['SKU', 'Producto', 'En la fotografía', 'Hoy', 'Diferencia'];
    $filas = array_map(static fn ($x) => [$x['sku'], $x['nombre'], $x['antes'] ?? 'No existía', $x['ahora'] ?? 'Eliminado', $x['diferencia']], \App\InventarioReportes::comparar((int) $corte['id']));
    $titulo = 'Inventario: ' . nombreMes((int) $corte['mes']) . ' ' . $corte['anio'] . ' contra hoy';
    $subtitulo = 'Fotografía tomada el ' . date('d/m/Y H:i', strtotime($corte['generado_en']));
    $nombre = sprintf('inventario-corte-%04d-%02d', $corte['anio'], $corte['mes']);
    $volver = '/admin/inventario/fotos';
} else {
    $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'empresa' => (int) ($_GET['empresa'] ?? 0), 'estado' => (string) ($_GET['estado'] ?? 'todos')];
    [$enc, $filas] = \App\InventarioReportes::filasActual(\App\InventarioReportes::actual($f));
    $titulo = 'Inventario actual';
    $subtitulo = 'Filtros: ' . ($f['q'] !== '' ? "búsqueda «{$f['q']}»; " : '') . ($f['empresa'] ? 'negocio #' . $f['empresa'] . '; ' : '') . 'estado ' . $f['estado'];
    $nombre = 'inventario-' . date('Y-m-d');
    $volver = '/admin/inventario';
}

if ($formato === 'pdf') {
    \App\Documentos::descargarTabla($titulo, $subtitulo, $enc, $filas, $nombre, 'Generar este reporte no modifica el inventario.', $volver);
}
if ($formato === 'xlsx') {
    $bytes = \App\Exportador::xlsx(mb_substr($titulo, 0, 30), $enc, $filas);
    if ($bytes === null) {
        redirigir($volver, 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, $nombre . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

\App\Exportador::descargar(\App\Exportador::csv($enc, $filas), $nombre . '.csv', 'text/csv; charset=utf-8');
