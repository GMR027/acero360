<?php
/**
 * views/admin/proveedores/detalle.php — Ficha de un proveedor.
 *
 * Expediente del proveedor: datos de identidad y contacto, sus compras (con lo recibido, facturado, pagado y
 * pendiente), las recepciones y los pagos. Las «entregas anteriores» (registro fotográfico antiguo) solo
 * aparecen, como historial, si el proveedor las tiene.
 */

soloAdmin();

$proveedor = \App\Proveedores::buscarID((int) ($_GET['id'] ?? 0));

if (!$proveedor) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$totalEntradas = $proveedor->contarEntradas();

/* --- Compras del proveedor (módulo unificado) -------------------------------------------------- */
$idProveedor    = (int) $proveedor->id;
$filtroCompras  = ['proveedor' => $idProveedor];
$totCompras     = \App\OrdenesCompra::totalesFiltrados($filtroCompras);
$comprasRecientes = \App\OrdenesCompra::listar($filtroCompras, 8, 0);
$porRecibirProv = \App\OrdenesCompra::pendientesDeRecibir($idProveedor, 8);
$facturasPend   = \App\OrdenesCompra::listar($filtroCompras + ['cfdi' => 'pendiente_cfdi', 'activas' => true], 8, 0);
$pagosProv      = \App\PagosCompra::listar(['proveedor' => $idProveedor, 'estado' => ''], 8, 0);
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
        . '<span>Nueva compra</span></a>',
]);
template('tabsCompras', ['tab' => 'proveedores']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            <span class="badge badge--info badge--sin-punto"><?= escaparValores($proveedor->tipo_proveedor) ?></span>
            <?php if ((int) $proveedor->activo !== 1) : ?><span class="badge badge--neutro badge--sin-punto">Inactivo</span><?php endif; ?>
            &nbsp; <?= number_format($totCompras['compras']) ?> <?= $totCompras['compras'] === 1 ? 'compra' : 'compras' ?>
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
                <div class="ficha__fila"><dt>Compras registradas</dt><dd class="cifra"><?= number_format($totCompras['compras']) ?></dd></div>
            </dl>
        </div>
    </section>

    <!-- Contacto -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">contact_phone</span>
                Contacto y notas
            </h2>
        </header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Contacto</dt><dd><?= escaparValores($proveedor->contacto ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Correo</dt><dd><?= escaparValores($proveedor->email ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Observaciones</dt><dd><?= nl2br(escaparValores($proveedor->observaciones ?: '—')) ?></dd></div>
                <div class="ficha__fila"><dt>Estado</dt><dd><?= (int) $proveedor->activo === 1
                    ? '<span class="badge badge--aprobado">Activo</span>'
                    : '<span class="badge badge--neutro">Inactivo</span> <span class="texto-xs texto-secundario">No aparece en compras nuevas; su historial se conserva.</span>' ?></dd></div>
            </dl>
        </div>
    </section>
</div>

<section class="rejilla-stats mb-lg" aria-label="Importes del proveedor">
    <article class="stat"><span class="stat__etiqueta">Comprado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($totCompras['comprado']) ?></span><span class="stat__pie">Sin compras canceladas</span></article>
    <article class="stat"><span class="stat__etiqueta">Facturado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($totCompras['facturado']) ?></span>
        <span class="stat__pie"><?= formatoMoneda($totCompras['pendiente_documentar']) ?> pendiente de documentar</span></article>
    <article class="stat"><span class="stat__etiqueta">Pagado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($totCompras['pagado']) ?></span><span class="stat__pie">Pagos vigentes</span></article>
    <article class="stat"><span class="stat__etiqueta">Saldo pendiente</span>
        <span class="stat__valor cifra"><?= formatoMoneda($totCompras['saldo']) ?></span><span class="stat__pie">Comprado menos pagado</span></article>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" id="compras">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Compras</h2>
    <?php if (!$comprasRecientes) : ?>
        <p class="texto-secundario" style="padding:1rem">Este proveedor aún no tiene compras. <a href="/admin/compras/crear?proveedor=<?= $idProveedor ?>">Registrar la primera</a>.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Compras del proveedor</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Tipo</th><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Total</th>
                <th scope="col" class="tabla--numerica">Saldo</th><th scope="col" class="tabla--centro">Recepción</th>
                <th scope="col" class="tabla--centro">Factura</th><th scope="col" class="tabla--centro">Pago</th></tr></thead>
            <tbody>
            <?php foreach ($comprasRecientes as $o) :
                $est = \App\OrdenesCompra::ESTADOS[$o['estado']]; $cf = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']]; $pe = \App\PagosCompra::ESTADOS[$o['estado_pago']]; ?>
                <tr>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"><strong><?= escaparValores($o['folio']) ?></strong></a>
                        <?= $o['referencia'] ? '<span class="texto-xs texto-secundario"><br>Ref. ' . escaparValores($o['referencia']) . '</span>' : '' ?></td>
                    <td><?= $o['tipo'] === 'insumo' ? 'Insumo' : 'Semiterminado' ?></td>
                    <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                    <td class="tabla--numerica cifra"><?= $o['estado'] === 'cancelada' ? '—' : formatoMoneda($o['saldo']) ?></td>
                    <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span></td>
                    <td class="tabla--centro"><span class="badge <?= $cf['clase'] ?>"><?= escaparValores($cf['etiqueta']) ?></span></td>
                    <td class="tabla--centro"><?= $o['estado'] === 'cancelada' ? '—' : '<span class="badge ' . $pe['clase'] . '">' . escaparValores($pe['etiqueta']) . '</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="padding:0 1rem 1rem"><a class="btn btn--texto btn--sm" href="/admin/compras/pedidos?proveedor=<?= $idProveedor ?>">Ver todas las compras de este proveedor</a></p>
    <?php endif; ?>
</section>

<div class="rejilla-2 mb-lg">
    <section class="tarjeta tarjeta--sin-relleno" id="recepciones">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Pendiente de recibir</h2>
        <?php if (!$porRecibirProv) : ?>
            <p class="texto-secundario" style="padding:1rem">Nada pendiente de este proveedor.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Pendiente de recibir</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Artículo</th><th scope="col" class="tabla--numerica">Pendiente</th></tr></thead>
                <tbody>
                <?php foreach ($porRecibirProv as $f) : ?>
                    <tr><td><a href="/admin/compras/recibir?id=<?= (int) $f['orden_id'] ?>"><strong><?= escaparValores($f['folio']) ?></strong></a></td>
                        <td><?= escaparValores($f['descripcion'] ?: $f['nombre_mostrado']) ?></td>
                        <td class="tabla--numerica cifra"><?= $f['pendiente'] ?> de <?= $f['solicitada'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <p style="padding:0 1rem 1rem"><a class="btn btn--texto btn--sm" href="/admin/compras/recepciones?proveedor=<?= $idProveedor ?>">Ver recepciones</a></p>
    </section>

    <section class="tarjeta tarjeta--sin-relleno" id="facturas">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Facturas pendientes</h2>
        <?php if (!$facturasPend) : ?>
            <p class="texto-secundario" style="padding:1rem">Sin facturas pendientes.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Compras pendientes de CFDI</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Total</th></tr></thead>
                <tbody>
                <?php foreach ($facturasPend as $o) : ?>
                    <tr><td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#factura"><strong><?= escaparValores($o['folio']) ?></strong></a></td>
                        <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <p style="padding:0 1rem 1rem"><a class="btn btn--texto btn--sm" href="/admin/compras/facturas?proveedor=<?= $idProveedor ?>">Ver facturas</a></p>
    </section>
</div>

<section class="tarjeta tarjeta--sin-relleno mb-lg" id="pagos">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pagos</h2>
    <?php if (!$pagosProv) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay pagos a este proveedor.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Pagos al proveedor</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Compra</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col">Método</th><th scope="col">Estado</th></tr></thead>
            <tbody>
            <?php foreach ($pagosProv as $pg) : $vig = $pg['estado'] === 'vigente'; ?>
                <tr<?= $vig ? '' : ' style="opacity:.6"' ?>>
                    <td><?= escaparValores(date('d/m/Y', strtotime($pg['fecha']))) ?></td>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $pg['orden_id'] ?>#pagos"><strong><?= escaparValores($pg['folio']) ?></strong></a></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($pg['importe']) ?></td>
                    <td><?= escaparValores((string) $pg['metodo']) ?></td>
                    <td><?= $vig ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--neutro">Cancelado</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <p style="padding:0 1rem 1rem"><a class="btn btn--texto btn--sm" href="/admin/compras/pagos?proveedor=<?= $idProveedor ?>">Ver pagos</a></p>
</section>

<?php if ($totalEntradas > 0) : ?>
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
            Entregas anteriores (historial)
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
                                    <form class="tabla__pago" action="/admin/proveedores/entradas/pago" method="post" data-validar>
                                        <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                        <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                                        <label class="solo-lectores" for="pago-<?= (int) $pedido->id ?>">Estado de pago del pedido <?= escaparValores($pedido->numero_pedido ?: (string) $pedido->id) ?></label>
                                        <select class="control control--compacto" id="pago-<?= (int) $pedido->id ?>" name="pagado">
                                            <option value=""<?= $estaPagado ? '' : ' selected' ?>>Pendiente de pago</option>
                                            <option value="1"<?= $estaPagado ? ' selected' : '' ?>>Pagado</option>
                                        </select>
                                        <button type="submit" class="btn btn--secundario btn--sm">Guardar</button>
                                    </form>
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
<?php endif; ?>

<?php template('footer'); ?>
