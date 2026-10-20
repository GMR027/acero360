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


// Gráficas de rankings (barras horizontales)
$graficaProductos = ['tipo' => 'barras-h', 'formato' => 'moneda', 'etiquetas' => array_map(static fn ($x) => (string) $x['nombre'], $r['productos']),
    'series' => [['nombre' => 'Importe', 'valores' => array_map(static fn ($x) => $x['importe'] / 100, $r['productos'])]]];
$graficaClientes = ['tipo' => 'barras-h', 'formato' => 'moneda', 'etiquetas' => array_map(static fn ($x) => (string) $x['nombre'], $r['clientes']),
    'series' => [['nombre' => 'Importe', 'valores' => array_map(static fn ($x) => $x['importe'] / 100, $r['clientes'])]]];
$graficaComp['destacar'] = 'ultimo';
// En la comparación entre años solo se dibujan ventas y cobros (lo demás queda en la tabla).
if (!$conProducto) {
    $graficaComp['series'] = [$graficaComp['series'][0], $graficaComp['series'][2] ?? $graficaComp['series'][1]];
    $graficaComp['destacar'] = '';
}
$graficaComp['series'] = array_values($graficaComp['series']);

/** Frase de variación contra el año anterior, en palabras. */
$frase = static function (?array $v, string $anterior): string {
    if ($v === null || $v['porcentual'] === null) {
        return 'Sin año anterior con qué comparar';
    }
    if ($v['absoluta'] == 0) {
        return 'Igual que ' . $anterior;
    }
    return ($v['absoluta'] > 0 ? '▲ ' : '▼ ') . number_format(abs($v['porcentual']), 1) . ' % ' . ($v['absoluta'] > 0 ? 'más' : 'menos') . ' que ' . $anterior;
};
$anteriorTexto = (string) ($p['anio'] - 1);
$fraseVentas = $frase($actual['variacion_mismo_avance'] ?? $actual['variacion'] ?? null, $anteriorTexto);
$hoyAnio = (int) date('Y');

template('header', [
    'titulo' => 'Estadísticas', 'subtitulo' => 'Cómo van tus ventas', 'rutaActiva' => '/admin/estadisticas',
    'acciones' => '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=pdf" target="_blank" rel="noopener">PDF</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=xlsx">Excel</a>'
        . '<a class="btn btn--secundario" href="/admin/estadisticas/exportar?' . escaparValores($consulta) . '&formato=csv">CSV</a>',
]);
?>

<div class="estadisticas-periodos" role="group" aria-label="Periodos rápidos">
    <span class="campo__etiqueta" style="margin:0">Ver:</span>
    <a class="chip" href="/admin/estadisticas?tipo=mes&indice=<?= (int) date('n') ?>&anio=<?= $hoyAnio ?>&empresa=<?= (int) $f['empresa'] ?>">Este mes</a>
    <a class="chip" href="/admin/estadisticas?tipo=anio&anio=<?= $hoyAnio ?>&empresa=<?= (int) $f['empresa'] ?>">Este año</a>
    <a class="chip" href="/admin/estadisticas?tipo=anio&anio=<?= $hoyAnio - 1 ?>&empresa=<?= (int) $f['empresa'] ?>">Año pasado</a>
</div>

<form class="tarjeta mb-lg" action="/admin/estadisticas" method="get" aria-label="Filtros de estadísticas">
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
<section class="estadisticas-resumen" aria-label="Medidas del periodo">
    <div class="tarjeta"><span class="stat__etiqueta">Vendiste</span>
        <span class="estadisticas-resumen__valor"><?= $m($actual['realizadas']['importe']) ?></span>
        <span class="stat__pie"><?= escaparValores($fraseVentas) ?></span>
        <span class="stat__pie"><?= number_format($actual['realizadas']['operaciones']) ?> ventas · <?= number_format($actual['realizadas']['unidades']) ?> piezas</span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Cobraste</span>
        <span class="estadisticas-resumen__valor"><?= $m($actual['cobros']['importe']) ?></span>
        <span class="stat__pie"><?= number_format($actual['cobros']['operaciones']) ?> pagos recibidos</span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Facturaste</span>
        <span class="estadisticas-resumen__valor"><?= $m($actual['facturadas']['importe']) ?></span>
        <span class="stat__pie"><?= number_format($actual['facturadas']['operaciones']) ?> ventas con factura</span></div>
</section>
<p class="campo__ayuda mb-lg">Una venta se <strong>vende</strong> el día que la haces, se <strong>factura</strong> cuando le das factura y se <strong>cobra</strong> cuando el cliente paga (puede ser otro mes).
    <?= $r['tendencia']['direccion'] === 'sin_datos' ? '' : escaparValores($r['tendencia']['etiqueta']) . '.' ?></p>

<div class="estadisticas-rejilla">
    <section>
        <h2 class="titulo-5 mb-md">Ventas por <?= escaparValores(mb_strtolower(\App\Estadisticas::AGRUPACIONES[$p['agrupacion']])) ?></h2>
        <?php if ($r['serie']) : ?>
            <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaSerie, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Serie de ventas realizadas"></figure>
        <?php else : ?>
            <div class="vacio"><p class="vacio__titulo">Sin ventas en este rango</p></div>
        <?php endif; ?>
    </section>
    <section>
        <h2 class="titulo-5 mb-md">Año contra año · <?= escaparValores(\App\Estadisticas::TIPOS_PERIODO[$p['tipo']]) ?></h2>
        <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaComp, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Comparación de <?= escaparValores($etiquetaPeriodo) ?> entre años"></figure>
    </section>
    <section>
        <h2 class="titulo-5 mb-md">Productos que más vendes</h2>
        <?php if ($r['productos']) : ?>
            <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaProductos, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Productos más vendidos"></figure>
        <?php else : ?><div class="vacio"><p class="vacio__titulo">Sin ventas en el periodo</p></div><?php endif; ?>
    </section>
    <section>
        <h2 class="titulo-5 mb-md">Clientes que más compran</h2>
        <?php if ($r['clientes']) : ?>
            <figure class="grafica" data-grafica="<?= escaparValores(json_encode($graficaClientes, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Clientes con mayor volumen"></figure>
        <?php else : ?><div class="vacio"><p class="vacio__titulo">Sin ventas en el periodo</p></div><?php endif; ?>
    </section>
</div>

<details class="mb-lg">
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

    </div>
</details>

<?php template('footer'); ?>
