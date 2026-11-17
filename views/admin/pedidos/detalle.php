<?php
/** views/admin/pedidos/detalle.php — Ficha de un pedido con sus renglones y acciones. */

soloAdmin();

\App\Pedidos::sincronizarConVentas();

$pedido = \App\Pedidos::buscarID((int) ($_GET['id'] ?? 0));
if (!$pedido) {
    paginaNoEncontrada();
}

$detalles = \App\Pedidos::detalles((int) $pedido->id);
$faltan   = \App\Pedidos::faltantes($detalles);
$estado   = \App\Pedidos::datosEstado($pedido->estado);
$abierto  = in_array($pedido->estado, ['pendiente', 'cotizado'], true);
$sinIva   = round((float) $pedido->subtotal - (float) $pedido->descuento, 2);

template('header', [
    'titulo'     => 'Pedido ' . $pedido->folio,
    'subtitulo'  => 'Pedidos de clientes',
    'rutaActiva' => '/admin/pedidos',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/pedidos?empresa=' . (int) $pedido->empresa_id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Pedidos</span></a>'
        . '<a class="btn btn--secundario" href="/admin/pedidos/pdf?id=' . (int) $pedido->id . '" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>'
        . '<span>PDF</span></a>',
]);
?>

<div class="rejilla-2">
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
                Datos del pedido
            </h2>
            <span class="badge <?= $estado['clase'] ?>"><?= $estado['etiqueta'] ?></span>
        </header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Folio</dt><dd class="cifra"><?= escaparValores($pedido->folio) ?></dd></div>
                <div class="ficha__fila"><dt>Negocio</dt><dd><?= escaparValores($pedido->empresa_nombre) ?></dd></div>
                <div class="ficha__fila"><dt>Cliente</dt><dd>
                    <a href="/admin/clientes/detalle?id=<?= (int) $pedido->cliente_id ?>"><?= escaparValores($pedido->cliente_nombre) ?></a>
                    <?= $pedido->cliente_empresa ? '<br><span class="texto-xs texto-secundario">' . escaparValores($pedido->cliente_empresa) . '</span>' : '' ?>
                </dd></div>
                <div class="ficha__fila"><dt>Fecha</dt><dd><?= date('d/m/Y', strtotime((string) $pedido->fecha)) ?></dd></div>
                <div class="ficha__fila"><dt>Origen</dt><dd><?= $pedido->origen === 'portal' ? 'Portal de clientes' : 'Capturado por ' . escaparValores($pedido->usuario_nombre ?: 'el administrador') ?></dd></div>
                <?php if ($pedido->cotizacion_id) : ?>
                    <div class="ficha__fila"><dt>Cotización</dt><dd><a href="/admin/cotizaciones/detalle?id=<?= (int) $pedido->cotizacion_id ?>"><?= escaparValores($pedido->cotizacion_folio) ?></a></dd></div>
                <?php endif; ?>
                <?php if ($pedido->venta_id) : ?>
                    <div class="ficha__fila"><dt>Venta</dt><dd><a href="/admin/ventas/detalle?id=<?= (int) $pedido->venta_id ?>"><?= escaparValores($pedido->venta_folio) ?></a></dd></div>
                <?php endif; ?>
            </dl>
            <?php if (trim((string) $pedido->comentarios) !== '') : ?>
                <div class="nota mt-md">
                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">sticky_note_2</span>
                    <span><?= nl2br(escaparValores($pedido->comentarios)) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">calculate</span>
                Importes y acciones
            </h2>
        </header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Subtotal</dt><dd class="cifra"><?= formatoMoneda($pedido->subtotal) ?></dd></div>
                <div class="ficha__fila"><dt>Descuento</dt><dd class="cifra">-<?= formatoMoneda($pedido->descuento) ?></dd></div>
                <div class="ficha__fila"><dt>Importe sin IVA</dt><dd class="cifra"><?= formatoMoneda($sinIva) ?></dd></div>
                <div class="ficha__fila"><dt>IVA<?= (int) $pedido->aplica_iva === 1 ? ' (16%)' : ' (no aplica)' ?></dt><dd class="cifra"><?= formatoMoneda($pedido->iva) ?></dd></div>
                <div class="ficha__fila"><dt>Importe con IVA</dt><dd class="cifra"><strong><?= formatoMoneda($pedido->total) ?></strong></dd></div>
            </dl>

            <?php if ($abierto && $faltan !== []) : ?>
                <div class="nota nota--error mt-md" role="status">
                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">inventory</span>
                    <span>No alcanza el inventario para <?= count($faltan) ?> <?= count($faltan) === 1 ? 'producto' : 'productos' ?>.
                        Si lo vendes, la existencia quedará en negativo.</span>
                </div>
            <?php endif; ?>

            <?php if ($abierto) : ?>
            <div class="formulario__acciones mt-md" style="flex-wrap:wrap">
                <?php if ($pedido->estado === 'pendiente') : ?>
                    <a class="btn btn--secundario" href="/admin/pedidos/editar?id=<?= (int) $pedido->id ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span><span>Editar</span></a>
                    <form action="/admin/pedidos/cotizar" method="post" data-validar
                          data-confirmacion="¿Marcar como cotización?"
                          data-confirmacion-mensaje="Se creará una cotización pendiente con los mismos productos, precios e IVA."
                          data-confirmacion-boton="Sí, crear cotización">
                        <input type="hidden" name="id" value="<?= (int) $pedido->id ?>"><input type="hidden" name="volver" value="1">
                        <button class="btn btn--secundario" type="submit">
                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span><span>Marcar como cotización</span></button>
                    </form>
                <?php endif; ?>
                <form action="/admin/pedidos/vender" method="post" data-validar
                      data-confirmacion="¿Marcar como venta?"
                      data-confirmacion-mensaje="<?= $pedido->cotizacion_id ? 'La cotización ' . escaparValores($pedido->cotizacion_folio) . ' pasará a vendida' : 'Se registrará una venta directa' ?>: se descuenta el inventario y nace el ingreso en Contabilidad."
                      data-confirmacion-boton="Sí, vender">
                    <input type="hidden" name="id" value="<?= (int) $pedido->id ?>"><input type="hidden" name="volver" value="1">
                    <button class="btn btn--primario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span><span>Marcar como venta</span></button>
                </form>
                <form action="/admin/pedidos/cancelar" method="post" data-validar
                      data-confirmacion="¿Cancelar el pedido?"
                      data-confirmacion-mensaje="El pedido queda cancelado.<?= $pedido->cotizacion_id ? ' La cotización ' . escaparValores($pedido->cotizacion_folio) . ' no se modifica.' : '' ?>"
                      data-confirmacion-boton="Sí, cancelar">
                    <input type="hidden" name="id" value="<?= (int) $pedido->id ?>"><input type="hidden" name="volver" value="1">
                    <button class="btn btn--peligro" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">cancel</span><span>Cancelar pedido</span></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="tarjeta tarjeta--sin-relleno mt-lg">
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Productos del pedido</caption>
            <thead>
                <tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Cantidad</th>
                    <th scope="col" class="tabla--numerica">Existencia</th>
                    <th scope="col" class="tabla--numerica">Precio</th>
                    <th scope="col" class="tabla--numerica">Desc.</th>
                    <th scope="col" class="tabla--numerica">Importe</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($detalles as $d) : $alcanza = (int) $d['producto_stock'] >= (int) $d['cantidad']; ?>
                <tr>
                    <td><strong><?= escaparValores($d['producto_nombre']) ?></strong>
                        <?= trim((string) $d['producto_sku']) !== '' ? '<br><span class="texto-xs texto-secundario">' . escaparValores($d['producto_sku']) . '</span>' : '' ?></td>
                    <td class="tabla--numerica cifra"><?= (int) $d['cantidad'] ?> <?= escaparValores($d['producto_unidad']) ?></td>
                    <td class="tabla--numerica cifra"><span class="badge badge--sin-punto <?= $alcanza ? 'badge--aprobado' : 'badge--error' ?>"><?= (int) $d['producto_stock'] ?></span></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($d['precio_unitario']) ?></td>
                    <td class="tabla--numerica cifra"><?= (float) $d['descuento_pct'] > 0 ? number_format((float) $d['descuento_pct'], 1) . ' %' : '—' ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($d['subtotal']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php template('footer'); ?>
