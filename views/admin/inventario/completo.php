<?php
/**
 * views/admin/inventario/completo.php — Vista compacta del inventario para celular:
 * solo imagen, producto y cantidad en existencia. Sin edición.
 */

soloAdmin();

$termino   = trim($_GET['q'] ?? '');
$filtros   = ['q' => $termino, 'empresa' => 0, 'estado' => 'todos'];
$porPagina = 30;
$total     = \App\Inventario::contar($filtros);
$pagina    = paginacion($porPagina, $total);
$productos = \App\Inventario::listar($filtros, $pagina['limite'], $pagina['offset']);

$rutaBase = '/admin/inventario/completo' . ($termino !== '' ? '?' . http_build_query(['q' => $termino]) : '');

template('header', [
    'titulo'     => 'Inventario completo',
    'subtitulo'  => 'Existencias de un vistazo',
    'rutaActiva' => '/admin/inventario',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/inventario"><span>Volver al inventario</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/inventario/completo" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar producto</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar producto…" value="<?= escaparValores($termino) ?>">
            </div>
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
        </form>
    </div>

    <?php if (empty($productos)) : ?>
        <div class="vacio"><p class="vacio__titulo">Sin productos</p></div>
    <?php else : ?>
        <ul class="inventario-lista">
            <?php foreach ($productos as $producto) :
                $stock = (int) $producto->stock; ?>
                <li class="inventario-lista__item">
                    <?php if ($producto->imagen) : ?>
                        <button type="button" class="miniatura-producto"
                                data-ampliar-imagen="<?= escaparValores(\App\Imagenes::url($producto->imagen)) ?>"
                                data-ampliar-titulo="<?= escaparValores($producto->nombre) ?>"
                                aria-label="Ampliar la imagen de <?= escaparValores($producto->nombre) ?>">
                            <img src="<?= escaparValores(\App\Imagenes::urlMiniatura($producto->imagen)) ?>"
                                 alt="" width="56" height="56" loading="lazy" decoding="async">
                        </button>
                    <?php else : ?>
                        <span class="miniatura-producto miniatura-producto--vacia" role="img" aria-label="Sin imagen">
                            <span class="material-symbols-outlined" aria-hidden="true">image_not_supported</span>
                        </span>
                    <?php endif; ?>
                    <span class="inventario-lista__nombre"><?= escaparValores($producto->nombre) ?></span>
                    <strong class="inventario-lista__stock cifra<?= $stock <= 0 ? ' texto-error' : '' ?>"><?= number_format($stock) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
