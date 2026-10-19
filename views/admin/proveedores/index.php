<?php
/**
 * views/admin/proveedores/index.php — Directorio de proveedores.
 *
 * Búsqueda por nombre, RFC o ubicación, filtro por tipo y paginación.
 * Desde aquí se abren las entradas (reportes fotográficos) de cada uno.
 */

soloAdmin();

/** Filtros del listado. */
$termino = trim($_GET['q'] ?? '');
$tipo    = trim($_GET['tipo'] ?? '');

if ($tipo !== '' && !in_array($tipo, \App\Proveedores::TIPOS, true)) {
    $tipo = '';
}

$porPagina = 10;
$total     = \App\Proveedores::contarTodos($termino, $tipo);
$pagina    = paginacion($porPagina, $total);
$lista     = \App\Proveedores::mostrarTodos($pagina['limite'], $pagina['offset'], $termino, $tipo);

/** URL del listado conservando la búsqueda activa. */
$rutaBase    = '/admin/proveedores';
$parametros  = [];
if ($termino !== '') { $parametros['q'] = $termino; }
if ($tipo !== '')    { $parametros['tipo'] = $tipo; }
if (!empty($parametros)) { $rutaBase .= '?' . http_build_query($parametros); }

$hayFiltros = !empty($parametros);

template('header', [
    'titulo'    => 'Proveedores',
    'subtitulo' => 'Directorio de proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores/categorias">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">category</span>'
        . '<span>Crear Categorías</span></a>'
        . '<a class="btn btn--secundario" href="/admin/proveedores/pdf" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>'
        . '<span>Exportar datos</span></a>'
        . '<a class="btn btn--secundario" href="/admin/proveedores/entradas">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">assignment</span>'
        . '<span>Entradas</span></a>'
        . '<a class="btn btn--primario" href="/admin/proveedores/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_business</span>'
        . '<span>Nuevo proveedor</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador + filtros -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/proveedores" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar proveedor</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por nombre, RFC o ubicación…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <label class="solo-lectores" for="tipo">Tipo de proveedor</label>
            <select class="control control--compacto" id="tipo" name="tipo">
                <option value="">Todos los tipos</option>
                <?php foreach (\App\Proveedores::TIPOS as $opcion) : ?>
                    <option value="<?= escaparValores($opcion) ?>"
                        <?= $tipo === $opcion ? 'selected' : '' ?>>
                        <?= escaparValores($opcion) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/proveedores"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'proveedor' : 'proveedores' ?>
        </span>
    </div>

    <?php if (empty($lista)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">local_shipping</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay proveedores' ?></p>
            <p><?= $hayFiltros
                    ? 'Ningún proveedor coincide con los filtros aplicados.'
                    : 'Registra a tu proveedor para documentar cada entrada de material.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/proveedores">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/proveedores/crear">Nuevo proveedor</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Listado -->
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Directorio de proveedores</caption>
                <thead>
                    <tr>
                        <th scope="col">Proveedor</th>
                        <th scope="col">RFC</th>
                        <th scope="col" class="tabla__col-secundaria">Teléfono</th>
                        <th scope="col" class="tabla__col-secundaria">Ubicación</th>
                        <th scope="col" class="tabla--centro">Entradas</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $proveedor) : ?>
                        <tr>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($proveedor->nombre) ?></strong>
                                    <span class="texto-xs texto-secundario texto-una-linea">
                                        <?= escaparValores($proveedor->tipo_proveedor) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="cifra"><?= escaparValores($proveedor->rfc ?: '—') ?></td>
                            <td class="tabla__col-secundaria cifra"><?= escaparValores($proveedor->telefono ?: '—') ?></td>
                            <td class="tabla__col-secundaria texto-sm texto-secundario">
                                <?= escaparValores($proveedor->ubicacion ?: '—') ?>
                            </td>
                            <td class="tabla--centro cifra">
                                <a class="badge badge--info badge--sin-punto"
                                   href="/admin/proveedores/entradas?proveedor=<?= (int) $proveedor->id ?>">
                                    <?= number_format($proveedor->contarEntradas()) ?>
                                </a>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/proveedores/detalle?id=<?= (int) $proveedor->id ?>"
                                       title="Ver detalle" aria-label="Ver detalle de <?= escaparValores($proveedor->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                    </a>
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/proveedores/editar?id=<?= (int) $proveedor->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($proveedor->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <!-- Borrado con confirmación modal (nunca window.confirm). -->
                                    <form action="/admin/proveedores/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Baja de proveedor"
                                          data-confirmacion="¿Eliminar este proveedor?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($proveedor->nombre) ?>» y TODAS sus entradas registradas. Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $proveedor->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($proveedor->nombre) ?>">
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
