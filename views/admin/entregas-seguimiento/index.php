<?php
/**
 * views/admin/entregas-seguimiento/index.php — Seguimiento de entregas.
 *
 * El listado de seguimiento se unificó con «Ventas»: las mismas ventas, con
 * los filtros de entrega (por entregar, entregadas, con incidencias) y las
 * acciones rápidas en cada fila. Esta ruta se conserva como redirección para
 * no romper enlaces, avisos ni marcadores.
 */

soloAdmin();

$parametros = array_filter([
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'empresa' => (int) ($_GET['empresa'] ?? 0) ?: '',
    'entrega' => (string) ($_GET['estado'] ?? ''),
], static fn ($valor) => $valor !== '' && $valor !== null && $valor !== 0);

redirigir('/admin/ventas' . ($parametros ? '?' . http_build_query($parametros) : ''));
