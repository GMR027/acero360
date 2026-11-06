<?php
/**
 * views/admin/analisis-financiero.php — Primer apartado de Análisis Financiero.
 *
 * Balance entre el inventario y las deudas:
 *   · inventario valuado al precio de catálogo, en la base elegida;
 *   · pedidos de proveedor con saldo pendiente, de todos los meses;
 *   · gastos operativos pendientes del mes (opcional).
 *
 * El selector «Comparar con» decide si todo se compara con IVA (precio de
 * venta) o sin IVA (precio de lista). Las tablas siempre muestran ambas
 * columnas para poder revisar el desglose.
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$empresas = \App\Empresas::mostrarTodos(100, 0);

$empresasActivas = array_values(array_filter($empresas, static fn ($e) => (int) $e->activa === 1));
$idsActivos = array_map(static fn ($e) => (int) $e->id, $empresasActivas);

$seleccion = isset($_GET['negocios']) && is_array($_GET['negocios'])
    ? array_values(array_intersect(array_map('intval', $_GET['negocios']), $idsActivos))
    : [];
// Sin selección se analizan todos los negocios activos (nunca una pantalla vacía).
if (!$seleccion) {
    $seleccion = $idsActivos;
}

$base = (string) ($_GET['base'] ?? 'venta') === 'lista' ? 'lista' : 'venta';
$modo = (string) ($_GET['modo'] ?? 'simple') === 'detallado' ? 'detallado' : 'simple';
$restarProveedores = (string) ($_GET['proveedores'] ?? '1') === '1';
$restarGastos = (string) ($_GET['gastos'] ?? ($modo === 'detallado' ? '1' : '0')) === '1';

$r = \App\AnalisisFinanciero::resumen(
    $periodo['anio'],
    $periodo['mes'],
    $seleccion,
    $base,
    $restarProveedores,
    $restarGastos,
    $modo
);

$m = static fn ($importe): string => formatoMoneda((float) $importe);
$baseTexto = $base === 'lista' ? 'Precio de lista (sin IVA)' : 'Precio de venta (con IVA)';
$baseCorta = $base === 'lista' ? 'sin IVA' : 'con IVA';
$estado = $r['balance']['estado'];
$medidor = '/public/image/analisis/medidor.webp?v=' . (is_file(__DIR__ . '/../../public/image/analisis/medidor.webp')
    ? filemtime(__DIR__ . '/../../public/image/analisis/medidor.webp') : time());
$medidor2 = $modo === 'detallado' ? \App\AnalisisFinanciero::medidor2DataUri((float) $r['balance']['angulo']) : '';

$pdfUrl = '/admin/analisis-financiero/exportar?' . http_build_query([
    'anio'        => $periodo['anio'],
    'mes'         => $periodo['mes'],
    'base'        => $base,
    'modo'        => $modo,
    'proveedores' => $restarProveedores ? '1' : '0',
    'gastos'      => $restarGastos ? '1' : '0',
    'negocios'    => $seleccion,
]);

template('header', [
    'titulo'     => 'Análisis Financiero',
    'subtitulo'  => 'Administración y finanzas',
    'rutaActiva' => '/admin/analisis-financiero',
    'acciones'   => '<a class="btn btn--primario" href="' . escaparValores($pdfUrl) . '" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>'
        . '<span>Exportar PDF' . ($modo === 'detallado' ? ' detallado' : '') . '</span></a>',
]);
?>

<section class="tarjeta mb-lg analisis-filtros">
    <form action="/admin/analisis-financiero" method="get" class="formulario" aria-label="Filtros del análisis financiero">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" for="anio-analisis">Año</label>
                <select class="control" id="anio-analisis" name="anio">
                    <?php foreach (\App\Contabilidad::aniosConMovimientos() as $anio) : ?>
                        <option value="<?= (int) $anio ?>"<?= $periodo['anio'] === (int) $anio ? ' selected' : '' ?>><?= (int) $anio ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="mes-analisis">Mes de los gastos</label>
                <select class="control" id="mes-analisis" name="mes">
                    <?php for ($mes = 1; $mes <= 12; $mes++) : ?>
                        <option value="<?= $mes ?>"<?= $periodo['mes'] === $mes ? ' selected' : '' ?>><?= escaparValores(nombreMes($mes)) ?></option>
                    <?php endfor; ?>
                </select>
                <span class="campo__ayuda">Solo aplica a los gastos operativos. La deuda con proveedores siempre es de todos los meses.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="modo-analisis">Modo de análisis</label>
                <select class="control" id="modo-analisis" name="modo">
                    <option value="simple"<?= $modo === 'simple' ? ' selected' : '' ?>>Simple</option>
                    <option value="detallado"<?= $modo === 'detallado' ? ' selected' : '' ?>>Detallado</option>
                </select>
                <span class="campo__ayuda">El detallado agrega ventas del mes y gastos pagados.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="base-analisis">Comparar con</label>
                <select class="control" id="base-analisis" name="base">
                    <option value="venta"<?= $base === 'venta' ? ' selected' : '' ?>>Precio de venta (con IVA)</option>
                    <option value="lista"<?= $base === 'lista' ? ' selected' : '' ?>>Precio de lista (sin IVA)</option>
                </select>
                <span class="campo__ayuda">La misma base se usa para el inventario, los proveedores y los gastos.</span>
            </div>
            <div class="campo">
                <span class="campo__etiqueta">Negocios</span>
                <div class="analisis-checks">
                    <?php foreach ($empresasActivas as $empresa) : $empresaId = (int) $empresa->id; ?>
                        <label class="ed-chk">
                            <input type="checkbox" name="negocios[]" value="<?= $empresaId ?>"<?= in_array($empresaId, $seleccion, true) ? ' checked' : '' ?>>
                            <?= escaparValores($empresa->nombre) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="campo__ayuda">Si no marcas ninguno se analizan todos los negocios activos.</span>
            </div>
        </div>

        <div class="analisis-checks analisis-checks--deudas">
            <span class="campo__etiqueta">Restar de <?= $modo === 'detallado' ? 'las entradas' : 'el inventario' ?></span>
            <input type="hidden" name="proveedores" value="0">
            <label class="ed-chk">
                <input type="checkbox" name="proveedores" value="1"<?= $restarProveedores ? ' checked' : '' ?>>
                Deuda con proveedores
            </label>
            <input type="hidden" name="gastos" value="0">
            <label class="ed-chk">
                <input type="checkbox" name="gastos" value="1"<?= $restarGastos ? ' checked' : '' ?>>
                Gastos operativos del mes
            </label>
        </div>

        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">query_stats</span>
                <span>Actualizar análisis</span>
            </button>
        </div>
    </form>
</section>

<?php if ($modo === 'detallado') : ?>
<section class="tarjeta analisis-medidor mb-lg" aria-label="Estado financiero detallado del negocio">
    <div class="analisis-medidor__grafico analisis-medidor__grafico--redondo">
        <img src="<?= escaparValores($medidor2) ?>" alt="Medidor del estado financiero detallado del negocio">
    </div>

    <div class="analisis-medidor__texto">
        <span class="stat__etiqueta">Estado del negocio · detallado</span>
        <p class="analisis-medidor__puntaje">
            <?= number_format((float) $r['balance']['solidez'], 1) ?><small>/ 100</small>
        </p>
        <p><span class="badge <?= escaparValores($estado['clase']) ?>"><?= escaparValores($estado['etiqueta']) ?></span></p>
        <p class="analisis-medidor__balance">
            Posición financiera: <strong><?= escaparValores($m($r['balance']['balance'])) ?></strong>
        </p>
        <p class="analisis-medidor__resultado">
            Resultado del periodo:
            <strong class="<?= (float) $r['balance']['resultado'] < 0 ? 'analisis-resultado--negativo' : 'analisis-resultado--positivo' ?>">
                <?= escaparValores($m($r['balance']['resultado'])) ?>
            </strong>
        </p>
        <p class="texto-sm texto-secundario mb-0">
            <?= escaparValores($baseTexto) ?>. Entradas = inventario + ventas del mes.
            Salidas = gastos pagados<?= $restarGastos ? ' + gastos operativos pendientes' : '' ?><?= $restarProveedores ? ' + deuda con proveedores' : '' ?>.
        </p>
    </div>
</section>
<?php else : ?>
<section class="tarjeta analisis-medidor mb-lg" aria-label="Estado financiero del negocio">
    <div class="analisis-medidor__grafico">
        <img src="<?= escaparValores($medidor) ?>" alt="Medidor del estado financiero del negocio">
        <svg class="analisis-medidor__aguja" viewBox="0 0 740 493" aria-hidden="true" focusable="false">
            <g transform="rotate(<?= number_format((float) $r['balance']['angulo'], 2, '.', '') ?> 380 386)">
                <polygon points="380,386 366,376 380,176 394,376" fill="#2f3542"></polygon>
                <circle cx="380" cy="386" r="18" fill="#2f3542"></circle>
            </g>
        </svg>
    </div>

    <div class="analisis-medidor__texto">
        <span class="stat__etiqueta">Estado del negocio</span>
        <p class="analisis-medidor__puntaje">
            <?= number_format((float) $r['balance']['solidez'], 1) ?><small>/ 100</small>
        </p>
        <p><span class="badge <?= escaparValores($estado['clase']) ?>"><?= escaparValores($estado['etiqueta']) ?></span></p>
        <p class="analisis-medidor__balance">
            Balance: <strong><?= escaparValores($m($r['balance']['balance'])) ?></strong>
        </p>
        <p class="texto-sm texto-secundario mb-0">
            <?= escaparValores($baseTexto) ?>. El medidor usa la misma base para los dos lados de la comparación.
            <?php if ($restarProveedores) : ?>Se resta la deuda con proveedores.<?php endif; ?>
            <?php if ($restarGastos) : ?>Se restan los gastos operativos pendientes de <?= escaparValores(nombreMes($periodo['mes'])) ?>.<?php endif; ?>
            <?php if (!$restarProveedores && !$restarGastos) : ?>No se está restando ninguna deuda: el balance es solo el inventario.<?php endif; ?>
        </p>
    </div>
</section>
<?php endif; ?>

<?php if ($modo === 'detallado') : ?>
<section class="rejilla-stats mb-lg" aria-label="Resumen del análisis detallado">
    <div class="stat">
        <span class="stat__etiqueta">Inventario (a favor)</span>
        <span class="stat__valor"><?= escaparValores($m($r['inventario']['base'])) ?></span>
        <span class="stat__pie"><?= number_format((int) $r['inventario']['piezas']) ?> piezas · <?= escaparValores($baseCorta) ?></span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Ventas del mes</span>
        <span class="stat__valor"><?= escaparValores($m($r['ventas']['base'])) ?></span>
        <span class="stat__pie">
            <?= number_format((int) $r['ventas']['operaciones']) ?> venta<?= (int) $r['ventas']['operaciones'] === 1 ? '' : 's' ?>
            · incluye <?= escaparValores($m($r['ventas']['sin_factura_base'])) ?> sin facturar
        </span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Cobrado del mes</span>
        <span class="stat__valor"><?= escaparValores($m($r['cobros']['cobrado'])) ?></span>
        <span class="stat__pie">Por cobrar <?= escaparValores($m($r['cobros']['por_cobrar'])) ?> · <?= number_format((float) $r['cobros']['pct_cobrado'], 1) ?> % cobrado</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Gastos pagados</span>
        <span class="stat__valor"><?= escaparValores($m($r['gastos_pagados']['base'])) ?></span>
        <span class="stat__pie"><?= number_format(count($r['gastos_pagados']['filas'])) ?> gasto<?= count($r['gastos_pagados']['filas']) === 1 ? '' : 's' ?> pagado<?= count($r['gastos_pagados']['filas']) === 1 ? '' : 's' ?> · <?= escaparValores($baseCorta) ?></span>
    </div>
    <div class="stat<?= $restarGastos ? '' : ' analisis-stat--apagado' ?>">
        <span class="stat__etiqueta">Gastos operativos pendientes</span>
        <span class="stat__valor"><?= $restarGastos ? escaparValores($m($r['gastos']['base'])) : 'No incluidos' ?></span>
        <span class="stat__pie"><?= $restarGastos ? number_format(count($r['gastos']['filas'])) . ' gasto' . (count($r['gastos']['filas']) === 1 ? '' : 's') . ' pendiente' . (count($r['gastos']['filas']) === 1 ? '' : 's') : 'Activa el check para restarlos' ?></span>
    </div>
    <div class="stat<?= $restarProveedores ? '' : ' analisis-stat--apagado' ?>">
        <span class="stat__etiqueta">Deuda con proveedores</span>
        <span class="stat__valor"><?= $restarProveedores ? escaparValores($m($r['proveedores']['base'])) : 'No incluida' ?></span>
        <span class="stat__pie"><?= number_format(count($r['proveedores']['filas'])) ?> pedido<?= count($r['proveedores']['filas']) === 1 ? '' : 's' ?> pendiente<?= count($r['proveedores']['filas']) === 1 ? '' : 's' ?> · todos los meses</span>
    </div>
    <div class="stat <?= (float) $r['balance']['resultado'] < 0 ? 'analisis-stat--perdida' : 'analisis-stat--ganancia' ?>">
        <span class="stat__etiqueta">Resultado del periodo</span>
        <span class="stat__valor"><?= escaparValores($m($r['balance']['resultado'])) ?></span>
        <span class="stat__pie">Ventas − gastos pagados<?= $restarGastos ? ' − gastos pendientes' : '' ?></span>
    </div>
    <div class="stat analisis-stat--balance">
        <span class="stat__etiqueta">Posición financiera</span>
        <span class="stat__valor"><?= escaparValores($m($r['balance']['balance'])) ?></span>
        <span class="stat__pie">Inventario + ventas − gastos y deudas seleccionadas</span>
    </div>
</section>
<?php else : ?>
<section class="rejilla-stats mb-lg" aria-label="Resumen del balance">
    <div class="stat">
        <span class="stat__etiqueta">Inventario (a favor)</span>
        <span class="stat__valor"><?= escaparValores($m($r['inventario']['base'])) ?></span>
        <span class="stat__pie"><?= number_format((int) $r['inventario']['piezas']) ?> piezas · <?= escaparValores($baseCorta) ?></span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Deuda con proveedores</span>
        <span class="stat__valor"><?= escaparValores($m($r['proveedores']['base'])) ?></span>
        <span class="stat__pie"><?= number_format(count($r['proveedores']['filas'])) ?> pedido<?= count($r['proveedores']['filas']) === 1 ? '' : 's' ?> pendiente<?= count($r['proveedores']['filas']) === 1 ? '' : 's' ?> · <?= escaparValores($baseCorta) ?></span>
    </div>
    <div class="stat<?= $restarGastos ? '' : ' analisis-stat--apagado' ?>">
        <span class="stat__etiqueta">Gastos operativos del mes</span>
        <span class="stat__valor"><?= $restarGastos ? escaparValores($m($r['gastos']['base'])) : 'No incluidos' ?></span>
        <span class="stat__pie"><?= $restarGastos ? number_format(count($r['gastos']['filas'])) . ' gasto' . (count($r['gastos']['filas']) === 1 ? '' : 's') . ' pendiente' . (count($r['gastos']['filas']) === 1 ? '' : 's') . ' · ' . escaparValores($baseCorta) : 'Activa el check para restarlos' ?></span>
    </div>
    <div class="stat analisis-stat--balance">
        <span class="stat__etiqueta">Balance</span>
        <span class="stat__valor"><?= escaparValores($m($r['balance']['balance'])) ?></span>
        <span class="stat__pie">Inventario menos las deudas seleccionadas · <?= escaparValores($baseCorta) ?></span>
    </div>
</section>
<?php endif; ?>

<?php if ($modo === 'detallado' && $r['alertas']) : ?>
<section class="tarjeta analisis-alertas mb-lg" aria-labelledby="analisis-alertas">
    <div class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="analisis-alertas">Diagnóstico</h2>
            <p class="texto-sm texto-secundario mb-0">Puntos que conviene revisar con los datos del periodo.</p>
        </div>
    </div>
    <ul class="analisis-alertas__lista">
        <?php foreach ($r['alertas'] as $alerta) : ?>
            <li class="analisis-alertas__item analisis-alertas__item--<?= escaparValores($alerta['tipo']) ?>">
                <span class="material-symbols-outlined" aria-hidden="true"><?= $alerta['tipo'] === 'error' ? 'error' : ($alerta['tipo'] === 'info' ? 'info' : 'warning') ?></span>
                <span><?= escaparValores($alerta['texto']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($modo === 'detallado' && $r['comparativo']) : $comp = $r['comparativo']; ?>
<section class="tarjeta analisis-comparativo mb-lg" aria-labelledby="analisis-comparativo">
    <div class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="analisis-comparativo">Comparado con <?= escaparValores($comp['periodo']['etiqueta']) ?></h2>
            <p class="texto-sm texto-secundario mb-0">La variación se calcula contra el mismo periodo del mes anterior.</p>
        </div>
    </div>
    <div class="analisis-comparativo__grid">
        <?php foreach ($comp['items'] as $item) : $v = $item['variacion']; $bueno = $v !== null && (($v >= 0) === in_array($item['clave'], ['ventas', 'resultado', 'posicion'], true)); ?>
            <div class="analisis-comparativo__item">
                <span class="stat__etiqueta"><?= escaparValores($item['etiqueta']) ?></span>
                <strong><?= escaparValores($m($item['actual'])) ?></strong>
                <span class="analisis-comparativo__var <?= $v === null ? '' : ($bueno ? 'analisis-comparativo__var--bien' : 'analisis-comparativo__var--mal') ?>">
                    <?php if ($v === null) : ?>
                        Sin base
                    <?php else : ?>
                        <?= $v > 0 ? '▲ +' : ($v < 0 ? '▼ ' : '') ?><?= number_format($v, 1) ?> %
                    <?php endif; ?>
                </span>
                <small>Antes: <?= escaparValores($m($item['anterior'])) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($r['inventario']['negativos']) : ?>
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">inventory</span>
        <span>
            <strong>Existencias negativas.</strong>
            No se suman al inventario: <?= escaparValores(implode('; ', array_map(
                static fn ($n) => ($n['sku'] !== '' ? $n['sku'] . ' ' : '') . $n['nombre'] . ' (' . $n['stock'] . ')',
                $r['inventario']['negativos']
            ))) ?>.
        </span>
    </p>
<?php endif; ?>

<?php if ($modo === 'detallado') : ?>
<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-ventas">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-ventas">Ventas del mes</h2>
            <p class="texto-sm texto-secundario mb-0">Todas las ventas vigentes del periodo, incluidas las que aún no tienen factura.</p>
        </div>
        <div class="analisis-tabla-cab__acciones">
            <span class="analisis-tabla-total"><?= escaparValores($m($r['ventas']['base'])) ?></span>
            <span class="badge badge--info badge--sin-punto"><?= number_format((int) $r['ventas']['operaciones']) ?> venta<?= (int) $r['ventas']['operaciones'] === 1 ? '' : 's' ?></span>
            <button type="button" class="btn btn--texto btn--sm analisis-toggle" data-toggle="ventas" aria-expanded="true" aria-controls="seccion-ventas">
                <span class="material-symbols-outlined" aria-hidden="true">expand_less</span>
                <span data-toggle-texto>Minimizar</span>
            </button>
        </div>
    </header>

    <div id="seccion-ventas" data-seccion="ventas">
    <?php if (!$r['ventas']['filas']) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay ventas registradas en el mes seleccionado.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla analisis-tabla">
                <caption class="solo-lectores">Ventas realizadas en el mes</caption>
                <thead>
                    <tr>
                        <th scope="col">Folio</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Negocio</th>
                        <th scope="col">Facturación</th>
                        <th scope="col" class="tabla--numerica">Importe sin IVA</th>
                        <th scope="col" class="tabla--numerica">IVA</th>
                        <th scope="col" class="tabla--numerica">Importe con IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['ventas']['filas'] as $fila) : ?>
                        <tr>
                            <td data-etiqueta="Folio"><strong><?= escaparValores($fila['folio']) ?></strong></td>
                            <td data-etiqueta="Fecha"><?= date('d/m/Y', strtotime($fila['fecha'])) ?></td>
                            <td data-etiqueta="Cliente"><?= escaparValores($fila['cliente']) ?></td>
                            <td data-etiqueta="Negocio"><?= escaparValores($fila['negocio']) ?></td>
                            <td data-etiqueta="Facturación">
                                <span class="badge <?= $fila['sin_factura'] ? 'badge--pendiente' : 'badge--aprobado' ?> badge--sin-punto">
                                    <?= escaparValores($fila['facturacion']) ?>
                                </span>
                            </td>
                            <td data-etiqueta="Sin IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_iva'])) ?></td>
                            <td data-etiqueta="IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['iva'])) ?></td>
                            <td data-etiqueta="Con IVA" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['con_iva'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5"><strong>Total de ventas</strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['ventas']['sin_iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['ventas']['iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['ventas']['con_iva'])) ?></strong></td>
                    </tr>
                    <tr>
                        <td colspan="5">Del total, ventas sin facturar (<?= number_format((int) $r['ventas']['sin_factura_operaciones']) ?>)</td>
                        <td class="tabla--numerica cifra"><?= escaparValores($m($r['ventas']['sin_factura_sin_iva'])) ?></td>
                        <td></td>
                        <td class="tabla--numerica cifra"><?= escaparValores($m($r['ventas']['sin_factura_con_iva'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-gastos-pagados">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-gastos-pagados">Gastos pagados del mes</h2>
            <p class="texto-sm texto-secundario mb-0">Salidas reales del periodo: gastos operativos y de proveedores ya pagados.</p>
        </div>
        <div class="analisis-tabla-cab__acciones">
            <span class="analisis-tabla-total"><?= escaparValores($m($r['gastos_pagados']['base'])) ?></span>
            <span class="badge badge--aprobado badge--sin-punto"><?= number_format(count($r['gastos_pagados']['filas'])) ?> pagado<?= count($r['gastos_pagados']['filas']) === 1 ? '' : 's' ?></span>
            <button type="button" class="btn btn--texto btn--sm analisis-toggle" data-toggle="gastos-pagados" aria-expanded="true" aria-controls="seccion-gastos-pagados">
                <span class="material-symbols-outlined" aria-hidden="true">expand_less</span>
                <span data-toggle-texto>Minimizar</span>
            </button>
        </div>
    </header>

    <div id="seccion-gastos-pagados" data-seccion="gastos-pagados">
    <?php if (!$r['gastos_pagados']['filas']) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay gastos pagados en el mes seleccionado.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla analisis-tabla">
                <caption class="solo-lectores">Gastos pagados en el mes</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Gasto</th>
                        <th scope="col">Beneficiario / categoría</th>
                        <th scope="col" class="tabla--numerica">Importe sin IVA</th>
                        <th scope="col" class="tabla--numerica">IVA</th>
                        <th scope="col" class="tabla--numerica">Importe con IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['gastos_pagados']['filas'] as $fila) : ?>
                        <tr>
                            <td data-etiqueta="Fecha"><?= date('d/m/Y', strtotime($fila['fecha'])) ?></td>
                            <td data-etiqueta="Gasto"><strong><?= escaparValores($fila['concepto']) ?></strong></td>
                            <td data-etiqueta="Beneficiario">
                                <?= escaparValores($fila['beneficiario'] ?: '—') ?>
                                <?php if ($fila['categoria'] !== '') : ?>
                                    <span class="texto-xs texto-secundario"><?= escaparValores($fila['categoria']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-etiqueta="Sin IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_iva'])) ?></td>
                            <td data-etiqueta="IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['iva'])) ?></td>
                            <td data-etiqueta="Con IVA" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['con_iva'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>Total de gastos pagados</strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_pagados']['sin_iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_pagados']['iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_pagados']['con_iva'])) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-inventario">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-inventario">Inventario (ingresos)</h2>
            <p class="texto-sm texto-secundario mb-0">Valuado con el precio del catálogo. Los productos compartidos entre negocios se cuentan una sola vez.</p>
        </div>
        <span class="badge badge--info badge--sin-punto"><?= escaparValores($baseTexto) ?></span>
    </header>

    <?php if (!$r['inventario']['filas']) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay productos con existencia para los negocios seleccionados.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla analisis-tabla">
                <caption class="solo-lectores">Inventario valuado al precio de catálogo</caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla--numerica">Stock actual</th>
                        <th scope="col" class="tabla--numerica">Importe sin IVA</th>
                        <th scope="col" class="tabla--numerica">Importe con IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['inventario']['filas'] as $fila) : $imagen = \App\Imagenes::url($fila['imagen']); $bajo = !empty($fila['bajo_minimo']); ?>
                        <tr>
                            <td data-etiqueta="Código"><strong><?= escaparValores($fila['sku'] !== '' ? $fila['sku'] : '—') ?></strong></td>
                            <td data-etiqueta="Producto">
                                <div class="analisis-producto">
                                    <?php if ($imagen !== '') : ?>
                                        <img src="<?= escaparValores($imagen) ?>" alt="" loading="lazy">
                                    <?php else : ?>
                                        <span class="analisis-producto__vacio material-symbols-outlined" aria-hidden="true">inventory_2</span>
                                    <?php endif; ?>
                                    <span>
                                        <strong><?= escaparValores($fila['nombre']) ?></strong>
                                        <span class="texto-xs texto-secundario"><?= $fila['negocios'] !== '' ? escaparValores($fila['negocios']) : 'Sin negocio' ?></span>
                                    </span>
                                </div>
                            </td>
                            <td data-etiqueta="Stock" class="tabla--numerica analisis-stock<?= $bajo ? ' analisis-stock--bajo' : '' ?>">
                                <strong><?= number_format((int) $fila['stock']) ?></strong>
                                <?php if ($fila['stock_minimo'] !== null) : ?>
                                    <span>mín. <?= number_format((int) $fila['stock_minimo']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-etiqueta="Sin IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_iva'])) ?></td>
                            <td data-etiqueta="Con IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['con_iva'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>Total del inventario</strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['inventario']['sin_iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['inventario']['con_iva'])) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-proveedores">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-proveedores">Proveedores</h2>
            <p class="texto-sm texto-secundario mb-0">Solo pedidos con saldo pendiente, sin importar el mes en que se hicieron.</p>
        </div>
        <span class="badge badge--pendiente badge--sin-punto"><?= number_format(count($r['proveedores']['filas'])) ?> pendiente<?= count($r['proveedores']['filas']) === 1 ? '' : 's' ?></span>
    </header>

    <?php if (!$r['proveedores']['filas']) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay pedidos pendientes de pago.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla analisis-tabla">
                <caption class="solo-lectores">Pedidos de proveedor con saldo pendiente</caption>
                <thead>
                    <tr>
                        <th scope="col">Código de pedido</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Proveedor</th>
                        <th scope="col" class="tabla--numerica">Saldo sin IVA</th>
                        <th scope="col" class="tabla--numerica">IVA pendiente</th>
                        <th scope="col" class="tabla--numerica">Saldo con IVA</th>
                        <th scope="col" class="tabla--numerica">Pagado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['proveedores']['filas'] as $fila) : ?>
                        <tr>
                            <td data-etiqueta="Pedido"><strong><?= escaparValores($fila['folio']) ?></strong></td>
                            <td data-etiqueta="Fecha"><?= date('d/m/Y', strtotime($fila['fecha'])) ?></td>
                            <td data-etiqueta="Proveedor"><?= escaparValores($fila['proveedor']) ?></td>
                            <td data-etiqueta="Sin IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_iva'])) ?></td>
                            <td data-etiqueta="IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['iva'])) ?></td>
                            <td data-etiqueta="Con IVA" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['con_iva'])) ?></strong></td>
                            <td data-etiqueta="Pagado" class="tabla--numerica cifra texto-secundario"><?= escaparValores($m($fila['pagado'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>Total pendiente</strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['proveedores']['sin_iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['proveedores']['iva'])) ?></strong></td>
                        <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['proveedores']['con_iva'])) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

    <div class="analisis-antiguedad" aria-label="Antigüedad de la deuda con proveedores">
        <?php foreach ($r['antiguedad']['buckets'] as $clave => $bucket) : ?>
            <div class="analisis-antiguedad__item<?= $bucket['monto'] > 0 ? ' analisis-antiguedad__item--con-deuda' : '' ?>">
                <span><?= escaparValores($bucket['etiqueta']) ?></span>
                <strong><?= escaparValores($m($bucket['monto'])) ?></strong>
                <small><?= number_format((int) $bucket['pedidos']) ?> pedido<?= (int) $bucket['pedidos'] === 1 ? '' : 's' ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($restarGastos) : ?>
    <section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-gastos">
        <header class="analisis-tabla-cab">
            <div>
                <h2 class="titulo-5" id="tabla-gastos">Gastos operativos de <?= escaparValores(nombreMes($periodo['mes']) . ' ' . $periodo['anio']) ?></h2>
                <p class="texto-sm texto-secundario mb-0">Solo gastos vigentes y pendientes de pago. Los gastos ligados a un proveedor o a un pedido no se muestran aquí para no duplicar la deuda.</p>
            </div>
            <span class="badge badge--pendiente badge--sin-punto"><?= number_format(count($r['gastos']['filas'])) ?> pendiente<?= count($r['gastos']['filas']) === 1 ? '' : 's' ?></span>
        </header>

        <?php if (!$r['gastos']['filas']) : ?>
            <p class="texto-secundario" style="padding:1rem">No hay gastos operativos pendientes en ese mes.</p>
        <?php else : ?>
            <div class="tabla-contenedor">
                <table class="tabla analisis-tabla">
                    <caption class="solo-lectores">Gastos operativos pendientes del mes</caption>
                    <thead>
                        <tr>
                            <th scope="col">Gasto</th>
                            <th scope="col">Beneficiario / categoría</th>
                            <th scope="col" class="tabla--numerica">Importe sin IVA</th>
                            <th scope="col" class="tabla--numerica">Importe con IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($r['gastos']['filas'] as $fila) : ?>
                            <tr>
                                <td data-etiqueta="Gasto">
                                    <strong><?= escaparValores($fila['concepto']) ?></strong>
                                    <span class="texto-xs texto-secundario"><?= date('d/m/Y', strtotime($fila['fecha'])) ?></span>
                                </td>
                                <td data-etiqueta="Beneficiario">
                                    <?= escaparValores($fila['beneficiario'] ?: '—') ?>
                                    <?php if ($fila['categoria'] !== '') : ?>
                                        <span class="texto-xs texto-secundario"><?= escaparValores($fila['categoria']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-etiqueta="Sin IVA" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_iva'])) ?></td>
                                <td data-etiqueta="Con IVA" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['con_iva'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>Total de gastos pendientes</strong></td>
                            <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos']['sin_iva'])) ?></strong></td>
                            <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos']['con_iva'])) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($modo === 'detallado' && $r['gastos_categoria']['filas']) : ?>
<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-gastos-categoria">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-gastos-categoria">Gastos por categoría</h2>
            <p class="texto-sm texto-secundario mb-0">Cómo se reparten los gastos del mes entre pagados y pendientes.</p>
        </div>
    </header>
    <div class="tabla-contenedor">
        <table class="tabla analisis-tabla">
            <caption class="solo-lectores">Gastos del mes por categoría</caption>
            <thead>
                <tr>
                    <th scope="col">Categoría</th>
                    <th scope="col" class="tabla--numerica">Pagado</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                    <th scope="col" class="tabla--numerica">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['gastos_categoria']['filas'] as $fila) : ?>
                    <tr>
                        <td data-etiqueta="Categoría"><strong><?= escaparValores($fila['categoria']) ?></strong></td>
                        <td data-etiqueta="Pagado" class="tabla--numerica cifra"><?= escaparValores($m($fila['pagado'])) ?></td>
                        <td data-etiqueta="Pendiente" class="tabla--numerica cifra"><?= escaparValores($m($fila['pendiente'])) ?></td>
                        <td data-etiqueta="Total" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['total'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total de gastos</strong></td>
                    <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_categoria']['pagado'])) ?></strong></td>
                    <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_categoria']['pendiente'])) ?></strong></td>
                    <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['gastos_categoria']['total'])) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($modo === 'detallado' && count($seleccion) > 1 && $r['ventas_negocio']['filas']) : ?>
<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="tabla-ventas-negocio">
    <header class="analisis-tabla-cab">
        <div>
            <h2 class="titulo-5" id="tabla-ventas-negocio">Ventas por negocio</h2>
            <p class="texto-sm texto-secundario mb-0">Comparación de las ventas del mes entre los negocios seleccionados.</p>
        </div>
    </header>
    <div class="tabla-contenedor">
        <table class="tabla analisis-tabla">
            <caption class="solo-lectores">Ventas del mes por negocio</caption>
            <thead>
                <tr>
                    <th scope="col">Negocio</th>
                    <th scope="col" class="tabla--numerica">Operaciones</th>
                    <th scope="col" class="tabla--numerica">Ventas</th>
                    <th scope="col" class="tabla--numerica">Sin facturar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['ventas_negocio']['filas'] as $fila) : ?>
                    <tr>
                        <td data-etiqueta="Negocio"><strong><?= escaparValores($fila['negocio']) ?></strong></td>
                        <td data-etiqueta="Operaciones" class="tabla--numerica"><?= number_format((int) $fila['operaciones']) ?></td>
                        <td data-etiqueta="Ventas" class="tabla--numerica cifra"><strong><?= escaparValores($m($fila['ventas'])) ?></strong></td>
                        <td data-etiqueta="Sin facturar" class="tabla--numerica cifra"><?= escaparValores($m($fila['sin_factura'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total</strong></td>
                    <td></td>
                    <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['ventas_negocio']['total'])) ?></strong></td>
                    <td class="tabla--numerica cifra"><strong><?= escaparValores($m($r['ventas']['sin_factura_base'])) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php endif; ?>

<script>
(function () {
    var modo = document.getElementById('modo-analisis');
    if (modo) {
        modo.addEventListener('change', function () {
            if (this.value !== 'detallado') return;
            var gastos = document.querySelector('input[name="gastos"][type="checkbox"]');
            if (gastos) gastos.checked = true;
        });
    }

    document.querySelectorAll('.analisis-toggle').forEach(function (boton) {
        var nombre = boton.dataset.toggle;
        var panel = document.getElementById('seccion-' + nombre);
        if (!panel) return;
        var clave = 'analisis-financiero-' + nombre + '-minimizado';
        var icono = boton.querySelector('.material-symbols-outlined');
        var texto = boton.querySelector('[data-toggle-texto]');

        var aplicar = function (minimizado) {
            panel.hidden = minimizado;
            boton.setAttribute('aria-expanded', minimizado ? 'false' : 'true');
            if (icono) icono.textContent = minimizado ? 'expand_more' : 'expand_less';
            if (texto) texto.textContent = minimizado ? 'Maximizar' : 'Minimizar';
        };

        var minimizado = false;
        try { minimizado = localStorage.getItem(clave) === '1'; } catch (e) { minimizado = false; }
        aplicar(minimizado);

        boton.addEventListener('click', function () {
            var nuevo = !panel.hidden;
            aplicar(nuevo);
            try { localStorage.setItem(clave, nuevo ? '1' : '0'); } catch (e) {}
        });
    });
})();
</script>

<?php template('footer'); ?>
