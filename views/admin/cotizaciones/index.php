<?php
/**
 * views/admin/cotizaciones/index.php — Histórico de cotizaciones y pedidos.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Reproduce design/hist_rico_de_cotizaciones_y_pedidos: cifras de resumen,
 * barra de filtros (búsqueda, negocio, cliente, estado y rango de fechas)
 * y tabla con las acciones de cada cotización.
 */

soloAdmin();

/** Filtros que llegan por query string (se conservan en la paginación). */
$filtros = [
    'q'         => trim((string) ($_GET['q'] ?? '')),
    'empresa_id'=> (int) ($_GET['empresa'] ?? 0),
    'cliente_id'=> (int) ($_GET['cliente'] ?? 0),
    'estado'    => (string) ($_GET['estado'] ?? ''),
    'desde'     => (string) ($_GET['desde'] ?? ''),
    'hasta'     => (string) ($_GET['hasta'] ?? ''),
];

if (!in_array($filtros['estado'], \App\Cotizaciones::ESTADOS, true)) {
    $filtros['estado'] = '';
}

$hayFiltros = $filtros['q'] !== ''
    || $filtros['empresa_id'] > 0
    || $filtros['cliente_id'] > 0
    || $filtros['estado'] !== ''
    || $filtros['desde'] !== ''
    || $filtros['hasta'] !== '';

$porPagina = 15;
$total     = \App\Cotizaciones::contar($filtros);
$pagina    = paginacion($porPagina, $total);
$cotizaciones = \App\Cotizaciones::listar($filtros, $pagina['limite'], $pagina['offset']);

$resumen  = \App\Cotizaciones::estadisticas();
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

    if ($filtros['q'] !== '')        { $parametros['q']      = $filtros['q']; }
    if ($filtros['empresa_id'] > 0)  { $parametros['empresa']= $filtros['empresa_id']; }
    if ($filtros['cliente_id'] > 0)  { $parametros['cliente']= $filtros['cliente_id']; }
    if ($filtros['estado'] !== '')   { $parametros['estado'] = $filtros['estado']; }
    if ($filtros['desde'] !== '')    { $parametros['desde']  = $filtros['desde']; }
    if ($filtros['hasta'] !== '')    { $parametros['hasta']  = $filtros['hasta']; }

    return $parametros ? '?' . http_build_query($parametros) : '';
};

/** Etiqueta de periodo corta para las tarjetas de estado. */
$etiquetaPeriodo = static function (string $desde, string $hasta): string {
    if ($desde !== '' && $hasta !== '') {
        return 'Del ' . date('d/m/y', strtotime($desde)) . ' al ' . date('d/m/y', strtotime($hasta));
    }
    if ($desde !== '') {
        return 'Desde el ' . date('d/m/y', strtotime($desde));
    }
    if ($hasta !== '') {
        return 'Hasta el ' . date('d/m/y', strtotime($hasta));
    }
    return 'Histórico completo';
};

template('header', [
    'titulo'     => 'Histórico de cotizaciones',
    'subtitulo'  => 'Módulo de cotizaciones',
    'rutaActiva' => '/admin/cotizaciones',
    'acciones'   => '<a class="btn btn--primario" href="/admin/cotizaciones/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">note_add</span>'
        . '<span>Nueva cotización</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Histórico de cotizaciones y pedidos</h1> -->
        <p class="texto-secundario">
            Todas las cotizaciones emitidas con su estado comercial. Desde acá se consulta,
            edita, exporta o envía cada documento al cliente.
        </p>
    </div>
    <!-- <div class="encabezado-pagina__acciones">
        <a class="btn btn--primario" href="/admin/cotizaciones/crear">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">note_add</span>
            <span>Nueva cotización</span>
        </a>
    </div> -->
</section>

<!-- Resumen del histórico -->
<section class="rejilla-stats mb-lg" aria-label="Resumen de cotizaciones">
    <div class="stat">
        <span class="stat__etiqueta">Total cotizaciones</span>
        <span class="stat__valor"><?= number_format($resumen['total']) ?></span>
        <span class="stat__pie">Emitidas</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Pendientes</span>
        <span class="stat__valor"><?= number_format($resumen['pendientes']) ?></span>
        <span class="stat__pie">Sin resolver</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Vendidas</span>
        <span class="stat__valor"><?= number_format($resumen['vendidas']) ?></span>
        <span class="stat__pie">Convertidas en pedido</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Monto vendido</span>
        <span class="stat__valor"><?= formatoMoneda($resumen['monto_vendido']) ?></span>
        <span class="stat__pie">IVA incluido</span>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Filtros del histórico -->
    <div class="tabla-toolbar">
        <form class="buscador buscador--filtros" action="/admin/cotizaciones" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar cotización</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por folio, cliente, alias o RFC…"
                       value="<?= escaparValores($filtros['q']) ?>">
            </div>

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"
                        <?= $filtros['empresa_id'] === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="cliente">Cliente</label>
            <select class="control control--compacto" id="cliente" name="cliente">
                <option value="0">Todos</option>
                <?php foreach ($clientes as $cliente) : ?>
                    <option value="<?= (int) $cliente->id ?>"
                        <?= $filtros['cliente_id'] === (int) $cliente->id ? 'selected' : '' ?>>
                        <?= escaparValores(trim((string) $cliente->alias) !== '' ? $cliente->alias : $cliente->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Todos</option>
                <?php foreach (\App\Cotizaciones::ESTILOS_ESTADO as $clave => $datos) : ?>
                    <option value="<?= $clave ?>" <?= $filtros['estado'] === $clave ? 'selected' : '' ?>>
                        <?= $datos['etiqueta'] ?>
                    </option>
                <?php endforeach; ?>
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
                <a class="btn btn--texto btn--sm" href="/admin/cotizaciones"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'cotización' : 'cotizaciones' ?>
        </span>
    </div>

    <?php if (empty($cotizaciones)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay cotizaciones' ?></p>
            <p><?= $hayFiltros
                    ? 'Ninguna cotización coincide con los filtros: ' . escaparValores($etiquetaPeriodo($filtros['desde'], $filtros['hasta'])) . '.'
                    : 'Creá la primera cotización para empezar a trabajar con tus clientes.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/cotizaciones">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/cotizaciones/crear">Nueva cotización</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--documentos">
                <caption class="solo-lectores">Histórico de cotizaciones y pedidos</caption>
                <thead>
                    <tr>
                        <th scope="col">Folio</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Negocio</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cotizaciones as $cotizacion) :
                        $estado = \App\Cotizaciones::datosEstado($cotizacion->estado);
                        $clienteEtiqueta = trim((string) $cotizacion->cliente_nombre);
                        if (trim((string) $cotizacion->cliente_empresa) !== '') {
                            $clienteEtiqueta .= ' — ' . $cotizacion->cliente_empresa;
                        }
                        ?>
                        <tr>
                            <td class="doc-folio">
                                <a href="/admin/cotizaciones/detalle?id=<?= (int) $cotizacion->id ?>"><strong><?= escaparValores($cotizacion->folio) ?></strong></a>
                            </td>
                            <td class="doc-fecha"><?= date('d/m/Y', strtotime($cotizacion->fecha)) ?></td>
                            <td class="doc-cliente">
                                <div class="tabla__producto-textos">
                                    <span><?= escaparValores($clienteEtiqueta) ?></span>
                                    <span class="texto-secundario"><?= escaparValores((string) $cotizacion->cliente_rfc) ?></span>
                                </div>
                            </td>
                            <td class="doc-negocio"><?= escaparValores((string) $cotizacion->empresa_nombre) ?></td>
                            <td class="doc-estado">
                                <span class="badge <?= $estado['clase'] ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $estado['icono'] ?></span>
                                    <?= $estado['etiqueta'] ?>
                                </span>
                            </td>
                            <td class="tabla--numerica cifra doc-total"><strong><?= formatoMoneda($cotizacion->total) ?></strong></td>
                            <td class="tabla__acciones doc-acciones">
                                <a class="btn btn--texto btn--sm"
                                   href="/admin/cotizaciones/detalle?id=<?= (int) $cotizacion->id ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                    <span>Ver</span>
                                </a>

                                <a class="btn btn--texto btn--sm"
                                   href="/admin/cotizaciones/editar?id=<?= (int) $cotizacion->id ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    <span>Editar</span>
                                </a>

                                <!-- Duplicar abre una copia con folio nuevo en el
                                     formulario de edición: sólo hay que ajustar
                                     productos o cantidades y guardar. -->
                                <form action="/admin/cotizaciones/duplicar" method="post" data-validar>
                                    <input type="hidden" name="id" value="<?= (int) $cotizacion->id ?>">
                                    <button type="submit" class="btn btn--texto btn--sm"
                                            title="Duplicar cotización">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">content_copy</span>
                                        <span>Duplicar</span>
                                    </button>
                                </form>

                                <form action="/admin/cotizaciones/eliminar" method="post" data-validar
                                      data-confirmacion-sobre="<?= escaparValores($cotizacion->folio) ?>"
                                      data-confirmacion="¿Eliminar esta cotización?"
                                      data-confirmacion-mensaje="Se borrará la cotización <?= escaparValores($cotizacion->folio) ?> y todos sus renglones.<?php if ($cotizacion->estado === 'vendido') : ?> Al estar Vendida, el inventario devolverá las piezas descontadas.<?php endif; ?> Irá a Ajustes → Papelera y ahí podrás restaurarla durante 90 días."
                                      data-confirmacion-boton="Sí, eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $cotizacion->id ?>">
                                    <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                            title="Eliminar"
                                            aria-label="Eliminar cotización <?= escaparValores($cotizacion->folio) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => '/admin/cotizaciones' . $baseFiltros(),
            'total'    => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
