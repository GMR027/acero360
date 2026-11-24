<?php
/**
 * views/admin/estadisticas/index.php — Tablero comercial enfocado.
 *
 * Orden de lectura:
 *   1. Productos del periodo: más y menos vendidos, por piezas o por monto.
 *   2. Clientes del periodo: mayor y menor compra por negocio.
 *   3. Ventas y cobros: comparativa mensual de 3 años y cobrado/pendiente.
 *   4. Gastos: comparativa mensual de 3 años.
 *   5. Tres años: productos por piezas y ventas a clientes.
 *   6. Mapa de ventas.
 *
 * Las gráficas de ECharts viven en Analisis; la vista sólo elige las que
 * necesita y construye las dos matrices de 3 años.
 */

soloAdmin();

$clienteMapa = max(0, (int) ($_GET['cliente'] ?? 0));
$entrada = $_GET;
unset($entrada['cliente'], $entrada['producto'], $entrada['agrupacion']);
$entrada['atras'] = 2; // Siempre 3 años: el año seleccionado y los dos anteriores.

$p = \App\Estadisticas::parametros($entrada);
$r = \App\Estadisticas::reporte($p);
$f = $p['filtros'];
$actual = $r['actual'];
$m = static fn (int $c): string => formatoMoneda($c / 100);
$etiquetaPeriodo = \App\Estadisticas::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']);
$empresas = \App\Empresas::mostrarTodos(100, 0);
$hoyAnio = (int) date('Y');
$maximoIndice = ['mes' => 12, 'trimestre' => 4, 'semestre' => 2, 'anio' => 1][$p['tipo']];
$aniosTexto = ($p['anio'] - 2) . ' · ' . ($p['anio'] - 1) . ' · ' . $p['anio'];
$base = $p['base'];
$baseTexto = $base === 'subtotal' ? 'sin IVA' : 'con IVA';
$orden = ($_GET['orden'] ?? 'piezas') === 'monto' ? 'monto' : 'piezas';
$ordenSql = $orden === 'monto' ? 'importe' : 'unidades';
$mapaVentas = \App\Estadisticas::mapaVentas($f['empresa'], $clienteMapa);
[$desdePeriodo, $hastaPeriodo] = $r['periodo'];

$graficas = [];
foreach ($r['graficas'] as $g) {
    $graficas[$g['id']] = $g;
}
$graficaVentas = $graficas['ventas-anios'] ?? [
    'id' => 'ventas-anios', 'titulo' => 'Ventas mensuales · 3 años', 'subtitulo' => 'Sin datos para el periodo',
    'tipo' => 'linea', 'formato' => 'moneda', 'categorias' => [], 'series' => [],
];
$graficaCobros = $graficas['cobrado-pendiente'] ?? [
    'id' => 'cobrado-pendiente', 'titulo' => 'Cobrado y saldo pendiente', 'subtitulo' => 'Sin datos para el periodo',
    'tipo' => 'apilado', 'formato' => 'moneda', 'categorias' => [], 'series' => [],
];
$graficaGastos = $graficas['gastos-anios'] ?? [
    'id' => 'gastos-anios', 'titulo' => 'Gastos mensuales · 3 años', 'subtitulo' => 'Sin datos para el periodo',
    'tipo' => 'linea', 'formato' => 'moneda', 'categorias' => [], 'series' => [],
];

$parametrosRapidos = [
    'atras' => 2,
    'base' => $base,
    'orden' => $orden,
    'empresa' => (int) $f['empresa'],
];
$urlRapida = static function (string $tipo, int $indice, int $anio) use ($parametrosRapidos): string {
    return '/admin/estadisticas?' . http_build_query(array_filter(
        $parametrosRapidos + ['tipo' => $tipo, 'indice' => $indice, 'anio' => $anio],
        static fn ($v) => $v !== '' && $v !== 0 && $v !== null
    ));
};

$colorIndicador = [
    'realizadas' => \App\Analisis::COLOR['ventas'],
    'cobros' => \App\Analisis::COLOR['cobros'],
    'facturadas' => \App\Analisis::COLOR['facturada'],
    'gastos' => \App\Analisis::COLOR['gastos'],
];

/* --- Rankings del periodo ------------------------------------------------ */
$topProductos = \App\Estadisticas::topProductos($desdePeriodo, $hastaPeriodo, $f, 10, $ordenSql);
$menosProductos = \App\Analisis::menorMovimiento($desdePeriodo, $hastaPeriodo, $f, 10, $ordenSql);
$topClientes = \App\Estadisticas::topClientes($desdePeriodo, $hastaPeriodo, $f, $base, 10, $ordenSql);
$menosClientes = \App\Estadisticas::menorClientes($desdePeriodo, $hastaPeriodo, $f, $base, 10, $ordenSql);

$normalizarProductos = static function (array $filas) use ($base): array {
    return array_map(static function (array $x) use ($base): array {
        $x['monto'] = $base === 'subtotal'
            ? (int) ($x['importe'] ?? 0)
            : (int) ($x['importe_con_iva'] ?? $x['importe'] ?? 0);
        return $x;
    }, $filas);
};
$normalizarClientes = static fn (array $filas): array => array_map(static function (array $x): array {
    $x['monto'] = (int) ($x['importe'] ?? 0);
    return $x;
}, $filas);

$topProductos = $normalizarProductos($topProductos);
$menosProductos = $normalizarProductos($menosProductos);
$topClientes = $normalizarClientes($topClientes);
$menosClientes = $normalizarClientes($menosClientes);

$gruposTopProductos = \App\Estadisticas::agruparPorNegocio($topProductos);
$gruposMenosProductos = \App\Estadisticas::agruparPorNegocio($menosProductos);
$gruposTopClientes = \App\Estadisticas::agruparPorNegocio($topClientes);
$gruposMenosClientes = \App\Estadisticas::agruparPorNegocio($menosClientes);

$porEmpresa = static function (array $grupos): array {
    $mapa = [];
    foreach ($grupos as $grupo) {
        $mapa[(int) $grupo['empresa_id']] = $grupo;
    }
    return $mapa;
};
$menosProductosPorEmpresa = $porEmpresa($gruposMenosProductos);
$topProductosPorEmpresa = $porEmpresa($gruposTopProductos);
$menosClientesPorEmpresa = $porEmpresa($gruposMenosClientes);
$topClientesPorEmpresa = $porEmpresa($gruposTopClientes);

$empresasProductos = $topProductosPorEmpresa + $menosProductosPorEmpresa;
$empresasClientes = $topClientesPorEmpresa + $menosClientesPorEmpresa;

/* --- Matrices de 3 años --------------------------------------------------- */
$matProductos = \App\Estadisticas::porAniosProductos($p['anio'] - 2, $p['anio'], $f, 8);
$matClientes = $r['matriz_clientes'];

$seriesProductos = [];
foreach ($matProductos['anios'] as $i => $anio) {
    $datos = [];
    foreach ($matProductos['filas'] as $fila) {
        $datos[] = (int) ($fila['unidades_por_anio'][$anio] ?? 0);
    }
    $seriesProductos[] = [
        'nombre' => (string) $anio,
        'datos' => $datos,
        'color' => \App\Analisis::COLORES_ANIOS[count($matProductos['anios']) - 1 - $i] ?? \App\Analisis::COLORES_ANIOS[0],
    ];
}
$graficaProductosAnios = [
    'id' => 'productos-anios',
    'titulo' => 'Productos más vendidos · 3 años',
    'subtitulo' => 'Piezas vendidas por año · top 8 por piezas',
    'tipo' => 'barras',
    'formato' => 'entero',
    'categorias' => array_column($matProductos['filas'], 'nombre'),
    'series' => $seriesProductos,
    'comparaSeries' => true,
    'rotar' => 25,
    'anchoEtiqueta' => 120,
];

$seriesClientes = [];
foreach ($matClientes['anios'] as $i => $anio) {
    $datos = [];
    foreach ($matClientes['filas'] as $fila) {
        $datos[] = round(((int) ($fila['por_anio'][$anio] ?? 0)) / 100, 2);
    }
    $seriesClientes[] = [
        'nombre' => (string) $anio,
        'datos' => $datos,
        'color' => \App\Analisis::COLORES_ANIOS[count($matClientes['anios']) - 1 - $i] ?? \App\Analisis::COLORES_ANIOS[0],
    ];
}
$graficaClientesAnios = [
    'id' => 'clientes-anios',
    'titulo' => 'Ventas a clientes · 3 años',
    'subtitulo' => 'Importe ' . $baseTexto . ' por año · top 8 por volumen',
    'tipo' => 'barras',
    'formato' => 'moneda',
    'categorias' => array_column($matClientes['filas'], 'nombre'),
    'series' => $seriesClientes,
    'comparaSeries' => true,
    'rotar' => 20,
    'anchoEtiqueta' => 110,
];

/* --- Resúmenes de las tarjetas ------------------------------------------- */
$resumenSerieAnual = static function (array $mensual, int $anio, string $etiqueta, bool $subeBueno) use ($m): array {
    $actualAnio = array_sum(array_map('intval', $mensual[$anio] ?? []));
    $previoAnio = array_sum(array_map('intval', $mensual[$anio - 1] ?? []));
    $v = ($actualAnio > 0 || $previoAnio > 0) ? \App\Estadisticas::variacion($previoAnio, $actualAnio) : null;
    $nota = 'Sin comparación con ' . ($anio - 1);
    $clase = '';
    if ($v !== null) {
        $sube = (int) $v['absoluta'] >= 0;
        $bueno = $sube === $subeBueno;
        $clase = $bueno ? 'variacion--sube' : 'variacion--baja';
        $nota = $v['porcentual'] === null
            ? 'Sin base en ' . ($anio - 1)
            : ($sube ? '▲ +' : '▼ ') . number_format(abs((float) $v['porcentual']), 1) . ' % vs ' . ($anio - 1);
    }
    return ['etiqueta' => $etiqueta . ' ' . $anio, 'valor' => $m($actualAnio), 'nota' => $nota, 'clase' => $clase];
};

$resumenVentas = $resumenSerieAnual($r['analisis']['ventas_anios'], $p['anio'], 'Ventas', true);
$resumenGastos = $resumenSerieAnual($r['analisis']['gastos_anios'], $p['anio'], 'Gastos', false);

$cobradoTotal = (int) array_sum($r['analisis']['cobranza']['cobrado'] ?? []);
$pendienteTotal = (int) array_sum($r['analisis']['cobranza']['pendiente'] ?? []);
$resumenCobros = [
    'etiqueta' => 'Cobrado ' . $p['anio'],
    'valor' => $m($cobradoTotal),
    'nota' => $pendienteTotal > 0 ? 'Pendiente ' . $m($pendienteTotal) : 'Sin saldo pendiente',
    'clase' => '',
];

$piezasAnio = 0;
$piezasTres = 0;
foreach ($matProductos['filas'] as $fila) {
    $piezasAnio += (int) ($fila['unidades_por_anio'][$p['anio']] ?? 0);
    $piezasTres += (int) $fila['total_unidades'];
}
$resumenProductosAnios = [
    'etiqueta' => 'Piezas ' . $p['anio'],
    'valor' => number_format($piezasAnio),
    'nota' => '3 años: ' . number_format($piezasTres) . ' pzas',
    'clase' => '',
];

$montoAnio = 0;
$montoTres = 0;
foreach ($matClientes['filas'] as $fila) {
    $montoAnio += (int) ($fila['por_anio'][$p['anio']] ?? 0);
    $montoTres += (int) $fila['total'];
}
$resumenClientesAnios = [
    'etiqueta' => 'Ventas ' . $p['anio'],
    'valor' => $m($montoAnio),
    'nota' => '3 años: ' . $m($montoTres),
    'clase' => '',
];

/* --- Helpers de presentación --------------------------------------------- */
$tarjetaGrafica = static function (array $g, array $resumen = [], string $claseExtra = ''): string {
    $tipo = (string) ($g['tipo'] ?? 'barras');
    $icono = match ($tipo) {
        'linea' => 'show_chart',
        'apilado' => 'stacked_bar_chart',
        'barras-h' => 'sort',
        default => 'bar_chart',
    };
    $resumenHtml = '';
    if ($resumen) {
        $resumenHtml = '<div class="grafica-tarjeta__resumen">'
            . '<span class="grafica-tarjeta__resumen-etiqueta">' . escaparValores((string) ($resumen['etiqueta'] ?? '')) . '</span>'
            . '<strong class="grafica-tarjeta__resumen-valor cifra">' . escaparValores((string) ($resumen['valor'] ?? '')) . '</strong>'
            . (!empty($resumen['nota'])
                ? '<span class="grafica-tarjeta__resumen-nota ' . escaparValores((string) ($resumen['clase'] ?? '')) . '">' . escaparValores((string) $resumen['nota']) . '</span>'
                : '')
            . '</div>';
    }

    return '<section class="grafica-tarjeta ' . escaparValores($claseExtra) . '" id="g-' . escaparValores((string) ($g['id'] ?? '')) . '">'
        . '<header class="grafica-tarjeta__cabecera">'
        . '<span class="grafica-tarjeta__icono" aria-hidden="true"><span class="material-symbols-outlined">' . $icono . '</span></span>'
        . '<div class="grafica-tarjeta__texto">'
        . '<h3 class="grafica-tarjeta__titulo">' . escaparValores((string) ($g['titulo'] ?? '')) . '</h3>'
        . '<p class="grafica-tarjeta__subtitulo">' . escaparValores((string) ($g['subtitulo'] ?? '')) . '</p>'
        . '</div>' . $resumenHtml . '</header>'
        . '<figure class="grafica" data-grafica="' . escaparValores(json_encode($g, JSON_UNESCAPED_UNICODE)) . '" aria-label="' . escaparValores((string) ($g['titulo'] ?? '')) . '"></figure>'
        . '</section>';
};

$cardRanking = static function (
    string $titulo,
    string $subtitulo,
    string $icono,
    string $variante,
    array $filas,
    string $tipo
) use ($m, $orden): string {
    $porMonto = $orden === 'monto';
    $clave = $porMonto ? 'monto' : 'unidades';
    $max = 1;
    foreach ($filas as $fila) {
        $max = max($max, (int) ($fila[$clave] ?? 0));
    }

    $html = '<article class="ranking ranking--' . escaparValores($variante) . '">'
        . '<header class="ranking__cabecera">'
        . '<span class="ranking__icono" aria-hidden="true"><span class="material-symbols-outlined">' . $icono . '</span></span>'
        . '<div class="ranking__texto"><h3 class="ranking__titulo">' . escaparValores($titulo) . '</h3>'
        . '<p class="ranking__sub">' . escaparValores($subtitulo) . '</p></div>'
        . '<span class="ranking__medida">' . ($porMonto ? 'Por monto' : 'Por piezas') . '</span>'
        . '</header>';

    if (!$filas) {
        return $html . '<div class="ranking__vacio"><span class="material-symbols-outlined" aria-hidden="true">query_stats</span><p>Sin datos en este periodo.</p></div></article>';
    }

    $html .= '<ol class="ranking__lista">';
    foreach (array_slice($filas, 0, 5) as $i => $fila) {
        $monto = (int) ($fila['monto'] ?? 0);
        $unidades = (int) ($fila['unidades'] ?? 0);
        $operaciones = (int) ($fila['operaciones'] ?? 0);
        $valorBarra = $porMonto ? $monto : $unidades;
        $barra = max(3, min(100, (int) round(($valorBarra / $max) * 100)));
        $principal = $porMonto ? $m($monto) : number_format($unidades) . ' pzas';
        $posicion = (int) $i + 1;

        if ($tipo === 'producto') {
            $nombre = trim((string) ($fila['sku'] ?? '') . ' ' . (string) ($fila['nombre'] ?? ''));
            $secundario = $porMonto ? number_format($unidades) . ' pzas' : $m($monto);
            $meta = number_format($operaciones) . ' venta' . ($operaciones === 1 ? '' : 's');
        } else {
            $nombre = (string) ($fila['nombre'] ?? '');
            $secundario = ($porMonto ? number_format($unidades) . ' pzas' : $m($monto)) . ' · ' . number_format($operaciones) . ' ops';
            $meta = '';
        }

        $html .= '<li class="ranking__fila">'
            . '<span class="ranking__puesto ranking__puesto--' . $posicion . '">' . $posicion . '</span>'
            . '<div class="ranking__cuerpo">'
            . '<div class="ranking__linea"><span class="ranking__nombre" title="' . escaparValores($nombre) . '">' . escaparValores($nombre) . '</span>'
            . '<strong class="ranking__principal cifra">' . escaparValores($principal) . '</strong></div>'
            . '<div class="ranking__barra" aria-hidden="true"><span style="width:' . $barra . '%"></span></div>'
            . '<div class="ranking__pie"><span>' . escaparValores($secundario) . '</span>'
            . ($meta !== '' ? '<span>' . escaparValores($meta) . '</span>' : '') . '</div>'
            . '</div></li>';
    }
    $html .= '</ol></article>';

    return $html;
};

$GLOBALS['estilosPagina'] = ['/build/css/leaflet.css'];

template('header', [
    'titulo' => 'Estadísticas',
    'subtitulo' => 'Productos, clientes, ventas y gastos',
    'rutaActiva' => '/admin/estadisticas',
]);
?>

<div class="estadisticas-periodos" role="group" aria-label="Periodos rápidos">
    <span class="campo__etiqueta" style="margin:0">Ver:</span>
    <a class="chip" href="<?= escaparValores($urlRapida('mes', (int) date('n'), $hoyAnio)) ?>">Este mes</a>
    <a class="chip" href="<?= escaparValores($urlRapida('anio', 1, $hoyAnio)) ?>">Este año</a>
    <a class="chip" href="<?= escaparValores($urlRapida('anio', 1, $hoyAnio - 1)) ?>">Año pasado</a>
</div>

<form class="tarjeta mb-lg estadisticas-filtros" action="/admin/estadisticas" method="get" aria-label="Filtros de estadísticas">
    <input type="hidden" name="atras" value="2">
    <div class="formulario__rejilla">
        <div class="campo">
            <label class="campo__etiqueta" for="tipo">Periodo</label>
            <select class="control" id="tipo" name="tipo">
                <?php foreach (\App\Estadisticas::TIPOS_PERIODO as $k => $t) : ?>
                    <option value="<?= $k ?>"<?= $p['tipo'] === $k ? ' selected' : '' ?>><?= escaparValores($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="indice">Cuál</label>
            <select class="control" id="indice" name="indice">
                <?php for ($i = 1; $i <= $maximoIndice; $i++) : ?>
                    <option value="<?= $i ?>"<?= $p['indice'] === $i ? ' selected' : '' ?>>
                        <?= escaparValores(\App\Estadisticas::etiquetaPeriodo($p['tipo'], $p['anio'], $i)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="anio">Año</label>
            <select class="control" id="anio" name="anio">
                <?php for ($a = $hoyAnio; $a >= 2020; $a--) : ?>
                    <option value="<?= $a ?>"<?= $p['anio'] === $a ? ' selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="empresa">Negocio</label>
            <select class="control" id="empresa" name="empresa">
                <option value="0">Ambos negocios</option>
                <?php foreach ($empresas as $e) : ?>
                    <option value="<?= (int) $e->id ?>"<?= $f['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="base">Importes</label>
            <select class="control" id="base" name="base">
                <option value="total"<?= $base === 'total' ? ' selected' : '' ?>>Con IVA</option>
                <option value="subtotal"<?= $base === 'subtotal' ? ' selected' : '' ?>>Sin IVA (con descuentos)</option>
            </select>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="orden">Ordenar rankings</label>
            <select class="control" id="orden" name="orden">
                <option value="piezas"<?= $orden === 'piezas' ? ' selected' : '' ?>>Por piezas</option>
                <option value="monto"<?= $orden === 'monto' ? ' selected' : '' ?>>Por monto</option>
            </select>
        </div>
    </div>
    <div class="formulario__acciones">
        <a class="btn btn--texto" href="/admin/estadisticas">Restablecer</a>
        <button class="btn btn--primario" type="submit">Ver estadísticas</button>
    </div>
</form>

<?php if (!$actual['completo']) : ?>
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">hourglass_top</span>
        <span><strong><?= $actual['futuro'] ? 'Periodo futuro.' : 'Periodo incompleto al ' . date('d/m/Y', strtotime($actual['corte'])) . '.' ?></strong>
            <?= $actual['futuro'] ? 'Aún no empieza.' : 'Los datos llegan a esa fecha; la comparación usa el mismo avance del año anterior.' ?></span>
    </p>
<?php endif; ?>

<h2 class="titulo-5 mb-md"><?= escaparValores($etiquetaPeriodo) ?></h2>
<section class="estadisticas-resumen" aria-label="Indicadores del periodo">
    <?php foreach ($r['kpis'] as $k) :
        $vk = $k['variacion'];
        $mejora = $vk && $vk['absoluta'] != 0 ? (($vk['absoluta'] > 0) === ($k['sentido'] === 'directo')) : null;
        $textoCambio = $vk === null ? 'Sin datos para comparar'
            : ($vk['porcentual'] === null ? 'Sin base en ' . ($p['anio'] - 1)
            : ($vk['absoluta'] == 0 ? 'Igual que ' . ($p['anio'] - 1) : ($vk['absoluta'] > 0 ? '▲ +' : '▼ ') . number_format(abs((float) $vk['porcentual']), 1) . ' % vs ' . ($p['anio'] - 1)));
        $iconoKpi = ['realizadas' => 'trending_up', 'cobros' => 'payments', 'facturadas' => 'receipt_long', 'gastos' => 'receipt'][$k['clave']] ?? 'insights';
        ?>
        <div class="indicador" style="--indicador: <?= escaparValores($colorIndicador[$k['clave']]) ?>">
            <span class="indicador__cabecera">
                <span class="indicador__icono" aria-hidden="true"><span class="material-symbols-outlined"><?= $iconoKpi ?></span></span>
                <span class="indicador__etiqueta"><?= escaparValores($k['nombre']) ?></span>
            </span>
            <span class="indicador__valor"><?= $m($k['importe']) ?></span>
            <span class="cambio<?= $mejora === null ? '' : ($mejora ? ' cambio--bien' : ' cambio--mal') ?>"><?= escaparValores($textoCambio) ?></span>
            <span class="indicador__pie"><?= $vk ? ($vk['absoluta'] > 0 ? '+' : '') . $m($vk['absoluta']) . ' contra ' . $m($k['previo']) . ' del ' . ($actual['completo'] ? 'mismo periodo' : 'mismo avance') . ' de ' . ($p['anio'] - 1) : escaparValores($k['detalle']) ?></span>
            <span class="indicador__pie"><?= escaparValores($k['detalle']) ?></span>
        </div>
    <?php endforeach; ?>
</section>
<p class="campo__ayuda mb-lg">
    Una venta se <strong>vende</strong> el día que la haces, se <strong>factura</strong> cuando le das factura y se <strong>cobra</strong> cuando el cliente paga.
    Los rankings usan <strong><?= $orden === 'monto' ? 'monto' : 'piezas' ?></strong>; las comparativas de 3 años usan el año actual y los dos anteriores.
</p>

<nav class="estadisticas-nav" aria-label="Secciones de estadísticas">
    <a class="chip" href="#sec-productos">Productos</a>
    <a class="chip" href="#sec-clientes">Clientes</a>
    <a class="chip" href="#sec-ventas">Ventas y cobros</a>
    <a class="chip" href="#sec-gastos">Gastos</a>
    <a class="chip" href="#sec-anios">3 años</a>
    <a class="chip" href="#mapa-ventas-seccion">Mapa</a>
</nav>

<section class="estadisticas-seccion" id="sec-productos" aria-labelledby="t-productos">
    <div class="estadisticas-seccion__cabecera">
        <div>
            <h2 class="titulo-4" id="t-productos">Productos del periodo</h2>
            <p class="campo__ayuda mb-0">Más y menos vendidos por negocio · <?= escaparValores($etiquetaPeriodo) ?> · importes <?= escaparValores($baseTexto) ?>.</p>
        </div>
        <span class="badge badge--info badge--sin-punto"><?= $orden === 'monto' ? 'Ordenado por monto' : 'Ordenado por piezas' ?></span>
    </div>

    <div class="rankings-grid">
        <?php if (!$empresasProductos) : ?>
            <?= $cardRanking('Productos vendidos', 'Sin ventas en el periodo', 'inventory_2', 'top', [], 'producto') ?>
        <?php else : ?>
            <?php foreach ($empresasProductos as $empresaId => $grupo) :
                $top = $topProductosPorEmpresa[$empresaId] ?? ['negocio' => $grupo['negocio'], 'filas' => []];
                $menos = $menosProductosPorEmpresa[$empresaId] ?? ['negocio' => $grupo['negocio'], 'filas' => []]; ?>
                <?= $cardRanking('Más vendidos · ' . $top['negocio'], 'Top 5 · ' . $etiquetaPeriodo, 'emoji_events', 'top', $top['filas'], 'producto') ?>
                <?= $cardRanking('Menos vendidos · ' . $menos['negocio'], 'Últimos 5 · ' . $etiquetaPeriodo, 'trending_down', 'bottom', $menos['filas'], 'producto') ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="estadisticas-seccion" id="sec-clientes" aria-labelledby="t-clientes">
    <div class="estadisticas-seccion__cabecera">
        <div>
            <h2 class="titulo-4" id="t-clientes">Clientes del periodo</h2>
            <p class="campo__ayuda mb-0">Mayor y menor compra por negocio · <?= escaparValores($etiquetaPeriodo) ?> · importes <?= escaparValores($baseTexto) ?>.</p>
        </div>
        <span class="badge badge--info badge--sin-punto"><?= $orden === 'monto' ? 'Ordenado por monto' : 'Ordenado por piezas' ?></span>
    </div>

    <div class="rankings-grid">
        <?php if (!$empresasClientes) : ?>
            <?= $cardRanking('Clientes', 'Sin compras en el periodo', 'group', 'top', [], 'cliente') ?>
        <?php else : ?>
            <?php foreach ($empresasClientes as $empresaId => $grupo) :
                $top = $topClientesPorEmpresa[$empresaId] ?? ['negocio' => $grupo['negocio'], 'filas' => []];
                $menos = $menosClientesPorEmpresa[$empresaId] ?? ['negocio' => $grupo['negocio'], 'filas' => []]; ?>
                <?= $cardRanking('Mayor compra · ' . $top['negocio'], 'Top 5 · ' . $etiquetaPeriodo, 'workspace_premium', 'top', $top['filas'], 'cliente') ?>
                <?= $cardRanking('Menor compra · ' . $menos['negocio'], 'Últimos 5 · ' . $etiquetaPeriodo, 'person_search', 'bottom', $menos['filas'], 'cliente') ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="estadisticas-seccion" id="sec-ventas" aria-labelledby="t-ventas">
    <div class="estadisticas-seccion__cabecera">
        <div>
            <h2 class="titulo-4" id="t-ventas">Ventas y cobros</h2>
            <p class="campo__ayuda mb-0">Ventas mensuales de 3 años y lo que ya se cobró de las ventas de <?= $p['anio'] ?>.</p>
        </div>
        <span class="badge badge--neutro badge--sin-punto">3 años</span>
    </div>
    <div class="estadisticas-rejilla">
        <?= $tarjetaGrafica($graficaVentas, $resumenVentas, 'estadisticas-rejilla__ancho grafica-tarjeta--hero') ?>
        <?= $tarjetaGrafica($graficaCobros, $resumenCobros, 'estadisticas-rejilla__ancho') ?>
    </div>
</section>

<section class="estadisticas-seccion" id="sec-gastos" aria-labelledby="t-gastos">
    <div class="estadisticas-seccion__cabecera">
        <div>
            <h2 class="titulo-4" id="t-gastos">Gastos</h2>
            <p class="campo__ayuda mb-0">Gastos mensuales comparados entre 3 años · importes <?= escaparValores($baseTexto) ?>.</p>
        </div>
        <span class="badge badge--neutro badge--sin-punto">3 años</span>
    </div>
    <?= $tarjetaGrafica($graficaGastos, $resumenGastos, 'grafica-tarjeta--hero grafica-tarjeta--gastos') ?>
</section>

<section class="estadisticas-seccion" id="sec-anios" aria-labelledby="t-anios">
    <div class="estadisticas-seccion__cabecera">
        <div>
            <h2 class="titulo-4" id="t-anios">Tres años</h2>
            <p class="campo__ayuda mb-0">Productos por piezas y clientes por volumen de compra.</p>
        </div>
        <span class="badge badge--neutro badge--sin-punto"><?= escaparValores($aniosTexto) ?></span>
    </div>
    <div class="estadisticas-rejilla">
        <?= $tarjetaGrafica($graficaProductosAnios, $resumenProductosAnios, 'estadisticas-rejilla__ancho') ?>
        <?= $tarjetaGrafica($graficaClientesAnios, $resumenClientesAnios, 'estadisticas-rejilla__ancho') ?>
    </div>
</section>

<!-- Mapa histórico de ventas: GPS de las fotos de entrega, agrupado a 50 m. -->
<section class="tarjeta mb-lg mapa-ventas" id="mapa-ventas-seccion" aria-labelledby="t-mapa-ventas">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-5" id="t-mapa-ventas">Mapa de ventas</h2>
            <p class="texto-sm texto-secundario mb-0">
                Histórico completo, según el GPS de las fotos de entrega o la ubicación capturada al
                registrar la entrega. Los pines agrupan las ventas a
                <strong><?= (int) $mapaVentas['radio'] ?> m</strong> o menos y muestran cuántas hay en cada lugar.
            </p>
        </div>
        <div class="mapa-ventas__resumen">
            <span class="badge badge--info"><?= number_format($mapaVentas['ventas_ubicadas']) ?> venta<?= $mapaVentas['ventas_ubicadas'] === 1 ? '' : 's' ?> ubicada<?= $mapaVentas['ventas_ubicadas'] === 1 ? '' : 's' ?></span>
            <?php if ($mapaVentas['ventas_sin_ubicacion'] > 0) : ?>
                <span class="badge badge--neutro"><?= number_format($mapaVentas['ventas_sin_ubicacion']) ?> sin ubicación</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($mapaVentas['puntos']) : ?>
        <figure class="mapa-ventas__figura" data-mapa-ventas="<?= escaparValores(json_encode($mapaVentas, JSON_UNESCAPED_UNICODE)) ?>">
            <div class="mapa-ventas__lienzo" id="mapa-ventas" aria-label="Mapa de ventas por ubicación"></div>
            <figcaption class="campo__ayuda">
                Cada número indica las ventas registradas en ese lugar. Toca un pin para ver los clientes y las fechas.
                © colaboradores de OpenStreetMap.
            </figcaption>
        </figure>
    <?php else : ?>
        <p class="nota nota--info" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">location_off</span>
            <span>Aún no hay ventas ubicadas. El mapa aparecerá cuando una foto del acta incluya su GPS o cuando se capture la ubicación al registrar la entrega.</span>
        </p>
    <?php endif; ?>

    <?php if ($mapaVentas['ventas_sin_ubicacion'] > 0) : ?>
        <p class="campo__ayuda mt-sm mb-0">
            <?= number_format($mapaVentas['ventas_sin_ubicacion']) ?> venta<?= $mapaVentas['ventas_sin_ubicacion'] === 1 ? '' : 's' ?> registrada<?= $mapaVentas['ventas_sin_ubicacion'] === 1 ? '' : 's' ?>
            no <?= $mapaVentas['ventas_sin_ubicacion'] === 1 ? 'tiene' : 'tienen' ?> GPS en las fotos ni ubicación capturada en la entrega y no
            <?= $mapaVentas['ventas_sin_ubicacion'] === 1 ? 'aparece' : 'aparecen' ?> en el mapa.
        </p>
    <?php endif; ?>
</section>

<?php $GLOBALS['scriptsPagina'] = ['/build/js/leaflet.js', '/build/js/echarts.min.js']; template('footer'); ?>
