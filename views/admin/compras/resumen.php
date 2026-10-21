<?php
/**
 * views/admin/compras/resumen.php — Resumen de compras: indicadores y pendientes importantes.
 * Solo consulta: abrirla nunca modifica inventario, pagos ni contabilidad.
 */

soloAdmin();

$ind = \App\OrdenesCompra::indicadores();
$porRecibir = \App\OrdenesCompra::pendientesDeRecibir(0, 8);
$sinCfdi = \App\OrdenesCompra::listar(['activas' => true, 'cfdi' => 'pendiente_cfdi'], 6, 0);
$conSaldo = \App\OrdenesCompra::listar(['pago' => 'con_saldo'], 6, 0);

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Resumen',
    'acciones'  => '<a class="btn btn--primario" href="/admin/compras/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
        . '<span>Nueva compra</span></a>',
]);
template('tabsCompras', ['tab' => 'resumen']);
?>

<section class="rejilla-stats mb-lg" aria-label="Indicadores de compras">
    <a class="stat stat--enlace" href="/admin/compras/recepciones">
        <span class="stat__etiqueta">Por recibir</span>
        <span class="stat__valor cifra"><?= number_format($ind['por_recibir']) ?></span>
        <span class="stat__pie">Compras con piezas pendientes</span>
    </a>
    <a class="stat stat--enlace" href="/admin/compras/recepciones#por-terminar">
        <span class="stat__etiqueta">Por terminar</span>
        <span class="stat__valor cifra"><?= number_format($ind['piezas_por_terminar']) ?></span>
        <span class="stat__pie">Piezas recibidas, aún no vendibles</span>
    </a>
    <a class="stat stat--enlace" href="/admin/compras/facturas?cfdi=pendiente_cfdi">
        <span class="stat__etiqueta">Facturas pendientes</span>
        <span class="stat__valor cifra"><?= number_format($ind['sin_cfdi']) ?></span>
        <span class="stat__pie"><?= formatoMoneda($ind['importe_sin_cfdi']) ?> sin documentar</span>
    </a>
    <a class="stat stat--enlace" href="/admin/compras/pagos">
        <span class="stat__etiqueta">Por pagar</span>
        <span class="stat__valor cifra"><?= formatoMoneda($ind['saldo']) ?></span>
        <span class="stat__pie"><?= number_format($ind['con_saldo']) ?> compra(s) con saldo</span>
    </a>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Piezas por recibir</h2>
    <?php if (!$porRecibir) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay piezas pendientes de recibir.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Piezas pendientes de recibir</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Artículo</th>
                <th scope="col" class="tabla--numerica">Pendiente</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($porRecibir as $f) : ?>
                <tr>
                    <td><strong><?= escaparValores($f['folio']) ?></strong></td>
                    <td><?= escaparValores($f['proveedor_nombre']) ?></td>
                    <td><?= escaparValores($f['nombre_mostrado']) ?>
                        <?php if (!$f['producto_id'] && !$f['material_id']) : ?><span class="badge badge--pendiente">Sin relacionar</span><?php endif; ?></td>
                    <td class="tabla--numerica cifra"><?= $f['pendiente'] ?> de <?= $f['solicitada'] ?></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/recibir?id=<?= (int) $f['orden_id'] ?>">Recibir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<div class="rejilla-2">
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Pendientes de factura (CFDI)</h2>
        <?php if (!$sinCfdi) : ?>
            <p class="texto-secundario" style="padding:1rem">Todas las compras tienen su documentación al día.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Compras pendientes de CFDI</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col" class="tabla--numerica">Total</th></tr></thead>
                <tbody>
                <?php foreach ($sinCfdi as $o) : ?>
                    <tr>
                        <td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"><strong><?= escaparValores($o['folio']) ?></strong></a></td>
                        <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Pagos pendientes</h2>
        <?php if (!$conSaldo) : ?>
            <p class="texto-secundario" style="padding:1rem">No hay saldos pendientes con proveedores.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Compras con saldo</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col" class="tabla--numerica">Saldo</th></tr></thead>
                <tbody>
                <?php foreach ($conSaldo as $o) : ?>
                    <tr>
                        <td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#pagos"><strong><?= escaparValores($o['folio']) ?></strong></a></td>
                        <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($o['saldo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>

<?php template('footer'); ?>
