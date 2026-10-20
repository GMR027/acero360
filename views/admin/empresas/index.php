<?php
/**
 * views/admin/empresas/index.php — Listado de negocios.
 *
 * Son los dos emisores de cotización del proyecto: Soluciones en Acero y
 * Canastillas de la Baja, con sus datos fiscales.
 */

soloAdmin();

$termino = trim($_GET['q'] ?? '');

$porPagina = 10;
$total     = \App\Empresas::contarTodos($termino);
$pagina    = paginacion($porPagina, $total);
$empresas  = \App\Empresas::mostrarTodos($pagina['limite'], $pagina['offset'], $termino);

$rutaBase = '/admin/empresas' . ($termino !== '' ? '?q=' . rawurlencode($termino) : '');

template('header', [
    'titulo'    => 'Empresas y razón social',
    'subtitulo' => 'Módulo de empresas',
    'acciones'  => '<a class="btn btn--primario" href="/admin/empresas/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_business</span>'
        . '<span>Nuevo negocio</span></a>',
    'rutaActiva' => '/admin/ajustes',
]);
template('tabsAjustes', ['tab' => 'empresas']);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/empresas" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar negocio</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por nombre, razón social o RFC…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($termino !== '') : ?>
                <a class="btn btn--texto btn--sm" href="/admin/empresas"
                   aria-label="Limpiar la búsqueda">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'negocio' : 'negocios' ?>
        </span>
    </div>

    <?php if (empty($empresas)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">corporate_fare</span>
            <p class="vacio__titulo"><?= $termino !== '' ? 'Sin coincidencias' : 'Aún no hay negocios' ?></p>
            <p><?= $termino !== ''
                    ? 'Ningún negocio coincide con "' . escaparValores($termino) . '".'
                    : 'Registra el negocio que emitirá las cotizaciones.' ?></p>
            <a class="btn btn--primario" href="/admin/empresas/crear">Nuevo negocio</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Listado de negocios</caption>
                <thead>
                    <tr>
                        <th scope="col">Negocio</th>
                        <th scope="col">RFC</th>
                        <th scope="col">Domicilio</th>
                        <th scope="col">Contacto</th>
                        <th scope="col" class="tabla--centro">Estado</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empresas as $empresa) : ?>
                        <tr>
                            <td>
                                <div class="tabla__producto">
                                    <?php if ($empresa->logo) : ?>
                                        <img class="tabla__producto-img"
                                             src="<?= escaparValores(\App\Imagenes::url($empresa->logo)) ?>"
                                             alt="Logo de <?= escaparValores($empresa->nombre) ?>">
                                    <?php else : ?>
                                        <span class="tabla__producto-img tabla__producto-img--vacia" aria-hidden="true">storefront</span>
                                    <?php endif; ?>
                                    <div class="tabla__producto-textos">
                                        <strong><?= escaparValores($empresa->nombre) ?></strong>
                                        <span class="texto-xs texto-secundario">
                                            <?= escaparValores($empresa->regimen ?: 'Régimen sin definir') ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="cifra"><?= escaparValores($empresa->rfc ?: '—') ?></td>
                            <td class="texto-sm texto-secundario">
                                <?= escaparValores(trim(($empresa->direccion ?: '') . ' ' . ($empresa->ciudad ?: '')) ?: '—') ?>
                                <?= $empresa->cp ? ' · CP ' . escaparValores($empresa->cp) : '' ?>
                            </td>
                            <td class="texto-sm">
                                <?= escaparValores($empresa->telefono ?: '—') ?><br>
                                <span class="texto-xs texto-secundario"><?= escaparValores($empresa->email ?: '') ?></span>
                            </td>
                            <td class="tabla--centro">
                                <?= (int) $empresa->activa === 1
                                    ? '<span class="badge badge--aprobado">Activo</span>'
                                    : '<span class="badge badge--neutro">Inactivo</span>' ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/empresas/editar?id=<?= (int) $empresa->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($empresa->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <form action="/admin/empresas/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Negocios"
                                          data-confirmacion="¿Eliminar este negocio?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($empresa->nombre) ?>» y sus datos fiscales. Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $empresa->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($empresa->nombre) ?>">
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
