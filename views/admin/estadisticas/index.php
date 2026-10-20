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

// Datos de las gráficas
$etiquetasComp = array_map(static fn ($x) => (string) $x['anio'], $r['comparacion']);
$graficaComp = ['tipo' => 'barras', 'formato' => 'moneda', 'etiquetas' => $etiquetasComp, 'series' => array_values(array_map(
    static fn ($clave, $nombre) => ['nombre' => $nombre, 'valores' => array_map(static fn ($x) => $x[$clave]['importe'] / 100, $r['comparacion'])],
    array_keys(\App\Estadisticas::METRICAS), \App\Estadisticas::METRICAS
))];
if ($conProducto) {
    $graficaComp['series'] = [$graficaComp['series'][0]];
}
$graficaSerie = ['tipo' => count($r['serie']) > 18 ? 'linea' : 'barras', 'formato' => 'moneda', 'etiquetas' => array_map('strval', array_keys($r['serie'])),
    'series' => [['nombre' => 'Ventas realizadas', 'valores' => array_map(static fn ($x) => $x['importe'] / 100, array_values($r['serie']))]]];

template('header', [
    'titulo' => 'Estadísticas de ventas', 'subtitulo' => 'Métricas históricas', 'rutaActiva' => '/admin/estadisticas',
    'acciones' => '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=pdf" target="_blank" rel="noopener">PDF</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=xlsx">Excel</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=csv">CSV</a>',
]);
?>

<form class="tarjeta mb-lg" action="/admin/estadisticas" method="get" aria-label="Filtros de estadísticas">
    <div class="formulario__rejilla">
        <div class="campo"><label class="campo__etiqueta" for="tipo">Periodo a comparar</label>
            <select class="control" id="tipo" name="tipo"><?php foreach (\App\Estadisticas::TIPOS_PERIODO as $k => $t) : ?><option value="<?= $k ?>"<?= $p['tipo'] === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="indice">Cuál</label>
            <select class="control" id="indice" name="indice"><?php for ($i = 1; $i <= $maximoIndice; $i++) : ?><option value="<?= $i ?>"<?= $p['indice'] === $i ? ' selected' : '' ?>><?= escaparValores($etiquetasIndice($p['tipo'], $i)) ?></option><?php endfor; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="anio">Año</label>
            <select class="control" id="anio" name="anio"><?php for ($a = (int) date('Y') + 0; $a >= 2020; $a--) : ?><option value="<?= $a ?>"<?= $p['anio'] === $a ? ' selected' : '' ?>><?= $a ?></option><?php endfor; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="atras">Comparar con años anteriores</label>
            <select class="control" id="atras" name="atras"><?php for ($i = 1; $i <= 4; $i++) : ?><option value="<?= $i ?>"<?= $p['atras'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="empresa">Negocio</label>
            <select class="control" id="empresa" name="empresa"><option value="0">Ambos negocios</option><?php foreach ($empresas as $e) : ?><option value="<?= (int) $e->id ?>"<?= $f['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="cliente">Cliente</label>
            <select class="control" id="cliente" name="cliente"><option value="0">Todos</option><?php foreach ($clientes as $c) : ?><option value="<?= (int) $c->id ?>"<?= $f['cliente'] === (int) $c->id ? ' selected' : '' ?>><?= escaparValores($c->nombre_empresa ?: $c->nombre) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="producto">Producto</label>
            <select class="control" id="producto" name="producto"><option value="0">Todos</option><?php foreach ($productos as $pr) : ?><option value="<?= (int) $pr->id ?>"<?= $f['producto'] === (int) $pr->id ? ' selected' : '' ?>><?= escaparValores(($pr->sku ? $pr->sku . ' · ' : '') . $pr->nombre) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="base">Importes</label>
            <select class="control" id="base" name="base"><option value="total"<?= $p['base'] === 'total' ? ' selected' : '' ?>>Con IVA</option><option value="subtotal"<?= $p['base'] === 'subtotal' ? ' selected' : '' ?>>Sin IVA (con descuentos)</option></select></div>
        <div class="campo"><label class="campo__etiqueta" for="agrupacion">Agrupar la serie por</label>
            <select class="control" id="agrupacion" name="agrupacion"><?php foreach (\App\Estadisticas::AGRUPACIONES as $k => $t) : ?><option value="<?= $k ?>"<?= $p['agrupacion'] === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="formulario__acciones"><a class="btn btn--texto" href="/admin/estadisticas">Restablecer</a><button class="btn btn--primario" type="submit">Actualizar</button></div>
</form>

<?php if ($conProducto) : ?>
    <p class="nota nota--advertencia mb-lg" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>Con un producto elegido se miden <strong>sus renglones</strong> (sin IVA ni descuento global). Las ventas facturadas y los cobros no se reparten por producto y aparecen en cero.</span></p>
<?php endif; ?>

<?php if (!$actual['completo']) : ?>
    <p class="nota nota--advertencia mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">hourglass_top</span>
        <span><strong><?= $actual['futuro'] ? 'Periodo futuro.' : 'Periodo incompleto.' ?></strong>
            <?= $actual['futuro'] ? 'Aún no empieza.' : 'Los datos llegan al ' . date('d/m/Y', strtotime($actual['corte'])) . '. Compararlo con un periodo completo exagera la caída: usa la variación «mismo avance».' ?></span></p>
<?php endif; ?>

<h2 class="titulo-5 mb-md"><?= escaparValores($etiquetaPeriodo) ?></h2>
<section class="rejilla-stats mb-lg" aria-label="Medidas del periodo">
    <div class="stat"><span class="stat__etiqueta">Ventas realizadas</span><span class="stat__valor"><?= $m($actual['realizadas']['importe']) ?></span>
        <span class="stat__pie"><?= number_format($actual['realizadas']['operaciones']) ?> operaciones · <?= number_format($actual['realizadas']['unidades']) ?> piezas</span></div>
    <div class="stat"><span class="stat__etiqueta">Ventas facturadas</span><span class="stat__valor"><?= $m($actual['facturadas']['importe']) ?></span>
        <span class="stat__pie"><?= number_format($actual['facturadas']['operaciones']) ?> operaciones marcadas como facturadas</span></div>
    <div class="stat"><span class="stat__etiqueta">Cobros recibidos</span><span class="stat__valor"><?= $m($actual['cobros']['importe']) ?></span>
        <span class="stat__pie"><?= number_format($actual['cobros']['operaciones']) ?> cobros en el periodo (con IVA)</span></div>
    <div class="stat"><span class="stat__etiqueta">Tendencia (serie mensual)</span><span class="stat__valor texto-sm"><?= escaparValores($r['tendencia']['etiqueta']) ?></span>
        <span class="stat__pie"><?= $r['tendencia']['direccion'] === 'sin_datos' ? '' : escaparValores(($r['tendencia']['pendiente_pct'] > 0 ? '+' : '') . $r['tendencia']['pendiente_pct'] . ' % por mes') ?></span></div>
</section>
<p class="campo__ayuda mb-lg">No son lo mismo: una venta se <strong>realiza</strong> el día que se vende, se <strong>factura</strong> cuando la marcas como facturada y se <strong>cobra</strong> cuando el cliente paga (puede ser otro mes).</p>

<div class="estadisticas-rejilla">
    <section>
        <h2 class="titulo-5 mb-md">Comparación entre años · <?= escaparValores(\App\Estadisticas::TIPOS_PERIODO[$p['tipo']]) ?></h2>
        <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaComp, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Comparación de <?= escaparValores($etiquetaPeriodo) ?> entre años"></figure>
    </section>
    <section>
        <h2 class="titulo-5 mb-md">Ventas realizadas por <?= escaparValores(mb_strtolower(\App\Estadisticas::AGRUPACIONES[$p['agrupacion']])) ?></h2>
        <?php if ($r['serie']) : ?>
            <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaSerie, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Serie de ventas realizadas"></figure>
        <?php else : ?>
            <div class="vacio"><p class="vacio__titulo">Sin ventas en este rango</p></div>
        <?php endif; ?>
    </section>
</div>

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
                <caption class="solo-lectores">Productos más vendidos</caption>
                <thead><tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Piezas</th><th scope="col" class="tabla--numerica">Importe (sin IVA)</th><th scope="col" class="tabla--numerica">Ventas</th></tr></thead>
                <tbody>
                    <?php foreach ($r['productos'] as $x) : ?>
                        <tr><td><strong><?= escaparValores($x['nombre']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($x['sku']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= number_format($x['unidades']) ?></td><td class="tabla--numerica cifra"><?= $m($x['importe']) ?></td><td class="tabla--numerica cifra"><?= number_format($x['operaciones']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$r['productos']) : ?><tr><td colspan="4" class="tabla--centro texto-secundario">Sin ventas en el periodo.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Clientes con mayor volumen · <?= escaparValores($etiquetaPeriodo) ?></h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Clientes con mayor volumen de compra</caption>
                <thead><tr><th scope="col">Cliente</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col" class="tabla--numerica">Ventas</th><th scope="col" class="tabla--numerica">Piezas</th></tr></thead>
                <tbody>
                    <?php foreach ($r['clientes'] as $x) : ?>
                        <tr><td><strong><?= escaparValores($x['nombre']) ?></strong></td><td class="tabla--numerica cifra"><?= $m($x['importe']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($x['operaciones']) ?></td><td class="tabla--numerica cifra"><?= number_format($x['unidades']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$r['clientes']) : ?><tr><td colspan="4" class="tabla--centro texto-secundario">Sin ventas en el periodo.</td></tr><?php endif; ?>
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

<?php template('footer'); ?>
