<?php
/**
 * views/admin/clientes/compras-pdf.php — PDF del reporte de compras de un cliente.
 *
 * Sólo lectura. Usa los mismos filtros que la pantalla.
 */

soloAdmin();

$clienteId = (int) ($_GET['id'] ?? 0);
$cliente   = \App\Clientes::buscarID($clienteId);

if (!$cliente) {
    paginaNoEncontrada();
}

$periodoDatos = \App\VentasCliente::normalizarPeriodo($_GET);
$periodo      = $periodoDatos['periodo'];
$anio         = $periodoDatos['anio'];
$mes          = $periodoDatos['mes'];
$desde        = $periodoDatos['desde'];
$hasta        = $periodoDatos['hasta'];

$empresaId = (int) ($_GET['empresa'] ?? 0);
$orden     = (string) ($_GET['orden'] ?? 'piezas');
if (!in_array($orden, ['piezas', 'importe'], true)) {
    $orden = 'piezas';
}

$empresaNombre = '';
if ($empresaId > 0) {
    $empresa = \App\Empresas::buscarID($empresaId);
    $empresaNombre = $empresa ? (string) $empresa->nombre : '';
}

$reporte = [
    'resumen'    => \App\VentasCliente::resumen($clienteId, $desde, $hasta, $empresaId),
    'productos'  => \App\VentasCliente::productos($clienteId, $desde, $hasta, $empresaId, $orden),
    'documentos' => \App\VentasCliente::documentos($clienteId, $desde, $hasta, $empresaId, 5000, 0),
    'serie'      => $periodo === 'anio' ? \App\VentasCliente::serieMensual($clienteId, $anio, $empresaId) : [],
    'anio'       => $anio,
];

$parametros = [
    'id'      => $clienteId,
    'periodo' => $periodo,
    'anio'    => $anio,
    'mes'     => $mes,
    'desde'   => $desde,
    'hasta'   => $hasta,
    'empresa' => $empresaId ?: '',
    'orden'   => $orden,
];
$parametros = array_filter($parametros, static fn ($v): bool => $v !== '' && $v !== null);
$volver = '/admin/clientes/compras?' . http_build_query($parametros);
$nombre = 'compras-cliente-' . $clienteId . '-' . $desde . '-' . $hasta;

\App\Documentos::descargarComprasCliente(
    $cliente,
    $reporte,
    $periodoDatos['etiqueta'],
    $empresaNombre,
    $nombre,
    $volver
);
