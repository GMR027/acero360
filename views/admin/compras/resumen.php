<?php
/**
 * views/admin/compras/resumen.php — Resumen de compras, pensado como punto de partida: el recorrido de una compra (pedir, recibir, facturar, pagar)
 * y, debajo, lo que está pendiente en cada paso con el botón para atenderlo.
 * Solo consulta: abrirla nunca modifica inventario, pagos ni contabilidad.
 */

soloAdmin();

$ind = \App\OrdenesCompra::indicadores();
$porRecibir = \App\OrdenesCompra::pendientesDeRecibir(0, 10);
$ultimas = \App\OrdenesCompra::listar([], 10, 0);
$sinCfdi = \App\OrdenesCompra::listar(['activas' => true, 'cfdi' => 'pendiente_cfdi'], 10, 0);
$conSaldo = \App\OrdenesCompra::listar(['pago' => 'con_saldo'], 10, 0);

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Resumen',
    'acciones'  => '<a class="btn btn--primario" href="/admin/compras/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
        . '<span>Nueva compra</span></a>',
]);
template('tabsCompras', ['tab' => 'resumen']);

/** Encabezado de cada bloque: número de paso, qué es y enlace a la lista completa. */
$bloque = static function (string $id, string $paso, string $titulo, string $ayuda, string $enlace, string $textoEnlace): void { ?>
    <header class="flex-entre fila--envuelto" style="padding:1rem 1rem 0;gap:.5rem" id="<?= $id ?>">
        <div>
            <h2 class="titulo-5"><?= $paso !== '' ? '<span class="badge badge--info badge--sin-punto">' . $paso . '</span> ' : '' ?><?= escaparValores($titulo) ?></h2>
            <p class="texto-sm texto-secundario"><?= escaparValores($ayuda) ?></p>
        </div>
        <a class="btn btn--texto btn--sm" href="<?= $enlace ?>"><?= escaparValores($textoEnlace) ?> →</a>
    </header>
<?php };
?>

<p class="texto-secundario mb-md">Una compra sigue cuatro pasos: <strong>1 · Pedir</strong> al proveedor, <strong>2 · Recibir</strong> lo que llega,
    <strong>3 · Adjuntar la factura</strong> y <strong>4 · Pagar</strong>. Toca un paso para ver lo que tienes pendiente.</p>

<section class="rejilla-stats mb-lg" aria-label="Pasos de una compra">
    <a class="stat stat--enlace" href="/admin/compras/crear">
        <span class="stat__etiqueta">1 · Pedir</span>
        <span class="stat__valor cifra">Nueva compra</span>
        <span class="stat__pie">Pide al proveedor · últimas compras abajo</span>
    </a>
    <a class="stat stat--enlace" href="#por-recibir">
        <span class="stat__etiqueta">2 · Recibir</span>
        <span class="stat__valor cifra"><?= number_format($ind['por_recibir']) ?></span>
        <span class="stat__pie">compra(s) con piezas por recibir</span>
    </a>
    <a class="stat stat--enlace" href="#por-facturar">
        <span class="stat__etiqueta">3 · Adjuntar factura</span>
        <span class="stat__valor cifra"><?= number_format($ind['sin_cfdi']) ?></span>
        <span class="stat__pie"><?= formatoMoneda($ind['importe_sin_cfdi']) ?> sin factura</span>
    </a>
    <a class="stat stat--enlace" href="#por-pagar">
        <span class="stat__etiqueta">4 · Pagar</span>
        <span class="stat__valor cifra"><?= formatoMoneda($ind['saldo']) ?></span>
        <span class="stat__pie"><?= number_format($ind['con_saldo']) ?> compra(s) con saldo</span>
    </a>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <?php $bloque('ultimas', '1', 'Compras y pedidos recientes', 'Las últimas 10 compras. Entra a una para ver su detalle, recibirla, adjuntar la factura o pagarla.', '/admin/compras/pedidos', 'Ver todas las compras'); ?>
    <?php if (!$ultimas) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay compras. Empieza con «Nueva compra».</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Últimas compras</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Total</th>
                <th scope="col" class="tabla--centro">Recepción</th><th scope="col" class="tabla--centro">Factura</th><th scope="col" class="tabla--centro">Pago</th>
                <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($ultimas as $o) :
                $est = \App\OrdenesCompra::ESTADOS[$o['estado']];
                $cfdi = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']];
                $pago = \App\PagosCompra::ESTADOS[$o['estado_pago']]; ?>
                <tr>
                    <td><strong><?= escaparValores($o['folio']) ?></strong></td>
                    <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                    <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                    <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span>
                        <?php if (trim((string) $o['motivo_cierre']) !== '') : ?><span title="Nota de cierre: <?= escaparValores($o['motivo_cierre']) ?>" style="font-weight:700;color:var(--color-advertencia-texto)">!</span><?php endif; ?></td>
                    <td class="tabla--centro"><span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span></td>
                    <td class="tabla--centro"><?= $o['estado'] === 'cancelada' ? '—' : '<span class="badge ' . $pago['clase'] . '">' . escaparValores($pago['etiqueta']) . '</span>' ?></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <?php $bloque('por-recibir', '2', 'Piezas por recibir', 'Lo que pediste y todavía no llega completo. «Recibir» te deja anotar cuánto llegó.', '/admin/compras/recepciones', 'Ver recepciones'); ?>
    <?php if (!$porRecibir) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay piezas pendientes de recibir.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Piezas pendientes de recibir</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Artículo</th>
                <th scope="col" class="tabla--numerica">Faltan</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($porRecibir as $f) : ?>
                <tr>
                    <td><strong><?= escaparValores($f['folio']) ?></strong></td>
                    <td><?= escaparValores($f['proveedor_nombre']) ?></td>
                    <td><?= escaparValores($f['nombre_mostrado']) ?>
                        <?php if (!$f['producto_id'] && !$f['material_id']) : ?><span class="badge badge--neutro badge--sin-punto">Sin relacionar</span><?php endif; ?></td>
                    <td class="tabla--numerica cifra"><?= $f['pendiente'] ?> de <?= $f['solicitada'] ?></td>
                    <td class="tabla--numerica"><a class="btn btn--primario btn--sm" href="/admin/compras/recibir?id=<?= (int) $f['orden_id'] ?>">Recibir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<div class="rejilla-2">
    <section class="tarjeta tarjeta--sin-relleno">
        <?php $bloque('por-facturar', '3', 'Facturas por adjuntar', 'Compras que aún no tienen su factura (CFDI). Adjúntala desde la compra.', '/admin/compras/facturas?cfdi=pendiente_cfdi', 'Ver todas'); ?>
        <?php if (!$sinCfdi) : ?>
            <p class="texto-secundario" style="padding:1rem">Todas las compras tienen su documentación al día.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Compras pendientes de factura</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col" class="tabla--numerica">Total</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($sinCfdi as $o) : ?>
                    <tr>
                        <td><strong><?= escaparValores($o['folio']) ?></strong></td>
                        <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                        <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#factura">Adjuntar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="tarjeta tarjeta--sin-relleno">
        <?php $bloque('por-pagar', '4', 'Pagos por realizar', 'Compras con saldo: lo que todavía se le debe al proveedor.', '/admin/compras/pagos', 'Ver pagos'); ?>
        <?php if (!$conSaldo) : ?>
            <p class="texto-secundario" style="padding:1rem">No hay saldos pendientes con proveedores.</p>
        <?php else : ?>
            <div class="tabla-contenedor"><table class="tabla">
                <caption class="solo-lectores">Compras con saldo</caption>
                <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col" class="tabla--numerica">Saldo</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($conSaldo as $o) : ?>
                    <tr>
                        <td><strong><?= escaparValores($o['folio']) ?></strong></td>
                        <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($o['saldo']) ?></td>
                        <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#pagos">Pagar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>

<?php template('footer'); ?>
