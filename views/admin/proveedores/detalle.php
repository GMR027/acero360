<?php
/**
 * views/admin/proveedores/detalle.php — Ficha de un proveedor.
 *
 * Muestra los datos de identidad y contacto, y las últimas entradas
 * (reportes fotográficos) que se le han registrado.
 */

soloAdmin();

$proveedor = \App\Proveedores::buscarID((int) ($_GET['id'] ?? 0));

if (!$proveedor) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$totalEntradas = $proveedor->contarEntradas();
$entradas      = \App\ProveedorEntradas::mostrarTodos(['proveedor' => (int) $proveedor->id], 5, 0);

/* --- Balance general -------------------------------------------------------
 * Todos los pedidos del proveedor con su estado de pago, en una sola pasada
 * sobre la base: total, pagado y pendiente salen del mismo query, de modo que
 * los tres números siempre cuadran entre sí.
 *
 * El filtro de categoría entra en ESTE mismo arreglo, así que la tabla y los
 * cuatro indicadores se reducen juntos: si se filtrara sólo la lista, la
 * suma diría una cosa y los renglones otra. */
$categoriaFiltro = (int) ($_GET['categoria'] ?? 0);
if (!isset(\App\CategoriasProveedor::mapa()[$categoriaFiltro])) {
    $categoriaFiltro = 0; // Cualquier id inventado se trata como "sin filtro".
}

$filtroProveedor = [
    'proveedor' => (int) $proveedor->id,
    'categoria' => $categoriaFiltro,
];
$balance         = \App\ProveedorEntradas::balance($filtroProveedor);

$porPagina       = 15;
$paginaBalance   = paginacion($porPagina, $balance['entradas']);
$listaBalance    = \App\ProveedorEntradas::mostrarTodos(
    $filtroProveedor,
    $paginaBalance['limite'],
    $paginaBalance['offset']
);

/** URL del detalle conservando proveedor y categoría (paginación y vuelta tras pagar). */
$rutaBalance = '/admin/proveedores/detalle?id=' . (int) $proveedor->id;
if ($categoriaFiltro > 0) {
    $rutaBalance .= '&categoria=' . $categoriaFiltro;
}

template('header', [
    'titulo'    => $proveedor->nombre,
    'subtitulo' => 'Ficha de proveedor',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores">Volver</a>'
        . '<a class="btn btn--secundario" href="/admin/proveedores/editar?id=' . (int) $proveedor->id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>'
        . '<span>Editar</span></a>'
        . '<a class="btn btn--primario" href="/admin/compras/crear?proveedor=' . (int) $proveedor->id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_shopping_cart</span>'
        . '<span>Nueva orden de compra</span></a>',
]);
template('tabsCompras', ['tab' => 'proveedores']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            <span class="badge badge--info badge--sin-punto"><?= escaparValores($proveedor->tipo_proveedor) ?></span>
            &nbsp; <?= number_format($totalEntradas) ?>
            <?= $totalEntradas === 1 ? 'entrega anterior registrada' : 'entregas anteriores registradas' ?>
        </p>
    </div>
</section>

<div class="rejilla-2">
    <!-- Datos del proveedor -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">badge</span>
                Datos del proveedor
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>RFC</dt><dd class="cifra"><?= escaparValores($proveedor->rfc ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Teléfono</dt><dd class="cifra"><?= escaparValores($proveedor->telefono ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Ubicación</dt><dd><?= escaparValores($proveedor->ubicacion ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Tipo de proveedor</dt><dd><?= escaparValores($proveedor->tipo_proveedor) ?></dd></div>
                <div class="ficha__fila"><dt>Entradas registradas</dt><dd class="cifra"><?= number_format($totalEntradas) ?></dd></div>
            </dl>
        </div>
    </section>

    <!-- Últimas entradas -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">assignment</span>
                Últimas entradas
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <?php if (empty($entradas)) : ?>
                <p class="texto-secundario">
                    Sin entregas anteriores. Las compras nuevas se registran con una orden de compra.
                </p>
                <a class="btn btn--primario btn--sm" href="/admin/compras/crear?proveedor=<?= (int) $proveedor->id ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_shopping_cart</span>
                    <span>Nueva orden de compra</span>
                </a>
            <?php else : ?>
                <ul class="lista-simple">
                    <?php foreach ($entradas as $entrada) : ?>
                        <li class="lista-simple__elemento">
                            <div class="tabla__producto-textos">
                                <strong><?= escaparValores(
                                    (int) $entrada->categoria > 0
                                        ? \App\ProveedorEntradas::nombreCategoria((int) $entrada->categoria)
                                        : 'Entrada de material'
                                ) ?></strong>
                                <span class="texto-xs texto-secundario texto-una-linea">
                                    <?= date('d/m/Y', strtotime($entrada->fecha)) ?>
                                    <?= $entrada->numero_pedido !== '' ? ' · Pedido ' . escaparValores($entrada->numero_pedido) : '' ?>
                                </span>
                            </div>
                            <a class="btn btn--secundario btn--sm btn--icono"
                               href="/admin/proveedores/entradas/detalle?id=<?= (int) $entrada->id ?>"
                               title="Ver detalle" aria-label="Ver la entrada">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <a class="btn btn--texto btn--sm mt-md" href="/admin/proveedores/entradas?proveedor=<?= (int) $proveedor->id ?>">
                    <span>Ver todas las entradas</span>
                </a>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ---------------------------------------------------------------------------
     Balance general: todos los pedidos del proveedor con su estado de pago.
     El verde marca "ya se pagó"; si ya está pagado, el mismo botón (en su
     variante secundaria) devuelve la entrada a pendiente. Es reversible, así
     que no pide confirmación.
     ------------------------------------------------------------------------- -->
<section class="tarjeta mb-lg" aria-labelledby="titulo-balance">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo" id="titulo-balance">
            <span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span>
            Balance general
        </h2>

        <?php if ($categoriaFiltro > 0) : ?>
            <span class="badge badge--info badge--sin-punto">
                Categoría: <?= escaparValores(\App\ProveedorEntradas::nombreCategoria($categoriaFiltro)) ?>
            </span>
        <?php endif; ?>

        <?php if ($balance['pendiente'] > 0) : ?>
            <span class="badge badge--pendiente">
                <?= number_format($balance['pendientes']) ?>
                <?= $balance['pendientes'] === 1 ? 'pedido pendiente' : 'pedidos pendientes' ?>
            </span>
        <?php elseif ($balance['entradas'] > 0) : ?>
            <span class="badge badge--aprobado">Todo pagado</span>
        <?php endif; ?>
    </header>

    <div class="tarjeta__cuerpo">
        <?php if ($totalEntradas > 0) : ?>
            <!-- Filtro por categoría. Va ANTES de los indicadores y la tabla
                 para que los dos se reduzcan con el mismo criterio. -->
            <form class="tabla-toolbar tabla-toolbar--sin-borde" method="get"
                  action="/admin/proveedores/detalle">
                <input type="hidden" name="id" value="<?= (int) $proveedor->id ?>">

                <div class="buscador">
                    <div class="buscador__campo">
                        <label class="solo-lectores" for="categoria-balance">Categoría</label>
                        <select class="control" id="categoria-balance" name="categoria">
                            <option value="0">Todas las categorías</option>
                            <?php foreach (\App\CategoriasProveedor::mapa() as $idCat => $nombreCat) : ?>
                                <option value="<?= (int) $idCat ?>"<?= $categoriaFiltro === $idCat ? ' selected' : '' ?>>
                                    <?= escaparValores($nombreCat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn--primario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                        <span>Buscar</span>
                    </button>
                    <a class="btn btn--secundario"
                       href="/admin/proveedores/detalle?id=<?= (int) $proveedor->id ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">filter_alt_off</span>
                        <span>Limpiar</span>
                    </a>
                </div>
            </form>
        <?php endif; ?>

        <section class="rejilla-stats mb-md" aria-label="Resumen de pagos del proveedor">
            <article class="stat">
                <span class="stat__etiqueta">Entregas</span>
                <span class="stat__valor cifra"><?= number_format($balance['entradas']) ?></span>
                <span class="stat__pie">Pedidos registrados</span>
            </article>

            <article class="stat">
                <span class="stat__etiqueta">Total</span>
                <span class="stat__valor cifra"><?= formatoMoneda($balance['total']) ?></span>
                <span class="stat__pie">Suma de todos los montos</span>
            </article>

            <article class="stat">
                <span class="stat__etiqueta">Pagado</span>
                <span class="stat__valor cifra"><?= formatoMoneda($balance['pagado']) ?></span>
                <span class="stat__pie">Pedidos ya liquidados</span>
            </article>

            <article class="stat">
                <span class="stat__etiqueta">Pendiente por pagar</span>
                <span class="stat__valor cifra"><?= formatoMoneda($balance['pendiente']) ?></span>
                <span class="stat__pie">Lo que aún se le debe</span>
            </article>
        </section>

        <?php if (empty($listaBalance)) : ?>
            <p class="texto-secundario">
                <?php if ($categoriaFiltro > 0) : ?>
                    Este proveedor no tiene pedidos en la categoría
                    <strong><?= escaparValores(\App\ProveedorEntradas::nombreCategoria($categoriaFiltro)) ?></strong>.
                    <a href="/admin/proveedores/detalle?id=<?= (int) $proveedor->id ?>">Ver todos</a>
                <?php else : ?>
                    Este proveedor todavía no tiene entregas registradas.
                <?php endif; ?>
            </p>
        <?php else : ?>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <caption class="solo-lectores">Pedidos y estado de pago de <?= escaparValores($proveedor->nombre) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Número de pedido</th>
                            <th scope="col" class="tabla__col-secundaria">Categoría</th>
                            <th scope="col" class="tabla--numerica">Monto</th>
                            <th scope="col" class="tabla--centro">Imagen de entrega</th>
                            <th scope="col" class="tabla--centro">Pago</th>
                            <th scope="col" class="tabla--numerica">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaBalance as $pedido) :
                            $estaPagado = (int) $pedido->pagado === 1;
                            // Vuelta a la misma página del balance tras marcar el pago.
                            $retorno = $rutaBalance
                                . ($paginaBalance['paginaActual'] > 1 ? '&pagina=' . $paginaBalance['paginaActual'] : '');
                            ?>
                            <tr>
                                <td class="cifra"><?= escaparValores($pedido->numero_pedido ?: '—') ?></td>
                                <td class="tabla__col-secundaria texto-sm">
                                    <?= escaparValores(\App\ProveedorEntradas::nombreCategoria((int) $pedido->categoria)) ?>
                                </td>
                                <td class="tabla--numerica cifra"><?= formatoMoneda($pedido->monto) ?></td>
                                <td class="tabla--centro">
                                    <?php if ($pedido->foto !== '') : ?>
                                        <img class="tabla__miniatura"
                                             src="<?= escaparValores(\App\Imagenes::url($pedido->foto)) ?>"
                                             alt="">
                                    <?php else : ?>
                                        <span class="texto-sm texto-secundario">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="tabla--centro">
                                    <div class="tabla__pago">
                                        <!-- Estado SIEMPRE visible: "Pendiente" hasta
                                             que se pague, "Pagado" en cuanto se marca. -->
                                        <?php if ($estaPagado) : ?>
                                            <span class="badge badge--aprobado badge--sin-punto">Pagado</span>
                                        <?php else : ?>
                                            <span class="badge badge--pendiente badge--sin-punto">Pendiente</span>
                                        <?php endif; ?>

                                        <form action="/admin/proveedores/entradas/pago" method="post" data-validar>
                                            <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                            <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                                            <?php if ($estaPagado) : ?>
                                                <input type="hidden" name="pagado" value="">
                                                <button type="submit" class="btn btn--secundario btn--sm"
                                                        aria-label="Deshacer el pago del pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?>">
                                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">replay</span>
                                                    <span>Deshacer</span>
                                                </button>
                                            <?php else : ?>
                                                <input type="hidden" name="pagado" value="1">
                                                <button type="submit" class="btn btn--exito btn--sm"
                                                        aria-label="Marcar como pagado el pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?>">
                                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">check_circle</span>
                                                    <span>Pagado</span>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                                <td class="tabla--numerica">
                                    <div class="tabla__acciones">
                                        <a class="btn btn--secundario btn--sm btn--icono"
                                           href="/admin/proveedores/entradas/detalle?id=<?= (int) $pedido->id ?>"
                                           title="Ver detalle" aria-label="Ver detalle del pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                        </a>
                                        <a class="btn btn--secundario btn--sm btn--icono"
                                           href="/admin/proveedores/entradas/editar?id=<?= (int) $pedido->id ?>"
                                           title="Editar" aria-label="Editar el pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                        </a>

                                        <!-- Borrado con confirmación modal (nunca window.confirm). -->
                                        <form action="/admin/proveedores/entradas/eliminar" method="post" data-validar
                                              data-confirmacion-sobre="Baja de entrada"
                                              data-confirmacion="¿Eliminar esta entrada?"
                                              data-confirmacion-mensaje="Se borrará la entrada con pedido «<?= escaparValores($pedido->numero_pedido ?: 'sin número') ?>» y su fotografía. Esta acción no se puede deshacer."
                                              data-confirmacion-boton="Sí, eliminar">
                                            <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                            <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                    title="Eliminar" aria-label="Eliminar el pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?>">
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
                'pagina'   => $paginaBalance,
                'rutaBase' => $rutaBalance,
                'total'    => $balance['entradas'],
            ]);
            ?>
        <?php endif; ?>
    </div>
</section>

<?php template('footer'); ?>
