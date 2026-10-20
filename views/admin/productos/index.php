<?php
/**
 * views/admin/productos/index.php — Catálogo de productos.
 *
 * Búsqueda por código, nombre o descripción, filtro por negocio y estado,
 * paginación y acciones (editar / eliminar con confirmación modal).
 */

soloAdmin();

$termino   = trim($_GET['q'] ?? '');
$empresaId = (int) ($_GET['empresa'] ?? 0);
$estado    = $_GET['estado'] ?? 'todos';

if (!in_array($estado, ['todos', 'activos', 'inactivos'], true)) {
    $estado = 'todos';
}

$porPagina = 10;
$total     = \App\Productos::contarTodos($termino, $empresaId, $estado);
$pagina    = paginacion($porPagina, $total);
$productos = \App\Productos::mostrarTodos($pagina['limite'], $pagina['offset'], $termino, $empresaId, $estado);

$empresas = \App\Empresas::mostrarTodos(100, 0);

/** URL del listado conservando los filtros activos. */
$rutaBase = '/admin/productos';
$parametros = [];
if ($termino !== '')   { $parametros['q'] = $termino; }
if ($empresaId > 0)    { $parametros['empresa'] = $empresaId; }
if ($estado !== 'todos') { $parametros['estado'] = $estado; }
if (!empty($parametros)) { $rutaBase .= '?' . http_build_query($parametros); }

/** Etiqueta del negocio de cada renglón. */
$nombreEmpresa = [];
foreach ($empresas as $empresa) {
    $nombreEmpresa[(int) $empresa->id] = $empresa->nombre;
}

$hayFiltros = $termino !== '' || $empresaId > 0 || $estado !== 'todos';

template('header', [
    'titulo'    => 'Catálogo de productos',
    'subtitulo' => 'Módulo de productos',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/productos/pdf" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>'
        . '<span>Exportar catálogo de productos</span></a>'
        . '<a class="btn btn--primario" href="/admin/productos/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
        . '<span>Nuevo producto</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador + filtros -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/productos" method="get" role="search" data-validar>
            <!-- La lupa vive dentro de __campo: se centra sobre el input aunque
                 los dos selects de filtro y el botón pasen a la línea siguiente. -->
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar producto</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por SKU, nombre o categoría…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"
                        <?= $empresaId === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="todos"<?= $estado === 'todos' ? ' selected' : '' ?>>Todos</option>
                <option value="activos"<?= $estado === 'activos' ? ' selected' : '' ?>>Activos</option>
                <option value="inactivos"<?= $estado === 'inactivos' ? ' selected' : '' ?>>Inactivos</option>
            </select>

            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/productos"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'producto' : 'productos' ?>
        </span>
    </div>

    <?php if (empty($productos)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay productos' ?></p>
            <p><?= $hayFiltros
                    ? 'Ningún producto coincide con los filtros aplicados.'
                    : 'Carga el primer producto del catálogo para poder cotizar.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/productos">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/productos/crear">Nuevo producto</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Catálogo de productos</caption>
                <thead>
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col">Negocio</th>
                        <th scope="col" class="tabla--numerica">Precio de lista</th>
                        <th scope="col" class="tabla--centro">Estado</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productos as $producto) : ?>
                        <tr>
                            <td>
                                <div class="tabla__producto">
                                    <?php if ($producto->imagen) : ?>
                                        <img class="tabla__producto-img"
                                             src="<?= escaparValores(\App\Imagenes::urlMiniatura($producto->imagen)) ?>"
                                             alt="<?= escaparValores($producto->nombre) ?>">
                                    <?php else : ?>
                                        <span class="tabla__producto-img tabla__producto-img--vacia" aria-hidden="true">inventory_2</span>
                                    <?php endif; ?>
                                    <div class="tabla__producto-textos">
                                        <strong><?= escaparValores($producto->nombre) ?></strong>
                                        <span class="texto-xs texto-secundario">
                                            <?= escaparValores($producto->sku ?: 'Sin SKU') ?>
                                            · <?= escaparValores($producto->unidad) ?>
                                            <?= $producto->categoria ? ' · ' . escaparValores($producto->categoria) : '' ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="texto-sm">
                                <?= escaparValores(implode(', ', array_map(
                                    static fn ($id) => $nombreEmpresa[$id] ?? '—',
                                    \App\Productos::negociosDe((int) $producto->id)
                                )) ?: '—') ?>
                            </td>
                            <td class="tabla--numerica cifra">
                                $<?= number_format((float) $producto->precio, 2) ?>
                            </td>
                            <td class="tabla--centro">
                                <?= (int) $producto->activo === 1
                                    ? '<span class="badge badge--aprobado">Activo</span>'
                                    : '<span class="badge badge--neutro">Inactivo</span>' ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/productos/editar?id=<?= (int) $producto->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($producto->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <form action="/admin/productos/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Catálogo de productos"
                                          data-confirmacion="¿Eliminar este producto?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($producto->nombre) ?>» y su imagen. Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $producto->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($producto->nombre) ?>">
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
