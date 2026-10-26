<?php
/**
 * views/admin/estadisticas/index.php — Métricas comerciales históricas (solo lectura).
 *
 * Ventas realizadas, ventas facturadas y cobros recibidos son medidas DISTINTAS y se muestran
 * por separado. Las ventas salen de una sola tabla: una cotización vendida cuenta una vez.
 */

soloAdmin();

$p        = \App\Estadisticas::parametros($_GET);
$r        = \App\Estadisticas::reporte($p);
$f        = $p['filtros'];
$empresas = \App\Empresas::mostrarTodos(100, 0);
$clientes = \App\Clientes::mostrarTodos(1000, 0, '');
$productos = \App\Productos::mostrarTodos(500, 0);
$actual   = $r['actual'];
$m        = static fn (int $c): string => formatoMoneda($c / 100);
$consulta = http_build_query(['tipo' => $p['tipo'], 'indice' => $p['indice'], 'anio' => $p['anio'], 'atras' => $p['atras'], 'agrupacion' => $p['agrupacion'],
    'base' => $p['base'], 'empresa' => $f['empresa'], 'cliente' => $f['cliente'], 'producto' => $f['producto']]);
$conProducto = $f['producto'] > 0;
$etiquetaPeriodo = \App\Estadisticas::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']);

/** Variación con color y signo. */
$variacion = static function (?array $v): string {
    if ($v === null) {
        return '<span class="texto-secundario">—</span>';
    }
    $clase = $v['absoluta'] > 0 ? 'variacion--sube' : ($v['absoluta'] < 0 ? 'variacion--baja' : '');
    $signo = $v['absoluta'] > 0 ? '+' : '';
    $pct = $v['porcentual'] === null ? 'sin base de comparación' : $signo . number_format($v['porcentual'], 2) . ' %';

    return '<span class="variacion ' . $clase . '">' . $signo . formatoMoneda($v['absoluta'] / 100) . '</span><span class="texto-xs texto-secundario"><br>' . escaparValores($pct) . '</span>';
};

$maximoIndice = ['mes' => 12, 'trimestre' => 4, 'semestre' => 2, 'anio' => 1][$p['tipo']];
$etiquetasIndice = static fn (string $tipo, int $i): string => match ($tipo) {
    'mes' => nombreMes($i), 'trimestre' => 'Trimestre ' . $i, 'semestre' => $i === 1 ? 'Primer semestre' : 'Segundo semestre', default => 'Año completo',
};

$anteriorTexto = (string) ($p['anio'] - 1);
$graficas = [];
foreach ($r['graficas'] as $g) { $graficas[$g['id']] = $g; }
$anchas = ['ventas-serie', 'ventas-anios', 'ventas-vs-cobros', 'negocios', 'gastos-anios'];
$secciones = [
    'ventas'    => ['Ventas', 'Cómo se mueven tus ventas mes a mes, año contra año y por semestre.'],
    'ingresos'  => ['Ingresos, cobros y facturación', 'Lo que vendiste, lo que ya cobraste y lo que falta por cobrar o facturar.'],
    'gastos'    => ['Gastos', 'Cuánto gastas, cómo cambia contra otros años y en qué categorías.'],
    'negocios'  => ['Negocios y clientes', 'Comparación entre tus negocios y quiénes compran más.'],
    'productos' => ['Productos', 'Los que más y los que menos se mueven en el periodo.'],
];
$negocios = $r['analisis']['negocios'];
$totalNegocios = array_sum(array_column($negocios, 'periodo'));
$sinMovimiento = $r['analisis']['sin_movimiento'];
$colorIndicador = ['realizadas' => \App\Analisis::COLOR['ventas'], 'cobros' => \App\Analisis::COLOR['cobros'], 'facturadas' => \App\Analisis::COLOR['facturada'], 'gastos' => \App\Analisis::COLOR['gastos']];

/** Tarjeta de una gráfica: título, subtítulo y lienzo (la gráfica la dibuja graficas.js). */
$tarjetaGrafica = static function (array $g) use ($anchas): string {
    return '<section class="grafica-tarjeta' . (in_array($g['id'], $anchas, true) ? ' estadisticas-rejilla__ancho' : '') . '" id="g-' . escaparValores($g['id']) . '">'
        . '<header><h3 class="grafica-tarjeta__titulo">' . escaparValores($g['titulo']) . '</h3><p class="grafica-tarjeta__subtitulo">' . escaparValores($g['subtitulo']) . '</p></header>'
        . '<figure class="grafica" data-grafica="' . escaparValores(json_encode($g, JSON_UNESCAPED_UNICODE)) . '" aria-label="' . escaparValores($g['titulo']) . '"></figure></section>';
};
$vacio = static fn (string $texto): string => '<div class="vacio"><p class="vacio__titulo">' . escaparValores($texto) . '</p></div>';

$hoyAnio = (int) date('Y');

template('header', [
    'titulo' => 'Estadísticas', 'subtitulo' => 'Cómo van tus ventas', 'rutaActiva' => '/admin/estadisticas',
    'acciones' => '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=pdf">PDF</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=xlsx">Excel</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=csv">CSV</a>',
]);
?>

<div class="estadisticas-periodos movil-oculto" role="group" aria-label="Periodos rápidos">
    <span class="campo__etiqueta" style="margin:0">Ver:</span>
    <a class="chip" href="/admin/estadisticas?tipo=mes&indice=<?= (int) date('n') ?>&anio=<?= $hoyAnio ?>&empresa=<?= (int) $f['empresa'] ?>">Este mes</a>
    <a class="chip" href="/admin/estadisticas?tipo=anio&anio=<?= $hoyAnio ?>&empresa=<?= (int) $f['empresa'] ?>">Este año</a>
    <a class="chip" href="/admin/estadisticas?tipo=anio&anio=<?= $hoyAnio - 1 ?>&empresa=<?= (int) $f['empresa'] ?>">Año pasado</a>
</div>

<form class="tarjeta mb-lg movil-oculto" action="/admin/estadisticas" method="get" aria-label="Filtros de estadísticas">
    <div class="formulario__rejilla">
        <div class="campo"><label class="campo__etiqueta" data-ayuda="Compara un mes, trimestre, semestre o el año completo." for="tipo">Periodo</label>
            <select class="control" id="tipo" name="tipo"><?php foreach (\App\Estadisticas::TIPOS_PERIODO as $k => $t) : ?><option value="<?= $k ?>"<?= $p['tipo'] === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="indice">Cuál</label>
            <select class="control" id="indice" name="indice"><?php for ($i = 1; $i <= $maximoIndice; $i++) : ?><option value="<?= $i ?>"<?= $p['indice'] === $i ? ' selected' : '' ?>><?= escaparValores($etiquetasIndice($p['tipo'], $i)) ?></option><?php endfor; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="anio">Año</label>
            <select class="control" id="anio" name="anio"><?php for ($a = $hoyAnio; $a >= 2020; $a--) : ?><option value="<?= $a ?>"<?= $p['anio'] === $a ? ' selected' : '' ?>><?= $a ?></option><?php endfor; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="empresa">Negocio</label>
            <select class="control" id="empresa" name="empresa"><option value="0">Ambos negocios</option><?php foreach ($empresas as $e) : ?><option value="<?= (int) $e->id ?>"<?= $f['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option><?php endforeach; ?></select></div>
    </div>
    <details class="mt-md">
        <summary class="boton-enlace" style="cursor:pointer">Más filtros (cliente, producto, años a comparar…)</summary>
        <div class="formulario__rejilla mt-md">
            <div class="campo"><label class="campo__etiqueta" for="cliente">Cliente</label>
                <select class="control" id="cliente" name="cliente"><option value="0">Todos</option><?php foreach ($clientes as $c) : ?><option value="<?= (int) $c->id ?>"<?= $f['cliente'] === (int) $c->id ? ' selected' : '' ?>><?= escaparValores($c->nombre_empresa ?: $c->nombre) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label class="campo__etiqueta" for="producto">Producto</label>
                <select class="control" id="producto" name="producto"><option value="0">Todos</option><?php foreach ($productos as $pr) : ?><option value="<?= (int) $pr->id ?>"<?= $f['producto'] === (int) $pr->id ? ' selected' : '' ?>><?= escaparValores(($pr->sku ? $pr->sku . ' · ' : '') . $pr->nombre) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label class="campo__etiqueta" for="atras">Comparar con cuántos años anteriores</label>
                <select class="control" id="atras" name="atras"><?php for ($i = 1; $i <= 4; $i++) : ?><option value="<?= $i ?>"<?= $p['atras'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
            <div class="campo"><label class="campo__etiqueta" for="base">Importes</label>
                <select class="control" id="base" name="base"><option value="total"<?= $p['base'] === 'total' ? ' selected' : '' ?>>Con IVA</option><option value="subtotal"<?= $p['base'] === 'subtotal' ? ' selected' : '' ?>>Sin IVA (con descuentos)</option></select></div>
            <div class="campo"><label class="campo__etiqueta" for="agrupacion">Agrupar la gráfica por</label>
                <select class="control" id="agrupacion" name="agrupacion"><?php foreach (\App\Estadisticas::AGRUPACIONES as $k => $t) : ?><option value="<?= $k ?>"<?= $p['agrupacion'] === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        </div>
    </details>
    <div class="formulario__acciones"><a class="btn btn--texto" href="/admin/estadisticas">Restablecer</a><button class="btn btn--primario" type="submit">Ver estadísticas</button></div>
</form>

<?php if ($conProducto) : ?>
    <p class="nota nota--advertencia mb-lg" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>Con un producto elegido se miden <strong>sus renglones</strong> (sin IVA ni descuento global). Las ventas facturadas y los cobros no se reparten por producto y aparecen en cero.</span></p>
<?php endif; ?>

<?php if (!$actual['completo']) : ?>
    <p class="nota nota--advertencia mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">hourglass_top</span>
        <span><strong><?= $actual['futuro'] ? 'Periodo futuro.' : 'Periodo incompleto.' ?></strong>
            <?= $actual['futuro'] ? 'Aún no empieza.' : 'Los datos llegan al ' . date('d/m/Y', strtotime($actual['corte'])) . '; la comparación usa el mismo avance del año anterior.' ?></span></p>
<?php endif; ?>

<h2 class="titulo-5 mb-md"><?= escaparValores($etiquetaPeriodo) ?></h2>
<section class="estadisticas-resumen" aria-label="Indicadores del periodo">
    <?php foreach ($r['kpis'] as $k) :
        $vk = $k['variacion'];
        $mejora = $vk && $vk['absoluta'] != 0 ? (($vk['absoluta'] > 0) === ($k['sentido'] === 'directo')) : null;
        $textoCambio = $vk === null ? 'Sin datos para comparar'
            : ($vk['porcentual'] === null ? 'Sin base en ' . $anteriorTexto
            : ($vk['absoluta'] == 0 ? 'Igual que ' . $anteriorTexto : ($vk['absoluta'] > 0 ? '▲ +' : '▼ ') . number_format($vk['porcentual'], 1) . ' % vs ' . $anteriorTexto));
    ?>
        <div class="indicador" style="--indicador: <?= escaparValores($colorIndicador[$k['clave']]) ?>">
            <span class="indicador__etiqueta"><?= escaparValores($k['nombre']) ?></span>
            <span class="indicador__valor"><?= $m($k['importe']) ?></span>
            <span class="cambio<?= $mejora === null ? '' : ($mejora ? ' cambio--bien' : ' cambio--mal') ?>"><?= escaparValores($textoCambio) ?></span>
            <span class="indicador__pie"><?= $vk ? ($vk['absoluta'] > 0 ? '+' : '') . $m($vk['absoluta']) . ' contra ' . $m($k['previo']) . ' del ' . ($actual['completo'] ? 'mismo periodo' : 'mismo avance') . ' de ' . $anteriorTexto : escaparValores($k['detalle']) ?></span>
            <span class="indicador__pie"><?= escaparValores($k['detalle']) ?></span>
        </div>
    <?php endforeach; ?>
</section>
<p class="campo__ayuda mb-lg">Una venta se <strong>vende</strong> el día que la haces, se <strong>factura</strong> cuando le das factura y se <strong>cobra</strong> cuando el cliente paga (puede ser otro mes); por eso van en gráficas y series separadas.
    <?= $r['tendencia']['direccion'] === 'sin_datos' ? '' : escaparValores($r['tendencia']['etiqueta']) . '.' ?>
    <?= $f['empresa'] > 0 ? 'Con un negocio elegido, sus gastos incluyen su parte de los compartidos.' : '' ?></p>

<nav class="estadisticas-nav" aria-label="Secciones de estadísticas">
    <?php foreach ($secciones as $clave => [$titulo]) : ?><a class="chip" href="#sec-<?= $clave ?>"><?= escaparValores($titulo) ?></a><?php endforeach; ?>
    <a class="chip movil-oculto" href="#tablas">Tablas</a>
</nav>

<?php foreach ($secciones as $clave => [$titulo, $descripcion]) : $deSeccion = array_filter($graficas, static fn ($g) => $g['seccion'] === $clave); ?>
    <section class="estadisticas-seccion" id="sec-<?= $clave ?>" aria-labelledby="t-<?= $clave ?>">
        <h2 class="titulo-4" id="t-<?= $clave ?>"><?= escaparValores($titulo) ?></h2>
        <p class="campo__ayuda mb-md"><?= escaparValores($descripcion) ?></p>
        <?php if (!$deSeccion) : ?>
            <?= $vacio($conProducto && $clave === 'ingresos' ? 'Con un producto elegido no se reparten cobros ni facturación.' : 'Sin datos para esta sección con los filtros elegidos.') ?>
        <?php else : ?>
            <div class="estadisticas-rejilla">
                <?php foreach ($deSeccion as $g) : echo $tarjetaGrafica($g); endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($clave === 'negocios' && $negocios) : ?>
            <div class="tarjeta tarjeta--sin-relleno">
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <caption>Comparación entre negocios · <?= escaparValores($etiquetaPeriodo) ?> (sin filtro de negocio)</caption>
                        <thead><tr><th scope="col">Negocio</th><th scope="col" class="tabla--numerica">Ventas del periodo</th><th scope="col" class="tabla--numerica">Operaciones</th>
                            <th scope="col" class="tabla--numerica">Mismo avance <?= $anteriorTexto ?></th><th scope="col" class="tabla--numerica">Variación</th><th scope="col" class="tabla--numerica">Participación</th></tr></thead>
                        <tbody>
                            <?php foreach ($negocios as $n) : $vn = \App\Estadisticas::variacion($n['previo'], $n['periodo']); ?>
                                <tr><td><strong><?= escaparValores($n['nombre']) ?></strong></td><td class="tabla--numerica cifra"><?= $m($n['periodo']) ?></td><td class="tabla--numerica cifra"><?= number_format($n['ventas']) ?></td>
                                    <td class="tabla--numerica cifra"><?= $m($n['previo']) ?></td><td class="tabla--numerica"><?= $variacion($vn) ?></td>
                                    <td class="tabla--numerica cifra"><?= $totalNegocios > 0 ? number_format($n['periodo'] * 100 / $totalNegocios, 1) . ' %' : '—' ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php foreach (\App\Estadisticas::agruparPorNegocio($r['productos']) as $grupoProductos) : ?>
                <div class="tarjeta tarjeta--sin-relleno mt-md">
                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <caption>Ventas por negocio · productos de <?= escaparValores($grupoProductos['negocio']) ?> · <?= escaparValores($etiquetaPeriodo) ?></caption>
                            <thead><tr><th scope="col">Código</th><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Piezas</th><th scope="col" class="tabla--numerica">Importe (sin IVA)</th><th scope="col" class="tabla--numerica">Ventas</th></tr></thead>
                            <tbody>
                                <?php foreach ($grupoProductos['filas'] as $x) : ?>
                                    <tr><td><strong><?= escaparValores($x['sku'] !== '' ? $x['sku'] : '—') ?></strong></td><td><?= escaparValores($x['nombre']) ?></td>
                                        <td class="tabla--numerica cifra"><?= number_format($x['unidades']) ?></td><td class="tabla--numerica cifra"><?= $m($x['importe']) ?></td><td class="tabla--numerica cifra"><?= number_format($x['operaciones']) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($r['productos']) : ?><p class="campo__ayuda">Los productos con más importe de cada negocio en el periodo, con su código (SKU).</p><?php endif; ?>
        <?php endif; ?>

        <?php if ($clave === 'productos') : ?>
            <p class="campo__ayuda mt-md"><strong><?= number_format($sinMovimiento['total']) ?></strong> producto(s) activo(s) no tuvieron ninguna venta en este periodo.
                <?php if ($sinMovimiento['lista']) : ?>Los de mayor existencia: <?= escaparValores(implode(', ', array_map(static fn ($x) => $x['nombre'] . ' (' . $x['stock'] . ')', $sinMovimiento['lista']))) ?>.<?php endif; ?></p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<details class="mb-lg movil-oculto" id="tablas">
    <summary class="boton-enlace" style="cursor:pointer"><strong>Ver las tablas con todos los números</strong></summary>
    <div class="mt-md">
<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption>Comparación de <?= escaparValores(mb_strtolower($etiquetaPeriodo)) ?> con años anteriores</caption>
            <thead><tr><th scope="col">Periodo</th><th scope="col" class="tabla--numerica">Realizadas</th><th scope="col" class="tabla--numerica">Operaciones</th><th scope="col" class="tabla--numerica">Piezas</th>
                <th scope="col" class="tabla--numerica">Variación vs año anterior</th><th scope="col" class="tabla--numerica">Facturadas</th><th scope="col" class="tabla--numerica">Cobros</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($r['comparacion']) as $x) : ?>
                    <tr>
                        <td><strong><?= escaparValores($x['etiqueta']) ?></strong>
                            <?= $x['futuro'] ? '<span class="badge badge--neutro">Futuro</span>' : (!$x['completo'] ? '<span class="badge badge--pendiente">Incompleto al ' . date('d/m', strtotime($x['corte'])) . '</span>' : '') ?></td>
                        <td class="tabla--numerica cifra"><?= $m($x['realizadas']['importe']) ?></td>
                        <td class="tabla--numerica cifra"><?= number_format($x['realizadas']['operaciones']) ?></td>
                        <td class="tabla--numerica cifra"><?= number_format($x['realizadas']['unidades']) ?></td>
                        <td class="tabla--numerica"><?= $variacion($x['variacion']) ?>
                            <?php if (!empty($x['variacion_mismo_avance'])) : ?><span class="texto-xs texto-secundario"><br>Mismo avance del año anterior:</span><br><?= $variacion($x['variacion_mismo_avance']) ?><?php endif; ?></td>
                        <td class="tabla--numerica cifra"><?= $m($x['facturadas']['importe']) ?></td>
                        <td class="tabla--numerica cifra"><?= $m($x['cobros']['importe']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="estadisticas-rejilla">
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Productos más vendidos · <?= escaparValores($etiquetaPeriodo) ?></h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Productos más vendidos, separados por negocio</caption>
                <thead><tr><th scope="col">Negocio</th><th scope="col">Código</th><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Piezas</th><th scope="col" class="tabla--numerica">Importe (sin IVA)</th><th scope="col" class="tabla--numerica">Ventas</th></tr></thead>
                <tbody>
                    <?php foreach ($r['productos'] as $x) : ?>
                        <tr><td><?= escaparValores($x['negocio']) ?></td><td><strong><?= escaparValores($x['sku'] !== '' ? $x['sku'] : '—') ?></strong></td><td><?= escaparValores($x['nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($x['unidades']) ?></td><td class="tabla--numerica cifra"><?= $m($x['importe']) ?></td><td class="tabla--numerica cifra"><?= number_format($x['operaciones']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$r['productos']) : ?><tr><td colspan="6" class="tabla--centro texto-secundario">Sin ventas en el periodo.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Clientes con mayor volumen · <?= escaparValores($etiquetaPeriodo) ?></h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Clientes con mayor volumen de compra, separados por negocio</caption>
                <thead><tr><th scope="col">Negocio</th><th scope="col">Cliente</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col" class="tabla--numerica">Ventas</th><th scope="col" class="tabla--numerica">Piezas</th></tr></thead>
                <tbody>
                    <?php foreach ($r['clientes'] as $x) : ?>
                        <tr><td><?= escaparValores($x['negocio']) ?></td><td><strong><?= escaparValores($x['nombre']) ?></strong></td><td class="tabla--numerica cifra"><?= $m($x['importe']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($x['operaciones']) ?></td><td class="tabla--numerica cifra"><?= number_format($x['unidades']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$r['clientes']) : ?><tr><td colspan="5" class="tabla--centro texto-secundario">Sin ventas en el periodo.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php foreach (['matriz_productos' => 'Ventas por producto durante varios años (sin IVA)', 'matriz_clientes' => 'Ventas por cliente durante varios años'] as $clave => $titulo) : $mat = $r[$clave]; ?>
    <section class="tarjeta tarjeta--sin-relleno mb-lg">
        <h2 class="titulo-5" style="padding:1rem 1rem 0"><?= $titulo ?></h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores"><?= $titulo ?></caption>
                <thead><tr><th scope="col"><?= $clave === 'matriz_productos' ? 'Producto' : 'Cliente' ?></th>
                    <?php foreach ($mat['anios'] as $a) : ?><th scope="col" class="tabla--numerica"><?= $a ?></th><?php endforeach; ?><th scope="col" class="tabla--numerica">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($mat['filas'] as $x) : ?>
                        <tr><td><?= escaparValores($x['nombre']) ?></td>
                            <?php foreach ($mat['anios'] as $a) : ?><td class="tabla--numerica cifra"><?= $m($x['por_anio'][$a]) ?></td><?php endforeach; ?>
                            <td class="tabla--numerica cifra"><strong><?= $m($x['total']) ?></strong></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$mat['filas']) : ?><tr><td colspan="<?= count($mat['anios']) + 2 ?>" class="tabla--centro texto-secundario">Sin ventas en estos años.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<h3 class="titulo-5 mb-md">Datos de cada gráfica</h3>
<?php foreach ($graficas as $g) : [$enc, $filasG, $totalG] = \App\Analisis::tabla($g); $monedaG = $g['formato'] === 'moneda'; $nSeries = count($g['series']); $nExtra = count($g['extra'] ?? []); ?>
    <section class="tarjeta tarjeta--sin-relleno mb-lg">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption><?= escaparValores($g['titulo']) ?> — <?= escaparValores($g['subtitulo']) ?></caption>
                <thead><tr><?php foreach ($enc as $i => $t) : ?><th scope="col"<?= $i ? ' class="tabla--numerica"' : '' ?>><?= escaparValores($t) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach (array_merge($filasG, $totalG ? [$totalG] : []) as $fila) : ?>
                        <tr><?php foreach ($fila as $i => $c) : ?>
                            <?php if ($i === 0) : ?><td><?= $c === 'Total' ? '<strong>Total</strong>' : escaparValores((string) $c) ?></td>
                            <?php else : ?><td class="tabla--numerica cifra"><?= $c === null ? '—'
                                : ($i > $nSeries + $nExtra ? number_format((float) $c, 2) . ' %'
                                : ($i > $nSeries ? number_format((float) $c) : ($monedaG ? formatoMoneda((float) $c) : number_format((float) $c)))) ?></td><?php endif; ?>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

    </div>
</details>

<?php $GLOBALS['scriptsPagina'] = ['/build/js/echarts.min.js']; template('footer'); ?>
