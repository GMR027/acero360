<?php
/**
 * views/admin/catalogos/index.php — Listado de un catálogo de texto
 * (categorías de producto o ubicaciones de cliente). Lo incluye el envoltorio
 * de cada ruta después de fijar $catalogo. Ver App\CatalogoTexto.
 */

soloAdmin();

$c         = \App\CatalogoTexto::config($catalogo);
$termino   = trim($_GET['q'] ?? '');
$porPagina = 15;
$total     = \App\CatalogoTexto::contarTodos($catalogo, $termino);
$pagina    = paginacion($porPagina, $total);
$lista     = \App\CatalogoTexto::mostrarTodos($catalogo, $pagina['limite'], $pagina['offset'], $termino);

$rutaBase = $c['ruta'] . ($termino !== '' ? '?' . http_build_query(['q' => $termino]) : '');
$hayFiltros = $termino !== '';
$Singular = mb_strtoupper(mb_substr($c['singular'], 0, 1)) . mb_substr($c['singular'], 1);

template('header', [
    'titulo'     => $c['titulo'],
    'subtitulo'  => $c['padre'],
    'rutaActiva' => $c['rutaPadre'],
    'acciones'   => '<a class="btn btn--secundario" href="' . $c['rutaPadre'] . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver</span></a>'
        . '<a class="btn btn--primario" href="' . $c['ruta'] . '/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nueva ' . escaparValores($c['singular']) . '</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Las <?= escaparValores($c['plural']) ?> de esta lista se ofrecen al capturar
            <?= escaparValores($c['usoPlural']) ?>. Una <?= escaparValores($c['singular']) ?> en uso no se puede
            borrar, pero sí renombrar: el cambio se refleja en todos los <?= escaparValores($c['usoPlural']) ?> que la usan.
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="<?= $c['ruta'] ?>" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar <?= escaparValores($c['singular']) ?></label>
                <input class="control" type="search" id="q" name="q" maxlength="<?= (int) $c['maximo'] ?>"
                       placeholder="Buscar <?= escaparValores($c['singular']) ?>…"
                       value="<?= escaparValores($termino) ?>">
            </div>
            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="<?= $c['ruta'] ?>" aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= escaparValores($total === 1 ? $c['singular'] : $c['plural']) ?>
        </span>
    </div>

    <?php if (empty($lista)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true"><?= $c['icono'] ?></span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay ' . escaparValores($c['plural']) ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="<?= $c['ruta'] ?>">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="<?= $c['ruta'] ?>/crear">Nueva <?= escaparValores($c['singular']) ?></a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores"><?= escaparValores($c['titulo']) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= escaparValores($Singular) ?></th>
                        <th scope="col" class="tabla--centro"><?= escaparValores(ucfirst($c['usoPlural'])) ?></th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $fila) : ?>
                        <?php $enUso = (int) $fila->usos; ?>
                        <tr>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($fila->nombre) ?></strong>
                                    <span class="texto-xs texto-secundario texto-una-linea">
                                        Alta <?= escaparValores(substr((string) $fila->creado_en, 0, 10)) ?>
                                        <?php if (!empty($c['web']) && $fila->slug !== '') : ?>
                                            · web: /productos/<?= escaparValores($fila->slug) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="tabla--centro cifra">
                                <?php if ($c['buscarUso']) : ?>
                                    <a class="badge badge--sin-punto <?= $enUso > 0 ? 'badge--info' : 'badge--neutro' ?>"
                                       href="<?= $c['buscarUso'] . rawurlencode($fila->nombre) ?>"><?= number_format($enUso) ?></a>
                                <?php else : ?>
                                    <span class="badge badge--sin-punto <?= $enUso > 0 ? 'badge--info' : 'badge--neutro' ?>"><?= number_format($enUso) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="<?= $c['ruta'] ?>/editar?id=<?= (int) $fila->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($fila->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>
                                    <form action="<?= $c['ruta'] ?>/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Baja de <?= escaparValores($c['singular']) ?>"
                                          data-confirmacion="¿Eliminar esta <?= escaparValores($c['singular']) ?>?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($fila->nombre) ?>»<?= $enUso > 0 ? '… pero la usan ' . number_format($enUso) . ' ' . escaparValores($c['usoPlural']) . ', así que no se podrá eliminar hasta cambiarles la ' . escaparValores($c['singular']) : '. No está en uso' ?>."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $fila->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($fila->nombre) ?>">
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
