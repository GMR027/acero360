<?php
/**
 * views/admin/contabilidad/gastos/detalle.php — Un gasto: datos, reparto, documentos y cancelación.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$gasto = \App\Gastos::buscarID($id);

if (!$gasto) {
    paginaNoEncontrada();
}

$cerrado  = \App\Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha']);
$vigente  = $gasto['estado'] === 'vigente';
$revision = \App\Gastos::REVISION[$gasto['revision_fiscal']];
$factura  = \App\Gastos::estadoFactura($gasto);
$periodo  = ['anio' => (int) substr($gasto['fecha'], 0, 4), 'mes' => (int) substr($gasto['fecha'], 5, 2), 'empresa' => 0];

template('header', ['titulo' => 'Gasto: ' . $gasto['beneficiario'], 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'gastos', 'periodo' => $periodo]);
?>

<section class="tarjeta mb-lg">
    <p>
        <?= $vigente ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--error">Cancelado</span>' ?>
        <span class="badge <?= $revision['clase'] ?>">Revisión fiscal: <?= escaparValores($revision['etiqueta']) ?></span>
        <?= $gasto['estado_pago'] === 'pagado' ? '<span class="badge badge--aprobado">Pagado</span>' : '<span class="badge badge--pendiente">Pago pendiente</span>' ?>
        <?= $cerrado ? '<span class="badge badge--neutro">Periodo cerrado</span>' : '' ?>
    </p>
    <p class="mt-md"><strong><?= escaparValores($gasto['concepto']) ?></strong> · <?= date('d/m/Y', strtotime($gasto['fecha'])) ?>
        · <?= escaparValores(\App\Gastos::NATURALEZAS[$gasto['naturaleza']] ?? '') ?>
        <?= $gasto['categoria_nombre'] ? ' · ' . escaparValores($gasto['categoria_nombre']) : '' ?></p>
    <dl class="cotizador-detalle__cliente mt-md">
        <div><dt>Subtotal</dt><dd><?= formatoMoneda($gasto['subtotal']) ?></dd></div>
        <div><dt>IVA (<?= rtrim(rtrim($gasto['iva_tasa'], '0'), '.') ?> %)</dt><dd><?= formatoMoneda($gasto['iva']) ?></dd></div>
        <div><dt>Total</dt><dd><strong><?= formatoMoneda($gasto['total']) ?></strong></dd></div>
        <div><dt>Negocio</dt><dd><?= (int) $gasto['compartido'] === 1 ? 'Compartido' : escaparValores((string) ($gasto['empresa_nombre'] ?? 'General')) ?></dd></div>
        <div><dt>UUID</dt><dd><?= escaparValores((string) $gasto['uuid_fiscal']) ?: '—' ?></dd></div>
        <div><dt>Uso del CFDI</dt><dd><?= $gasto['uso_cfdi'] ? escaparValores(\App\Gastos::USOS_CFDI[$gasto['uso_cfdi']] ?? $gasto['uso_cfdi']) : '—' ?></dd></div>
        <div><dt>Método de pago</dt><dd><?= $gasto['metodo_pago_sat'] ? escaparValores(\App\Gastos::METODOS_CFDI[$gasto['metodo_pago_sat']] ?? $gasto['metodo_pago_sat']) : '—' ?></dd></div>
        <div><dt>Pagado a</dt><dd><?= escaparValores(\App\Gastos::BENEFICIARIOS[$gasto['beneficiario_tipo']] ?? '') ?>: <?= escaparValores($gasto['beneficiario']) ?></dd></div>
        <div><dt>Factura (CFDI)</dt><dd><span class="badge <?= $factura['clase'] ?>"><?= escaparValores($factura['etiqueta']) ?></span><br><small class="texto-secundario"><?= escaparValores($factura['detalle']) ?></small></dd></div>
    </dl>
    <?php if ($gasto['reparto']) : ?>
        <p class="mt-md texto-secundario">Reparto interno (el total contable no cambia):
            <?php foreach ($gasto['reparto'] as $r) : ?><?= escaparValores($r['nombre']) ?> <?= rtrim(rtrim(number_format($r['porcentaje'], 2), '0'), '.') ?> % · <?php endforeach; ?></p>
    <?php endif; ?>

    <?php /* Este gasto nació de una compra: aquí se ve el dinero realmente pagado al proveedor
             (trazabilidad de pagos_compra), no solo la bandera «pagado» del gasto. */ ?>
    <?php $orden = \App\CompraContable::ordenDeGasto($id);
    if ($orden) :
        $totalC = \App\PagosCompra::totalCompra($orden);
        $pagadoC = \App\PagosCompra::pagado((int) $orden['id']);
        $estadoPagoC = \App\PagosCompra::estado($totalC, $pagadoC);
        $cicloC = \App\PagosCompra::ciclo($orden + ['estado_pago' => $estadoPagoC]);
        $pagosC = \App\PagosCompra::deOrden((int) $orden['id']); ?>
        <div class="nota mt-md" role="note">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">local_shipping</span>
            <span><strong>Compra de origen:</strong>
                <a href="/admin/compras/detalle?id=<?= (int) $orden['id'] ?>"><?= escaparValores($orden['folio']) ?></a>
                · <?= escaparValores($orden['proveedor_nombre']) ?>
                · <span class="badge <?= $cicloC['clase'] ?>"><?= escaparValores($cicloC['etiqueta']) ?></span>
                <br>De <strong class="cifra"><?= formatoMoneda($totalC / 100) ?></strong> se han pagado
                <strong class="cifra"><?= formatoMoneda($pagadoC / 100) ?></strong>
                · saldo <strong class="cifra"><?= formatoMoneda(($totalC - $pagadoC) / 100) ?></strong>
                <?php if ($pagosC) : ?>
                    <br><span class="texto-xs">Pagos registrados:
                        <?php foreach ($pagosC as $pg) : ?>
                            <?php if ((string) $pg['estado'] !== 'vigente') continue; ?>
                            <span class="badge badge--neutro"><?= formatoMoneda((float) $pg['importe']) ?>
                                · <?= date('d/m/Y', strtotime($pg['fecha'])) ?>
                                <?= $pg['metodo'] ? '· ' . escaparValores($pg['metodo']) : '' ?>
                                <?= $pg['referencia'] ? '· ' . escaparValores($pg['referencia']) : '' ?></span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
    <?php if ($gasto['observaciones']) : ?><p class="texto-secundario mt-md"><?= nl2br(escaparValores($gasto['observaciones'])) ?></p><?php endif; ?>
    <?php if (!$vigente) : ?><p class="nota nota--advertencia mt-md"><span>Cancelado: <?= escaparValores((string) $gasto['motivo_cancelacion']) ?></span></p><?php endif; ?>

    <?php if ($vigente && !$cerrado) : ?>
        <div class="encabezado-pagina__acciones mt-lg">
            <a class="btn btn--secundario" href="/admin/contabilidad/gastos/editar?id=<?= $id ?>">Editar</a>
            <form action="/admin/contabilidad/gastos/accion" method="post" data-validar
                  data-confirmacion-sobre="Gasto"
                  data-confirmacion="¿Cancelar este gasto?"
                  data-confirmacion-mensaje="El gasto no se borra: queda como cancelado, con tu motivo, y deja de sumar en los reportes."
                  data-confirmacion-boton="Sí, cancelar">
                <input type="hidden" name="id" value="<?= $id ?>">
                <label class="solo-lectores" for="motivo">Motivo de la cancelación</label>
                <input class="control control--compacto" type="text" id="motivo" name="motivo" placeholder="Motivo de la cancelación" maxlength="200" required minlength="5">
                <button class="btn btn--peligro" type="submit">Cancelar gasto</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php template('documentosOperacion', ['entidad' => 'gasto', 'entidadId' => $id, 'retorno' => '/admin/contabilidad/gastos/detalle?id=' . $id, 'bloqueado' => $cerrado || !$vigente]); ?>

<?php template('footer'); ?>
