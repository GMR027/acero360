<?php
/**
 * views/admin/analisis-financiero/exportar.php — PDF del balance financiero.
 *
 * Respeta exactamente los filtros de la pantalla: periodo, negocios, base
 * (con o sin IVA) y qué deudas se están restando. Solo lectura.
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$empresas = \App\Empresas::mostrarTodos(100, 0);

$empresasActivas = array_values(array_filter($empresas, static fn ($e) => (int) $e->activa === 1));
$idsActivos = array_map(static fn ($e) => (int) $e->id, $empresasActivas);

$seleccion = isset($_GET['negocios']) && is_array($_GET['negocios'])
    ? array_values(array_intersect(array_map('intval', $_GET['negocios']), $idsActivos))
    : [];
if (!$seleccion) {
    $seleccion = $idsActivos;
}

$base = (string) ($_GET['base'] ?? 'venta') === 'lista' ? 'lista' : 'venta';
$modo = (string) ($_GET['modo'] ?? 'simple') === 'detallado' ? 'detallado' : 'simple';
$restarProveedores = (string) ($_GET['proveedores'] ?? '1') === '1';
$restarGastos = (string) ($_GET['gastos'] ?? '1') === '1';

$r = \App\AnalisisFinanciero::resumen(
    $periodo['anio'],
    $periodo['mes'],
    $seleccion,
    $base,
    $restarProveedores,
    $restarGastos,
    $modo
);

$nombres = [];
foreach ($empresasActivas as $empresa) {
    if (in_array((int) $empresa->id, $seleccion, true)) {
        $nombres[] = (string) $empresa->nombre;
    }
}

$volver = '/admin/analisis-financiero?' . http_build_query([
    'anio'       => $periodo['anio'],
    'mes'        => $periodo['mes'],
    'base'       => $base,
    'modo'       => $modo,
    'proveedores' => $restarProveedores ? '1' : '0',
    'gastos'     => $restarGastos ? '1' : '0',
    'negocios'   => $seleccion,
]);

\App\Documentos::descargarAnalisisFinanciero([
    'r'                 => $r,
    'periodo'           => $periodo,
    'negocios'          => $nombres,
    'base'              => $base,
    'modo'              => $modo,
    'restar_proveedores' => $restarProveedores,
    'restar_gastos'     => $restarGastos,
    'generado'          => date('d/m/Y H:i'),
], 'analisis-financiero-' . $periodo['anio'] . '-' . sprintf('%02d', $periodo['mes']) . '-' . $modo, $volver);
