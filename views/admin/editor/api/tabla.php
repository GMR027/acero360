<?php
/**
 * views/admin/editor/api/tabla.php — Datos para insertar una tabla en una lámina.
 *
 * Orígenes:
 *   sheetflow  → hoja de SheetFlow (id)
 *   inventario → inventario de un negocio activo (empresa)
 *
 * Devuelve `filas` (matriz; la primera fila son los encabezados), los anchos
 * sugeridos de SheetFlow y el nombre del origen. Es sólo lectura.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$origen = (string) ($_POST['origen'] ?? '');

if ($origen === 'sheetflow') {
    $hoja = \Model\SheetflowHoja::find((int) ($_POST['id'] ?? 0));
    if (!$hoja) {
        json_respuesta(['success' => false, 'error' => 'La tabla de SheetFlow no existe o fue eliminada.'], 404);
    }

    $m = \Classes\Sheetflow::matriz($hoja);
    $filas = [$m['columnas']];
    foreach ($m['filas'] as $fila) {
        $filas[] = $fila;
    }
    if (!empty($m['totales'])) {
        $filas[] = $m['totales'];
    }

    json_respuesta([
        'success'    => true,
        'fuenteTipo' => 'sheetflow',
        'fuenteId'   => (int) $hoja->id,
        'nombre'     => (string) $hoja->nombre,
        'filas'      => $filas,
        'anchos'     => $m['anchos'],
        'hash'       => sha1(json_encode([$filas, $m['anchos']], JSON_UNESCAPED_UNICODE)),
    ]);
}

if ($origen === 'inventario') {
    $empresaId = (int) ($_POST['empresa'] ?? 0);
    $seccion = null;
    foreach (\App\Inventario::paraPdf() as $s) {
        if ((int) $s['id'] === $empresaId) {
            $seccion = $s;
            break;
        }
    }
    if (!$seccion) {
        json_respuesta(['success' => false, 'error' => 'Ese negocio no tiene productos en el inventario.'], 404);
    }

    $filas = [[
        'Código', 'Producto', 'Precio de lista (sin IVA)', 'IVA 16 % unitario', 'Precio con IVA unitario',
        'Cantidad en inventario', 'Total sin IVA', 'Total con IVA',
    ]];
    foreach ($seccion['productos'] as $p) {
        $filas[] = [
            $p['sku'] !== '' ? $p['sku'] : '—',
            $p['nombre'],
            formatoMoneda($p['precio']),
            formatoMoneda($p['iva_unitario']),
            formatoMoneda($p['precio_con_iva']),
            number_format($p['stock']),
            formatoMoneda($p['total_sin_iva']),
            formatoMoneda($p['total_con_iva']),
        ];
    }
    $filas[] = [
        'Totales', '', '', '', '',
        number_format($seccion['piezas']),
        formatoMoneda($seccion['total_sin_iva']),
        formatoMoneda($seccion['total_con_iva']),
    ];

    json_respuesta([
        'success'    => true,
        'fuenteTipo' => 'inventario',
        'fuenteId'   => (int) $seccion['id'],
        'nombre'     => (string) $seccion['nombre'],
        'filas'      => $filas,
        'anchos'     => [],
        'hash'       => sha1(json_encode($filas, JSON_UNESCAPED_UNICODE)),
    ]);
}

json_respuesta(['success' => false, 'error' => 'Origen de tabla no válido.'], 422);
