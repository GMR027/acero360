<?php
/**
 * views/admin/cotizaciones/detalle.php — Vista de una cotización.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Documento de sólo lectura: datos fiscales del negocio, cliente, renglones,
 * condiciones comerciales y resumen. Desde acá se cambia el estado y se
 * exporta el PDF.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$cotizacion = \App\Cotizaciones::buscarID($id);

if (!$cotizacion) {
    // Igual que el resto del panel: un folio inexistente es una 404.
    paginaNoEncontrada();
}

$detalles = \App\Cotizaciones::detalles($id);
$estado   = \App\Cotizaciones::datosEstado($cotizacion->estado);

/** Descuento por renglón y descuento global (con su porcentaje negociado). */
$desglose           = \App\Cotizaciones::desgloseDescuento($cotizacion, $detalles);
$descuentoRenglones = $desglose['renglones'];
$descuentoGlobal    = $desglose['global'];
$pctGlobal          = $desglose['pct'];

/** Fecha límite de vigencia. */
$vence = '';
if ($cotizacion->fecha) {
    $vence = date('d/m/Y', strtotime($cotizacion->fecha . ' + ' . (int) $cotizacion->vigencia_dias . ' days'));
}

$logo = trim((string) $cotizacion->empresa_logo) !== ''
    ? \App\Imagenes::url(\App\Imagenes::logoRecortado($cotizacion->empresa_logo))
    : '';

/** Botones de cambio de estado (el actual queda deshabilitado). */
$estados = \App\Cotizaciones::ESTILOS_ESTADO;

/**
 * Existencias insuficientes: sólo tiene sentido para una cotización que
 * todavía NO está vendida (si ya lo está, el descuento ya ocurrió).
 * La lista se muestra en el modal de confirmación del botón "Vendido":
 * el sistema avisa, pero deja continuar y el inventario queda en negativo.
 */
$faltantes = $cotizacion->estado === 'vendido'
    ? []
    : \App\Inventario::faltantes($id);

$mensajeFaltantes = '';
if (!empty($faltantes)) {
    $partes = [];
    foreach ($faltantes as $faltante) {
        $partes[] = sprintf(
            '«%s» pide %d y hay %d (faltan %d)',
            $faltante['nombre'],
            $faltante['requerido'],
            $faltante['disponible'],
            $faltante['faltante']
        );
    }
    $mensajeFaltantes = 'No alcanzan las existencias: ' . implode('; ', $partes)
        . '. El inventario quedará en negativo hasta que se cargue material.';
}

template('header', [
    'titulo'            => $cotizacion->folio,
    'subtitulo'         => 'Detalle de cotización',
    'rutaActiva'        => '/admin/cotizaciones',
    // La vista lleva su propio encabezado (folio, insignia de estado y
    // fechas); sin esta llave el nombre de la sección repetiría el <h1> y
    // el <title> saldría como "Cotizaciones · Cotizaciones".
    'mostrarEncabezado' => false,
]);
?>

<section class="encabezado-pagina">
    <div>
        <h1 class="titulo-2">
             <?= escaparValores($cotizacion->folio) ?>
            <span class="badge <?= $estado['clase'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $estado['icono'] ?></span>
                <?= $estado['etiqueta'] ?>
            </span>
        </h1>
        <p class="texto-secundario">
            Emitida el <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?>
            · vigencia de <?= (int) $cotizacion->vigencia_dias ?> días
            <?php if ($vence !== '') : ?>
                (vence el <?= $vence ?>)
            <?php endif; ?>
            <?php if (trim((string) $cotizacion->usuario_nombre) !== '') : ?>
                · elaborada por <?= escaparValores($cotizacion->usuario_nombre) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/cotizaciones">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Histórico</span>
        </a>
        <a class="btn btn--secundario" href="/admin/cotizaciones/editar?id=<?= $id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
            <span>Editar</span>
        </a>
        <?php $ventaOrigen = $cotizacion->estado === 'vendido' ? \App\Ventas::buscarPorCotizacion($id) : null; ?>
        <?php if ($ventaOrigen) : ?>
            <a class="btn btn--secundario" href="/admin/ventas/detalle?id=<?= (int) $ventaOrigen->id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>
                <span>Venta <?= escaparValores($ventaOrigen->folio) ?></span>
            </a>
        <?php endif; ?>
        <a class="btn btn--secundario" href="/admin/cotizaciones/pdf?id=<?= $id ?>" target="_blank" rel="noopener">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
            <span>Exportar PDF</span>
        </a>
    </div>
</section>

<!-- Cambio de estado -->
<section class="tarjeta mb-lg">
    <div class="cotizador-estado-acciones">
        <span class="campo__etiqueta">Estado del pedido</span>

        <?php foreach ($estados as $clave => $datos) :
            $activo = $cotizacion->estado === $clave;

            // Marcar como "Vendido" descuenta el almacén: si no alcanza,
            // antes se avisa en el MODAL (nunca con window.confirm).
            $exigirConfirmacion = $clave === 'vendido' && $mensajeFaltantes !== '';
            ?>
            <form action="/admin/cotizaciones/estado" method="post" data-validar
                  <?= $exigirConfirmacion ? 'data-confirmacion-sobre="Inventario insuficiente"'
                      . ' data-confirmacion="¿Marcar como Vendido sin existencias?"'
                      . ' data-confirmacion-mensaje="' . escaparValores($mensajeFaltantes) . '"'
                      . ' data-confirmacion-boton="Sí, descontar stock"' : '' ?>>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="estado" value="<?= $clave ?>">
                <button type="submit" class="btn btn--sm <?= $activo ? 'btn--primario' : 'btn--secundario' ?>"
                        <?= $activo ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= $datos['icono'] ?></span>
                    <span><?= $datos['etiqueta'] ?></span>
                </button>
            </form>
        <?php endforeach; ?>

        <a class="btn btn--peligro btn--sm" href="#eliminar-cotizacion">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
            <span>Eliminar</span>
        </a>
    </div>

    <?php if (!empty($faltantes)) : ?>
        <!-- Aviso visual además del modal: el aviso debe verse siempre. -->
        <p class="nota nota--advertencia mt-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">inventory</span>
            <span>
                <strong>Existencias insuficientes.</strong>
                <?= escaparValores($mensajeFaltantes) ?>
                <a href="/admin/inventario">Revisar inventario</a>
            </span>
        </p>
    <?php endif; ?>
</section>

<div class="cotizador-detalle">
    <!-- Documento -->
    <section class="tarjeta mb-lg">
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
                </dd>
            </div>
            <div>
                <dt>Referencia</dt>
                <dd>
                    Folio <strong><?= escaparValores($cotizacion->folio) ?></strong><br>
                    Fecha <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?><br>
                    Vigencia <?= (int) $cotizacion->vigencia_dias ?> días
                </dd>
            </div>
        </dl>

        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Renglones de la cotización</caption>
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
            <p class="texto-secundario">Esta cotización no tiene condiciones comerciales registradas.</p>
        <?php endif; ?>
    </section>
</div>

<!-- Resumen -->
<section class="tarjeta mb-lg">
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

<!-- Baja (sólo POST, con confirmación modal) -->
<section class="tarjeta" id="eliminar-cotizacion">
    <h2 class="titulo-3 mb-md">
        <span class="material-symbols-outlined" aria-hidden="true">delete_forever</span>
        Eliminar cotización
    </h2>
    <p class="texto-secundario mb-md">
        Los precios especiales guardados con el cliente se conservan: sólo se retira la cotización.
    </p>

    <form action="/admin/cotizaciones/eliminar" method="post" data-validar
          data-confirmacion-sobre="<?= escaparValores($cotizacion->folio) ?>"
          data-confirmacion="¿Eliminar esta cotización?"
          data-confirmacion-mensaje="Se borrará la cotización <?= escaparValores($cotizacion->folio) ?> y todos sus renglones.<?php if ($cotizacion->estado === 'vendido') : ?> Al estar marcada como Vendida, el inventario devolverá las piezas descontadas.<?php endif; ?> Irá a Ajustes → Papelera y ahí podrás restaurarla durante 90 días."
          data-confirmacion-boton="Sí, eliminar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn--peligro">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
            <span>Eliminar cotización</span>
        </button>
    </form>
</section>

<?php template('footer'); ?>
