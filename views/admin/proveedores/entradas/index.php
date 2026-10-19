<?php
/**
 * views/admin/proveedores/entradas/index.php — Reportes de entrega de
 * proveedores.
 *
 * Búsqueda por proveedor, pedido o categoría, filtro por proveedor y rango
 * de fechas, más paginación.
 */

soloAdmin();

/** Filtros del listado. */
$termino    = trim($_GET['q'] ?? '');
$proveedorId = (int) ($_GET['proveedor'] ?? 0);
$desde      = trim($_GET['desde'] ?? '');
$hasta      = trim($_GET['hasta'] ?? '');

$filtros = [
    'q'        => $termino,
    'proveedor' => $proveedorId,
    'desde'    => $desde,
    'hasta'    => $hasta,
];

$porPagina = 15;
$total     = \App\ProveedorEntradas::contarTodos($filtros);
$pagina    = paginacion($porPagina, $total);
$entradas  = \App\ProveedorEntradas::mostrarTodos($filtros, $pagina['limite'], $pagina['offset']);

$proveedores = \App\Proveedores::paraSelector();

/** URL del listado conservando los filtros activos. */
$rutaBase   = '/admin/proveedores/entradas';
$parametros = [];
if ($termino !== '')     { $parametros['q'] = $termino; }
if ($proveedorId > 0)    { $parametros['proveedor'] = $proveedorId; }
if ($desde !== '')       { $parametros['desde'] = $desde; }
if ($hasta !== '')       { $parametros['hasta'] = $hasta; }
if (!empty($parametros)) { $rutaBase .= '?' . http_build_query($parametros); }

$hayFiltros = !empty($parametros);

// Suma de lo que sigue por pagar con los filtros aplicados: con la vista sin
// filtros muestra la deuda total, y al filtrar por proveedor, la de ese.
$pendientePorPagar = \App\ProveedorEntradas::sumarMonto($filtros, true);

template('header', [
    'titulo'    => 'Entradas de proveedores',
    'subtitulo' => 'Reportes fotográficos',
    'rutaActiva' => '/admin/proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">contacts</span>'
        . '<span>Proveedores</span></a>'
        . '<a class="btn btn--primario" href="/admin/proveedores/entradas/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_a_photo</span>'
        . '<span>Nueva entrada</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador + filtros -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/proveedores/entradas" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar entrada</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por proveedor, pedido o categoría…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <label class="solo-lectores" for="proveedor">Proveedor</label>
            <select class="control control--compacto" id="proveedor" name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $opcion) : ?>
                    <option value="<?= (int) $opcion['id'] ?>"
                        <?= $proveedorId === (int) $opcion['id'] ? 'selected' : '' ?>>
                        <?= escaparValores($opcion['etiqueta']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="desde">Desde</label>
            <input class="control control--compacto" type="date" id="desde" name="desde"
                   value="<?= escaparValores($desde) ?>">

            <label class="solo-lectores" for="hasta">Hasta</label>
            <input class="control control--compacto" type="date" id="hasta" name="hasta"
                   value="<?= escaparValores($hasta) ?>">

            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/proveedores/entradas"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <div class="tabla-toolbar__resumen">
            <span class="badge badge--pendiente badge--sin-punto cifra"
                  title="Suma de los montos de los pedidos que aún no se pagan">
                Pendiente por pagar <?= formatoMoneda($pendientePorPagar) ?>
            </span>

            <span class="badge badge--neutro badge--sin-punto cifra">
                <?= number_format($total) ?> <?= $total === 1 ? 'entrada' : 'entradas' ?>
            </span>
        </div>
    </div>

    <?php if (empty($entradas)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">assignment</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay entradas' ?></p>
            <p><?= $hayFiltros
                    ? 'Ninguna entrada coincide con los filtros aplicados.'
                    : 'Registra cada entrega de material con su fotografía y su número de pedido.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/proveedores/entradas">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/proveedores/entradas/crear">Nueva entrada</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Listado -->
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Entradas de material registradas</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Proveedor</th>
                        <th scope="col">Número de pedido</th>
                        <th scope="col" class="tabla__col-secundaria">Categoría</th>
                        <th scope="col" class="tabla--numerica">Monto</th>
                        <th scope="col" class="tabla__col-secundaria tabla--centro">Foto</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entradas as $entrada) : ?>
                        <tr>
                            <td class="cifra"><?= date('d/m/Y', strtotime($entrada->fecha)) ?></td>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($entrada->proveedor_nombre) ?></strong>
                                    <span class="texto-xs texto-secundario texto-una-linea">
                                        <?= escaparValores($entrada->proveedor_tipo) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="cifra"><?= escaparValores($entrada->numero_pedido ?: '—') ?></td>
                            <td class="tabla__col-secundaria texto-sm"><?= escaparValores(\App\ProveedorEntradas::nombreCategoria((int) $entrada->categoria)) ?></td>
                            <td class="cifra"><?= formatoMoneda($entrada->monto) ?></td>
                            <td class="tabla__col-secundaria tabla--centro">
                                <?php if ($entrada->foto !== '') : ?>
                                    <img class="tabla__miniatura"
                                         src="<?= escaparValores(\App\Imagenes::url($entrada->foto)) ?>"
                                         alt="">
                                <?php else : ?>
                                    <span class="texto-sm texto-secundario">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/proveedores/entradas/detalle?id=<?= (int) $entrada->id ?>"
                                       title="Ver detalle" aria-label="Ver detalle de la entrada">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                    </a>
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/proveedores/entradas/editar?id=<?= (int) $entrada->id ?>"
                                       title="Editar" aria-label="Editar la entrada">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <!-- Borrado con confirmación modal (nunca window.confirm). -->
                                    <form action="/admin/proveedores/entradas/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Baja de entrada"
                                          data-confirmacion="¿Eliminar esta entrada?"
                                          data-confirmacion-mensaje="Se borrará la entrada del proveedor «<?= escaparValores($entrada->proveedor_nombre) ?>» y su fotografía. Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $entrada->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar la entrada">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => $rutaBase,
            'total'    => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
