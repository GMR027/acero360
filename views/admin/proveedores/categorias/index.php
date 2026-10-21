<?php
/**
 * views/admin/proveedores/categorias/index.php — Catálogo de categorías.
 *
 * CRUD de lectura: aquí se listan, filtran y dan de alta/baja las categorías
 * con las que se clasifica cada entrada de material.
 *
 * El catálogo es COMÚN a todos los proveedores (una sola tabla). Si fuera
 * por proveedor, "Acero" existiría repetido con tres significados y el
 * filtro dejaría de servir para comparar entradas entre sí.
 *
 * Ruta protegida por el middleware "soloAdmin".
 */

soloAdmin();

/** Filtros del listado. */
$termino   = trim($_GET['q'] ?? '');
$porPagina = 15;
$total     = \App\CategoriasProveedor::contarTodos($termino);
$pagina    = paginacion($porPagina, $total);
$lista     = \App\CategoriasProveedor::mostrarTodos($pagina['limite'], $pagina['offset'], $termino);

/** URL del listado conservando la búsqueda activa. */
$rutaBase = '/admin/proveedores/categorias';
if ($termino !== '') {
    $rutaBase .= '?' . http_build_query(['q' => $termino]);
}

$hayFiltros = $termino !== '';

template('header', [
    'titulo'    => 'Categorías de compra',
    'subtitulo' => 'Categorías de compra',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver</span></a>'
        . '<a class="btn btn--primario" href="/admin/proveedores/categorias/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nueva categoría</span></a>',
]);
template('tabsCompras', ['tab' => 'categorias']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Las categorías se ofrecen en el campo «Categoría» de las compras de
            <strong>todos</strong> los proveedores. Si una categoría ya está en uso no se puede
            borrar (se avisa antes), pero sí renombrarla: sólo cambia el texto y las compras
            conservan su id.
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador + contador -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/proveedores/categorias" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar categoría</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar categoría…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/proveedores/categorias"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'categoría' : 'categorías' ?>
        </span>
    </div>

    <?php if (empty($lista)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">category</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay categorías' ?></p>
            <p><?= $hayFiltros
                    ? 'Ninguna categoría coincide con la búsqueda.'
                    : 'Crea categorías para clasificar las entradas de material de tus proveedores.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/proveedores/categorias">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/proveedores/categorias/crear">Nueva categoría</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Listado -->
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Categorías de compra disponibles</caption>
                <thead>
                    <tr>
                        <th scope="col">Categoría</th>
                        <th scope="col" class="tabla--centro">Entradas</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $categoria) : ?>
                        <?php $enUso = (int) $categoria->usos; ?>
                        <tr>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($categoria->nombre) ?></strong>
                                    <span class="texto-xs texto-secundario texto-una-linea">
                                        Alta <?= escaparValores(substr((string) $categoria->creado_en, 0, 10)) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="tabla--centro cifra">
                                <!-- El contador es también la razón por la que la categoría
                                     puede o no borrarse: enlaza a las entradas filtradas. -->
                                <a class="badge badge--sin-punto <?= $enUso > 0 ? 'badge--info' : 'badge--neutro' ?>"
                                   href="/admin/proveedores/entradas?q=<?= rawurlencode($categoria->nombre) ?>"
                                   title="Ver entradas de esta categoría">
                                    <?= number_format($enUso) ?>
                                </a>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/proveedores/categorias/editar?id=<?= (int) $categoria->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($categoria->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <!-- Borrado con confirmación modal (nunca window.confirm).
                                         Si la categoría está en uso, el servidor se niega y
                                         devuelve el aviso en una alerta; el botón queda visible
                                         para que la razón se entienda al intentarlo. -->
                                    <form action="/admin/proveedores/categorias/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Baja de categoría"
                                          data-confirmacion="¿Eliminar esta categoría?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($categoria->nombre) ?>»<?= $enUso > 0 ? '… pero la usan ' . number_format($enUso) . ' entradas, así que no se podrá eliminar hasta liberarlas' : '. No está en uso, así que ninguna entrada se ve afectada' ?>."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $categoria->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($categoria->nombre) ?>">
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
