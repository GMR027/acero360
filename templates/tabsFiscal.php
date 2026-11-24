<?php
/**
 * templates/tabsFiscal.php — Secciones del módulo fiscal. Variables: $tab, $periodo (anio/mes) opcional.
 */
$consulta = isset($periodo) ? '?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]) : '';
$secciones = [
    'resumen'       => ['/admin/fiscal' . $consulta, 'Impuestos', 'request_quote'],
    'preliminar'    => ['/admin/fiscal/preliminar' . $consulta, 'Preliminar básico', 'calculate'],
    'movimientos'   => ['/admin/fiscal/movimientos' . $consulta, 'Ventas y gastos', 'table_view'],
    'documentos'    => ['/admin/fiscal/documentos' . $consulta, 'Documentos fiscales', 'fact_check'],
    'registros'     => ['/admin/fiscal/registros' . $consulta, 'Retenciones y declaraciones', 'gavel'],
    'configuracion' => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
];
$etiqueta = 'Secciones fiscales';
require __DIR__ . '/navModulo.php';
