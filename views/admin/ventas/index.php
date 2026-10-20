<?php
/**
 * views/admin/ventas/index.php — Todo lo vendido.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Mezcla en un solo listado las ventas directas (sin cotización) y las
 * cotizaciones en estado Vendido, sin importar el negocio que las emitió:
 * es el registro único de lo que salió del almacén por venta.
 */

soloAdmin();

/** Filtros que llegan por query string (se conservan en la paginación). */
$filtros = [
    'q'          => trim((string) ($_GET['q'] ?? '')),
    'empresa_id' => (int) ($_GET['empresa'] ?? 0),
    'cliente_id' => (int) ($_GET['cliente'] ?? 0),
    'origen'     => (string) ($_GET['origen'] ?? ''),
    'estado'     => (string) ($_GET['estado'] ?? ''),
    'desde'      => (string) ($_GET['desde'] ?? ''),
    'hasta'      => (string) ($_GET['hasta'] ?? ''),
];

if (!in_array($filtros['origen'], ['venta', 'cotizacion'], true)) {
    $filtros['origen'] = '';
}
if ($filtros['estado'] !== 'cancelada') {
    $filtros['estado'] = '';
}

$hayFiltros = $filtros['q'] !== ''
    || $filtros['empresa_id'] > 0
    || $filtros['cliente_id'] > 0
    || $filtros['origen'] !== ''
    || $filtros['estado'] !== ''
    || $filtros['desde'] !== ''
    || $filtros['hasta'] !== '';

$porPagina = 15;
$total     = \App\Ventas::contarHistorial($filtros);
$pagina    = paginacion($porPagina, $total);
$registros = \App\Ventas::historial($filtros, $pagina['limite'], $pagina['offset']);
$resumen   = \App\Ventas::resumenHistorial($filtros);

$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static function ($empresa): bool {
        return (int) $empresa->activa === 1;
    }
));
$clientes = \App\Clientes::mostrarTodos(1000, 0, '');

/** Query string actual sin la página, para encadenar los filtros. */
$baseFiltros = static function () use ($filtros): string {
    $parametros = [];

    if ($filtros['q'] !== '')       { $parametros['q']       = $filtros['q']; }
    if ($filtros['empresa_id'] > 0) { $parametros['empresa'] = $filtros['empresa_id']; }
    if ($filtros['cliente_id'] > 0) { $parametros['cliente'] = $filtros['cliente_id']; }
    if ($filtros['origen'] !== '')  { $parametros['origen']  = $filtros['origen']; }
    if ($filtros['estado'] !== '')  { $parametros['estado']  = $filtros['estado']; }
    if ($filtros['desde'] !== '')   { $parametros['desde']   = $filtros['desde']; }
    if ($filtros['hasta'] !== '')   { $parametros['hasta']   = $filtros['hasta']; }

    return $parametros ? '?' . http_build_query($parametros) : '';
};

template('header', [
    'titulo'     => 'Ventas',
    'subtitulo'  => 'Todo lo vendido',
    'rutaActiva' => '/admin/ventas',
    'acciones'   => '<a class="btn btn--primario" href="/admin/ventas/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>'
        . '<span>Nueva venta</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Ventas directas y cotizaciones marcadas como vendidas, de todos los negocios.
            Ambas descuentan el inventario; una venta directa sirve cuando no hubo cotización previa.
        </p>
    </div>
</section>

<!-- Resumen (respeta los filtros activos) -->
<section class="rejilla-stats mb-lg" aria-label="Resumen de ventas">
    <div class="stat">
        <span class="stat__etiqueta">Total vendido</span>
        <span class="stat__valor"><?= number_format($resumen['registros']) ?></span>
        <span class="stat__pie">Documentos</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Ventas directas</span>
        <span class="stat__valor"><?= number_format($resumen['directas']) ?></span>
        <span class="stat__pie">Sin cotización</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Desde cotización</span>
        <span class="stat__valor"><?= number_format($resumen['cotizaciones']) ?></span>
        <span class="stat__pie">Marcadas como vendidas</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Monto vendido</span>
        <span class="stat__valor"><?= formatoMoneda($resumen['monto']) ?></span>
        <span class="stat__pie">IVA incluido</span>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador buscador--filtros" action="/admin/ventas" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar venta</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por folio, cliente, alias o RFC…"
                       value="<?= escaparValores($filtros['q']) ?>">
            </div>

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"
                        <?= $filtros['empresa_id'] === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="cliente">Cliente</label>
            <select class="control control--compacto" id="cliente" name="cliente">
                <option value="0">Todos los clientes</option>
                <?php foreach ($clientes as $cliente) : ?>
                    <option value="<?= (int) $cliente->id ?>"
                        <?= $filtros['cliente_id'] === (int) $cliente->id ? 'selected' : '' ?>>
                        <?= escaparValores(trim((string) $cliente->alias) !== '' ? $cliente->alias : $cliente->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="origen">Origen</label>
            <select class="control control--compacto" id="origen" name="origen">
                <option value="">Todos los orígenes</option>
                <option value="venta" <?= $filtros['origen'] === 'venta' ? 'selected' : '' ?>>Venta directa</option>
                <option value="cotizacion" <?= $filtros['origen'] === 'cotizacion' ? 'selected' : '' ?>>Desde cotización</option>
            </select>

            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Vendidas</option>
                <option value="cancelada" <?= $filtros['estado'] === 'cancelada' ? 'selected' : '' ?>>Ventas canceladas</option>
            </select>

            <label class="solo-lectores" for="desde">Desde</label>
            <input class="control control--compacto" type="date" id="desde" name="desde"
                   value="<?= escaparValores($filtros['desde']) ?>">

            <label class="solo-lectores" for="hasta">Hasta</label>
            <input class="control control--compacto" type="date" id="hasta" name="hasta"
                   value="<?= escaparValores($filtros['hasta']) ?>">

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">filter_list</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/ventas" aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'registro' : 'registros' ?>
        </span>
    </div>

    <?php if (empty($registros)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">point_of_sale</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay ventas' ?></p>
            <p><?= $hayFiltros
                    ? 'Ninguna venta coincide con los filtros.'
                    : 'Registra una venta directa o marca una cotización como vendida.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/ventas">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/ventas/crear">Nueva venta</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--documentos">
                <caption class="solo-lectores">Ventas directas y cotizaciones vendidas</caption>
                <thead>
                    <tr>
                        <th scope="col">Folio</th>
                        <th scope="col">Origen</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Negocio</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $registro) :
                        $esDirecta = $registro->origen === 'venta';
                        $rutaBase  = $esDirecta ? '/admin/ventas' : '/admin/cotizaciones';
                        $clienteEtiqueta = trim((string) $registro->cliente_nombre);
                        if (trim((string) $registro->cliente_empresa) !== '') {
                            $clienteEtiqueta .= ' — ' . $registro->cliente_empresa;
                        }
                        ?>
                        <tr>
                            <td class="doc-folio" style="white-space:nowrap">
                                <a href="<?= $rutaBase ?>/detalle?id=<?= (int) $registro->id ?>"><strong><?= escaparValores($registro->folio) ?></strong></a>
                            </td>
                            <td class="doc-estado">
                                <?php if ($esDirecta && $registro->estado === 'cancelada') : ?>
                                    <span class="badge badge--error">Venta cancelada</span>
                                <?php elseif ($esDirecta) : ?>
                                    <span class="badge badge--info">Venta directa</span>
                                <?php else : ?>
                                    <span class="badge badge--neutro">Cotización</span>
                                <?php endif; ?>
                            </td>
                            <td class="doc-fecha"><?= date('d/m/Y', strtotime($registro->fecha)) ?></td>
                            <td class="doc-cliente">
                                <div class="tabla__producto-textos">
                                    <span><?= escaparValores($clienteEtiqueta) ?></span>
                                    <span class="texto-secundario"><?= escaparValores((string) $registro->cliente_rfc) ?></span>
                                </div>
                            </td>
                            <td class="doc-negocio"><?= escaparValores((string) $registro->empresa_nombre) ?></td>
                            <td class="tabla--numerica cifra doc-total"><strong><?= formatoMoneda($registro->total) ?></strong></td>
                            <td class="tabla__acciones doc-acciones">
                                <a class="btn btn--texto btn--sm" href="<?= $rutaBase ?>/detalle?id=<?= (int) $registro->id ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                    <span>Ver</span>
                                </a>

                                <?php if ($esDirecta) : ?>
                                    <a class="btn btn--texto btn--sm" href="/admin/ventas/editar?id=<?= (int) $registro->id ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                        <span>Editar</span>
                                    </a>

                                    <form action="/admin/ventas/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="<?= escaparValores($registro->folio) ?>"
                                          data-confirmacion="¿Eliminar esta venta?"
                                          data-confirmacion-mensaje="Se borrará la venta <?= escaparValores($registro->folio) ?> y todos sus renglones.<?php if ($registro->estado === 'registrada') : ?> El inventario recuperará las piezas descontadas.<?php endif; ?> Irá a Ajustes → Papelera y ahí podrás restaurarla durante 90 días."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $registro->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar"
                                                aria-label="Eliminar venta <?= escaparValores($registro->folio) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                        </button>
                                    </form>
                                <?php else : ?>
                                    <a class="btn btn--texto btn--sm" href="/admin/cotizaciones/editar?id=<?= (int) $registro->id ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                        <span>Editar</span>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => '/admin/ventas' . $baseFiltros(),
            'total'    => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
