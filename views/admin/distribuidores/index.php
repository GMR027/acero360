<?php
/**
 * views/admin/distribuidores/index.php — Distribuidores autorizados.
 *
 * Puntos de venta que el sitio de Soluciones en Acero muestra en
 * /distribuidores (migración 056). Búsqueda por nombre, sucursal o dirección
 * y filtro por municipio; el listado sigue el orden del sitio.
 */

soloAdmin();

$termino   = trim($_GET['q'] ?? '');
$municipio = trim($_GET['municipio'] ?? '');
$municipios = \App\Distribuidores::municipios();
if ($municipio !== '' && !in_array($municipio, $municipios, true)) {
    $municipio = '';
}

$porPagina = 20;
$total     = \App\Distribuidores::contarTodos($termino, $municipio);
$pagina    = paginacion($porPagina, $total);
$lista     = \App\Distribuidores::mostrarTodos($pagina['limite'], $pagina['offset'], $termino, $municipio);
$enSitio   = \App\Distribuidores::contarPublicados();

$parametros = array_filter(['q' => $termino, 'municipio' => $municipio], static fn ($v) => $v !== '');
$rutaBase   = '/admin/distribuidores' . ($parametros ? '?' . http_build_query($parametros) : '');
$hayFiltros = !empty($parametros);
$volverA    = $_SERVER['REQUEST_URI'] ?? '/admin/distribuidores';

template('header', [
    'titulo'    => 'Distribuidores',
    'subtitulo' => 'Puntos de venta del sitio web',
    'acciones'  => '<a class="btn btn--secundario" href="' . \App\Distribuidores::SITIO_URL . '/distribuidores" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">open_in_new</span>'
        . '<span>Ver en el sitio</span></a>'
        . '<a class="btn btn--primario" href="/admin/distribuidores/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_business</span>'
        . '<span>Nuevo distribuidor</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Los distribuidores publicados aparecen en la página «Distribuidores» del sitio de Soluciones en Acero,
            agrupados por municipio y en el orden de esta lista. Hoy se muestran
            <strong><?= number_format($enSitio) ?></strong>.
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/distribuidores" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar distribuidor</label>
                <input class="control" type="search" id="q" name="q" maxlength="120"
                       placeholder="Buscar por nombre, sucursal o dirección…"
                       value="<?= escaparValores($termino) ?>">
            </div>
            <label class="solo-lectores" for="municipio">Municipio</label>
            <select class="control" id="municipio" name="municipio" style="max-width:14rem">
                <option value="">Todos los municipios</option>
                <?php foreach ($municipios as $opcion) : ?>
                    <option value="<?= escaparValores($opcion) ?>" <?= $opcion === $municipio ? 'selected' : '' ?>><?= escaparValores($opcion) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/distribuidores" aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'distribuidor' : 'distribuidores' ?>
        </span>
    </div>

    <?php if (empty($lista)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">storefront</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay distribuidores' ?></p>
            <p><?= $hayFiltros
                    ? 'Ningún distribuidor coincide con los filtros aplicados.'
                    : 'Agrega el primer punto de venta para mostrarlo en el sitio.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/distribuidores">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/distribuidores/crear">Nuevo distribuidor</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Distribuidores autorizados</caption>
                <thead>
                    <tr>
                        <th scope="col" class="tabla--centro">Orden</th>
                        <th scope="col">Distribuidor</th>
                        <th scope="col">Municipio</th>
                        <th scope="col">Dirección</th>
                        <th scope="col" class="tabla--centro">Sitio web</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $d) : ?>
                        <?php $miniatura = $d->urlImagen(true); ?>
                        <tr>
                            <td class="tabla--centro cifra texto-secundario"><?= (int) $d->orden ?></td>
                            <td>
                                <div class="tabla__producto">
                                    <?php if ($miniatura !== '') : ?>
                                        <img class="tabla__producto-img" src="<?= escaparValores($miniatura) ?>"
                                             alt="Fachada de <?= escaparValores($d->nombre) ?>" loading="lazy">
                                    <?php else : ?>
                                        <span class="tabla__producto-img tabla__producto-img--vacia" aria-hidden="true">storefront</span>
                                    <?php endif; ?>
                                    <div class="tabla__producto-textos">
                                        <strong><?= escaparValores($d->nombre) ?></strong>
                                        <span class="texto-xs texto-secundario">
                                            <?= $d->sucursal !== '' ? 'Sucursal ' . escaparValores($d->sucursal) : 'Sin sucursal' ?>
                                            <?= $d->telefono !== '' ? ' · ' . escaparValores($d->telefono) : '' ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="texto-sm">
                                <a href="/admin/distribuidores?<?= escaparValores(http_build_query(['municipio' => $d->municipio])) ?>"><?= escaparValores($d->municipio) ?></a>
                            </td>
                            <td class="texto-sm" style="max-width:22rem"><?= escaparValores($d->direccion) ?></td>
                            <td class="tabla--centro">
                                <form action="/admin/distribuidores/publicar" method="post" data-validar>
                                    <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                    <input type="hidden" name="publicado" value="<?= (int) $d->publicado === 1 ? 0 : 1 ?>">
                                    <input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                                    <button type="submit" class="badge badge--sin-punto <?= (int) $d->publicado === 1 ? 'badge--aprobado' : 'badge--neutro' ?>"
                                            style="cursor:pointer;border:0"
                                            title="<?= (int) $d->publicado === 1 ? 'Ocultar del sitio' : 'Publicar en el sitio' ?>">
                                        <?= (int) $d->publicado === 1 ? 'Publicado' : 'Oculto' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <?php if ($d->maps !== '') : ?>
                                        <a class="btn btn--secundario btn--sm btn--icono" href="<?= escaparValores($d->maps) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           title="Abrir en Maps" aria-label="Abrir <?= escaparValores($d->nombre) ?> en Maps">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">map</span>
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/distribuidores/editar?id=<?= (int) $d->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($d->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>
                                    <form action="/admin/distribuidores/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Distribuidores"
                                          data-confirmacion="¿Eliminar este distribuidor?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($d->nombre) ?>» y dejará de verse en el sitio. Si sólo quieres ocultarlo, usa «Publicado». Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($d->nombre) ?>">
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
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
