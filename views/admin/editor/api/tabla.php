<?php
/**
 * views/admin/editor/api/tabla.php — Datos para insertar una tabla en una lámina.
 *
 * Orígenes:
 *   sheetflow  → hoja de SheetFlow (id)
 *   inventario → inventario de un negocio activo (empresa)
 *
 * Devuelve `filas` (matriz; la primera fila son los encabezados), los anchos
 * sugeridos, el estilo de la tabla y el formato por celda en coordenadas del
 * Editor (la fila 0 es el encabezado). Es sólo lectura.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$origen = (string) ($_POST['origen'] ?? '');

/** Formato de celda vacío para el Editor. */
function formato_editor(array $f): array
{
    $out = [];
    foreach (['b', 'i', 'u'] as $k) {
        if (!empty($f[$k])) $out[$k] = 1;
    }
    foreach (['fs', 'tc', 'bg', 'al', 'bt', 'br', 'bb', 'bl', 'im', 'lk', 'qr', 'barcode', 'bar', 'barColor', 'spark', 'sparkTipo', 'sparkColor', 'grafica', 'nota', 'adj', 'firma', 'dbar', 'dbarColor'] as $k) {
        if (isset($f[$k]) && $f[$k] !== '') $out[$k] = $f[$k];
    }
    return $out;
}

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

    // SheetFlow usa la fila -1 para el encabezado; el Editor usa la fila 0.
    $fmt = [];
    foreach ((array) $m['fmt'] as $clave => $f) {
        if (!preg_match('/^(-1|\d+),(\d+)$/', (string) $clave, $mm)) continue;
        $r = (int) $mm[1];
        $c = (int) $mm[2];
        $rr = $r < 0 ? 0 : $r + 1;
        if ($rr >= count($filas)) continue;
        $f = formato_editor((array) $f);
        if ($f) $fmt["$rr,$c"] = $f;
    }
    // La fila de totales va en negrita, como en SheetFlow.
    if (!empty($m['totales'])) {
        $tf = count($filas) - 1;
        foreach (array_keys($m['totales']) as $c) {
            $f = $fmt["$tf,$c"] ?? [];
            $f['b'] = 1;
            $fmt["$tf,$c"] = $f;
        }
    }
    // Formato condicional: se resuelve aquí para que el Editor reciba el color ya calculado.
    foreach ((array) ($m['reglasFmt'] ?? []) as $clave => $rf) {
        if (!preg_match('/^(\d+),(\d+)$/', (string) $clave, $rm)) continue;
        $rr = (int) $rm[1] + 1;
        if ($rr >= count($filas)) continue;
        $k = $rr . ',' . $rm[2];
        $fmt[$k] = array_merge($rf, $fmt[$k] ?? []);
    }

    json_respuesta([
        'success'    => true,
        'fuenteTipo' => 'sheetflow',
        'fuenteId'   => (int) $hoja->id,
        'nombre'     => (string) $hoja->nombre,
        'filas'      => $filas,
        'anchos'     => $m['anchos'],
        'estilo'     => $m['estilo'],
        'fmt'        => $fmt,
        'alinear'    => $m['alinear'],
        'tipos'      => $m['tipos'],
        'opciones'   => $m['opciones'],
        'merges'     => array_map(fn($mg) => ['r0' => (int) $mg['r0'] + 1, 'c0' => (int) $mg['c0'], 'r1' => (int) $mg['r1'] + 1, 'c1' => (int) $mg['c1']], (array) ($m['merges'] ?? [])),
        'hash'       => sha1(json_encode([$filas, $m['anchos'], $m['estilo'], $m['fmt'], $m['merges'] ?? []], JSON_UNESCAPED_UNICODE)),
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

    $alinear = ['izquierda', 'izquierda', 'derecha', 'derecha', 'derecha', 'derecha', 'derecha', 'derecha'];
    $tipos = ['texto', 'texto', 'moneda', 'moneda', 'moneda', 'numero', 'moneda', 'moneda'];
    $fmt = [];
    foreach (array_keys($filas[0]) as $c) $fmt["0,$c"] = ['b' => 1];
    $tf = count($filas) - 1;
    foreach (array_keys($filas[0]) as $c) $fmt["$tf,$c"] = ['b' => 1, 'bt' => '2|solid|#334155'];

    json_respuesta([
        'success'    => true,
        'fuenteTipo' => 'inventario',
        'fuenteId'   => (int) $seccion['id'],
        'nombre'     => (string) $seccion['nombre'],
        'filas'      => $filas,
        'anchos'     => [],
        'estilo'     => ['enc' => '#1f3a5f', 'encTxt' => '#ffffff', 'zebra' => true, 'bordes' => true],
        'fmt'        => $fmt,
        'alinear'    => $alinear,
        'tipos'      => $tipos,
        'opciones'   => array_fill(0, count($alinear), []),
        'merges'     => [],
        'hash'       => sha1(json_encode([$filas, $fmt], JSON_UNESCAPED_UNICODE)),
    ]);
}

json_respuesta(['success' => false, 'error' => 'Origen de tabla no válido.'], 422);
