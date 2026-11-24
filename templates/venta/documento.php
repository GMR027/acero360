<?php
/**
 * templates/venta/documento.php — Documento de la venta dentro del expediente.
 *
 * Es la misma información que antes vivía en /admin/ventas/detalle: emisor,
 * cliente, renglones, términos comerciales, resumen de liquidación y la baja.
 * Se separó a una plantilla para que el expediente la muestre como una más de
 * sus secciones, sin duplicar datos.
 *
 * Variables: $cotizacion, $detalles, $descuentoRenglones, $descuentoGlobal,
 *            $pctGlobal, $logo, $id.
 */
?>
<section class="mb-lg" id="resumen" style="scroll-margin-top:5rem">
    <div class="cotizador-detalle expediente-resumen-grid">
        <!-- Documento -->
        <section class="tarjeta expediente-resumen-doc">
            <div class="cotizador-detalle__emisor">
                <?php if ($logo !== '') : ?>
                    <img class="cotizador-detalle__logo" src="<?= escaparValores($logo) ?>"
                         alt="<?= escaparValores($cotizacion->empresa_nombre) ?>">
                <?php endif; ?>

                <div>
                    <h2 class="titulo-3"><?= escaparValores($cotizacion->empresa_nombre) ?></h2>
                    <p class="texto-secundario">
                        <?php
                        $domicilio = trim((string) $cotizacion->empresa_direccion);
                        if (trim((string) $cotizacion->empresa_ciudad) !== '') {
                            $domicilio .= ($domicilio !== '' ? ', ' : '')
                                . $cotizacion->empresa_ciudad;
                        }
                        if (trim((string) $cotizacion->empresa_estado) !== '') {
                            $domicilio .= ($domicilio !== '' ? ', ' : '') . $cotizacion->empresa_estado;
                        }
                        if (trim((string) $cotizacion->empresa_cp) !== '') {
                            $domicilio .= ($domicilio !== '' ? ' C.P. ' : 'C.P. ') . $cotizacion->empresa_cp;
                        }
                        ?>
                        <?php if ($domicilio !== '') : ?>
                            <?= escaparValores($domicilio) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) $cotizacion->empresa_telefono) !== '') : ?>
                            Tel. <?= escaparValores($cotizacion->empresa_telefono) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) $cotizacion->empresa_email) !== '') : ?>
                            <?= escaparValores($cotizacion->empresa_email) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <dl class="cotizador-detalle__cliente">
                <div>
                    <dt>Cliente</dt>
                    <dd>
                        <strong><?= escaparValores($cotizacion->cliente_nombre) ?></strong><br>
                        <?php if (trim((string) $cotizacion->cliente_empresa) !== '') : ?>
                            <?= escaparValores($cotizacion->cliente_empresa) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) $cotizacion->cliente_email) !== '') : ?>
                            <?= escaparValores($cotizacion->cliente_email) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) $cotizacion->cliente_telefono) !== '') : ?>
                            Tel. <?= escaparValores($cotizacion->cliente_telefono) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) $cotizacion->cliente_ubicacion) !== '') : ?>
                            <?= escaparValores($cotizacion->cliente_ubicacion) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>
                        Folio <strong><?= escaparValores($cotizacion->folio) ?></strong><br>
                        Fecha <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?>
                    </dd>
                </div>
            </dl>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <caption class="solo-lectores">Renglones de la venta</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Producto</th>
                            <th scope="col">Unidad</th>
                            <th scope="col" class="tabla--numerica">Precio unitario</th>
                            <th scope="col" class="tabla--numerica">Cantidad</th>
                            <th scope="col" class="tabla--numerica">Desc. (%)</th>
                            <th scope="col" class="tabla--numerica">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalles as $indice => $detalle) :
                            $especial = (float) $detalle['precio_unitario'] !== (float) $detalle['producto_precio'];
                            ?>
                            <tr>
                                <td class="tabla--numerica cifra"><?= $indice + 1 ?></td>
                                <td>
                                    <div class="tabla__producto">
                                        <?php $rutaImg = trim((string) $detalle['producto_imagen']); ?>
                                        <?php if ($rutaImg !== '') : ?>
                                            <img class="tabla__miniatura" src="<?= escaparValores(\App\Imagenes::urlMiniatura($rutaImg)) ?>"
                                                 alt="" width="300" height="300" loading="lazy">
                                        <?php endif; ?>
                                        <div class="tabla__producto-textos">
                                            <span><?= escaparValores($detalle['producto_nombre']) ?></span>
                                            <span class="texto-secundario">
                                                <?= escaparValores($detalle['producto_sku']) ?>
                                                <?php if ($especial) : ?>
                                                    <span class="badge badge--vip">Precio especial</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td><?= escaparValores($detalle['producto_unidad']) ?></td>
                                <td class="tabla--numerica cifra"><?= formatoMoneda($detalle['precio_unitario']) ?></td>
                                <td class="tabla--numerica cifra"><?= number_format((int) $detalle['cantidad']) ?></td>
                                <td class="tabla--numerica cifra"><?= (float) $detalle['descuento_pct'] > 0 ? number_format((float) $detalle['descuento_pct'], 2) . '%' : '—' ?></td>
                                <td class="tabla--numerica cifra"><strong><?= formatoMoneda($detalle['subtotal']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Condiciones comerciales -->
        <section class="tarjeta mb-lg">
            <h2 class="titulo-3 mb-md">
                <span class="material-symbols-outlined" aria-hidden="true">handshake</span>
                Términos comerciales y logística
            </h2>

            <?php if (trim((string) $cotizacion->condiciones_envio) !== '') : ?>
                <div class="cotizador-detalle__condicion">
                    <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                    <div>
                        <strong>Entrega</strong>
                        <p><?= nl2br(escaparValores($cotizacion->condiciones_envio)) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (trim((string) $cotizacion->condiciones_compra) !== '') : ?>
                <div class="cotizador-detalle__condicion">
                    <span class="material-symbols-outlined" aria-hidden="true">account_balance</span>
                    <div>
                        <strong>Pago</strong>
                        <p><?= nl2br(escaparValores($cotizacion->condiciones_compra)) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (trim((string) $cotizacion->comentarios) !== '') : ?>
                <div class="cotizador-detalle__condicion">
                    <span class="material-symbols-outlined" aria-hidden="true">sticky_note_2</span>
                    <div>
                        <strong>Comentarios adicionales</strong>
                        <p><?= nl2br(escaparValores($cotizacion->comentarios)) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (trim((string) $cotizacion->condiciones_envio) === ''
                && trim((string) $cotizacion->condiciones_compra) === ''
                && trim((string) $cotizacion->comentarios) === '') : ?>
                <p class="texto-secundario">Esta venta no tiene condiciones comerciales registradas.</p>
            <?php endif; ?>
        </section>

    <!-- Resumen -->
    <section class="tarjeta">
        <div class="cotizador-resumen cotizador-resumen--plano">
            <header class="cotizador-resumen__cabecera">
                <h2 class="cotizador-resumen__titulo">
                    <span class="material-symbols-outlined" aria-hidden="true">calculate</span>
                    Resumen de liquidación
                </h2>
                <span class="badge badge--neutro">Moneda: MXN</span>
            </header>

            <dl class="cotizador-resumen__cuerpo">
                <div class="cotizador-resumen__fila">
                    <dt>Subtotal bruto de partidas</dt>
                    <dd><?= formatoMoneda($cotizacion->subtotal) ?></dd>
                </div>
                <?php if ($descuentoRenglones > 0) : ?>
                    <div class="cotizador-resumen__fila">
                        <dt>Descuento por renglón</dt>
                        <dd class="cotizador-resumen__negativo">-<?= formatoMoneda($descuentoRenglones) ?></dd>
                    </div>
                <?php endif; ?>
                <div class="cotizador-resumen__fila">
                    <dt>
                        Descuento global
                        <span class="cotizador-resumen__ayuda"><?= $pctGlobal > 0 ? number_format($pctGlobal, 2) . '%' : 'Sin descuento' ?></span>
                    </dt>
                    <dd class="cotizador-resumen__negativo">-<?= formatoMoneda($descuentoGlobal) ?></dd>
                </div>
                <div class="cotizador-resumen__fila">
                    <dt>Base gravable</dt>
                    <dd><?= formatoMoneda((float) $cotizacion->subtotal - (float) $cotizacion->descuento) ?></dd>
                </div>
                <div class="cotizador-resumen__fila">
                    <dt>I.V.A. federal trasladado (16%)<?= (int) $cotizacion->aplica_iva !== 1 ? ' — no aplicado' : '' ?></dt>
                    <dd><?= formatoMoneda($cotizacion->iva) ?></dd>
                </div>
                <div class="cotizador-resumen__fila cotizador-resumen__fila--total">
                    <dt>Total neto autorizado</dt>
                    <dd><strong><?= formatoMoneda($cotizacion->total) ?></strong></dd>
                </div>
            </dl>

            <p class="cotizador-resumen__letra"><?= escaparValores(numeroALetras((float) $cotizacion->total)) ?></p>
        </div>
    </section>
    </div>
</section>
