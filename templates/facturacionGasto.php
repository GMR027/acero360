<?php
/**
 * templates/facturacionGasto.php — Facturación y pago de un gasto.
 *
 * Reúne en un solo lugar el ciclo completo:
 *   Factura (CFDI recibido, PUE/PPD/por definir)
 *   → Pago (pendiente, parcial o pagado, con sus abonos)
 *   → Complemento de pago (recibido, pendiente o no aplica).
 *
 * No escribe nada: los formularios envían a gastos/accion, documentos/subir o
 * a la bandeja de Complementos de pago.
 *
 * Variables: $gasto (array de Gastos::buscarID), $orden (array|null de la compra
 * de origen), $cerrado (bool), $factura (array de Gastos::estadoFactura, opcional).
 */
$gasto = $gasto ?? [];
$orden = $orden ?? null;
$cerrado = (bool) ($cerrado ?? false);
$id = (int) ($gasto['id'] ?? 0);
$conFactura = (int) ($gasto['comprobante_fiscal'] ?? 0) === 1;
$metodo = strtoupper(trim((string) ($gasto['metodo_pago_sat'] ?? '')));
$factura = $factura ?? \App\Gastos::estadoFactura($gasto);
$estadoPago = \App\Gastos::estadoPagoGasto($gasto);
$pagos = \App\PagosGasto::deGasto($id);
$complementos = \App\ComplementosPago::deGasto($id);
$docs = \App\Archivos::deOperacion('gasto', $id, false);
$complementoAdjunto = array_values(array_filter($docs, static fn ($d) => ($d['tipo'] ?? '') === 'complemento_pago' && (int) ($d['activo'] ?? 0) === 1));
$complementoRecibido = $complementos !== [] || $complementoAdjunto !== [];
$requiereComplemento = $conFactura && $metodo !== 'PUE';
$esDeCompra = $orden !== null;
$puedeAbonar = (string) ($gasto['estado'] ?? '') === 'vigente' && !$esDeCompra && !$cerrado && $estadoPago['clave'] !== 'pagado' && $estadoPago['saldo'] > 0;
$total = (float) ($gasto['total'] ?? 0);
$pagado = (float) ($estadoPago['pagado'] / 100);
$saldo = (float) ($estadoPago['saldo'] / 100);
$pct = $total > 0 ? min(100, max(0, (int) round($pagado * 100 / $total))) : 0;
$metodoEtiqueta = $metodo === 'PPD' ? 'PPD · Pago en parcialidades'
    : ($metodo === 'PUE' ? 'PUE · Pago en una sola exhibición'
    : ($metodo === 'POR' ? 'Método por definir' : 'Método sin definir'));
?>
<section class="tarjeta mb-lg" id="facturacion-pago">
    <div class="flex-entre mb-md">
        <h2 class="titulo-5">Facturación y pago
            <span class="badge <?= escaparValores($factura['clase']) ?>"><?= escaparValores($factura['etiqueta']) ?></span>
            <span class="badge <?= escaparValores($estadoPago['clase']) ?>"><?= escaparValores($estadoPago['etiqueta']) ?></span>
            <?php if ($conFactura) : ?>
                <span class="badge <?= $metodo === 'PUE' ? 'badge--aprobado' : ($metodo === 'PPD' ? 'badge--info' : 'badge--neutro') ?>"><?= escaparValores($metodoEtiqueta) ?></span>
                <?php if ($requiereComplemento) : ?>
                    <span class="badge <?= $complementoRecibido ? 'badge--aprobado' : 'badge--pendiente' ?>">
                        <?= $complementoRecibido ? 'Complemento recibido' : 'Falta complemento' ?></span>
                <?php else : ?>
                    <span class="badge badge--neutro">Sin complemento (PUE)</span>
                <?php endif; ?>
            <?php endif; ?>
        </h2>
        <span class="texto-xs texto-secundario"><?= escaparValores($factura['detalle']) ?></span>
    </div>

    <div class="barra-cobro mb-md" role="group" aria-label="Avance del pago">
        <progress value="<?= $total > 0 ? $pagado : 0 ?>" max="<?= $total > 0 ? $total : 1 ?>"></progress>
        <span class="texto-xs texto-secundario">
            <?= $pct ?> % pagado · <?= count(array_filter($pagos, static fn ($p) => (string) $p['estado'] === 'vigente')) ?> abono(s)
            · saldo <?= formatoMoneda($saldo) ?>
        </span>
    </div>

    <dl class="cotizador-detalle__cliente">
        <div><dt>Total de la factura</dt><dd class="cifra"><strong><?= formatoMoneda($total) ?></strong></dd></div>
        <div><dt>Abonado</dt><dd class="cifra"><?= formatoMoneda($pagado) ?></dd></div>
        <div><dt>Saldo</dt><dd class="cifra"><?= formatoMoneda($saldo) ?></dd></div>
        <div><dt>Método de pago del CFDI</dt><dd><?= escaparValores($metodoEtiqueta) ?></dd></div>
        <div><dt>Forma de pago</dt><dd><?php $formaG = \App\VentasFiscal::normalizarFormaPago((string) ($gasto['forma_pago_sat'] ?? '')); ?><?= $formaG !== '' ? escaparValores($formaG . ' · ' . (\App\VentasFiscal::FORMAS_PAGO[$formaG] ?? '')) : '—' ?></dd></div>
        <div><dt>Uso del CFDI</dt><dd><?= !empty($gasto['uso_cfdi']) ? escaparValores(\App\Gastos::USOS_CFDI[$gasto['uso_cfdi']] ?? $gasto['uso_cfdi']) : '—' ?></dd></div>
        <div><dt>Tipo de comprobante</dt><dd><?= !empty($gasto['tipo_cfdi']) ? escaparValores(\App\Contabilidad::TIPOS_CFDI[$gasto['tipo_cfdi']] ?? $gasto['tipo_cfdi']) : '—' ?></dd></div>
        <div><dt>UUID de la factura</dt><dd><?= escaparValores((string) ($gasto['uuid_fiscal'] ?? '')) ?: '—' ?></dd></div>
    </dl>

    <?php if ($esDeCompra) : ?>
        <p class="nota nota--info mt-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">local_shipping</span>
            <span>Este gasto nació de la compra
                <a href="/admin/compras/detalle?id=<?= (int) $orden['id'] ?>#pagos"><?= escaparValores($orden['folio']) ?></a>:
                sus pagos y su complemento se gestionan desde la compra.</span>
        </p>
    <?php else : ?>
        <?php if ($conFactura && ($metodo === 'POR' || $metodo === '')) : ?>
            <div class="nota nota--advertencia mt-md" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">help</span>
                <span><strong>Define el método de pago del CFDI:</strong> si la factura se paga de una vez es PUE;
                    si es en parcialidades, PPD. De eso depende que necesites complemento de pago.</span>
                <?php if (!$cerrado) : ?>
                    <form class="formulario mt-sm" action="/admin/contabilidad/gastos/accion" method="post">
                        <input type="hidden" name="accion" value="metodo_cfdi">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                        <div class="formulario__acciones">
                            <button class="btn btn--secundario btn--sm" type="submit" name="metodo" value="PUE">Es PUE (un solo pago)</button>
                            <button class="btn btn--secundario btn--sm" type="submit" name="metodo" value="PPD">Es PPD (parcialidades)</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($metodo === 'PUE' && $estadoPago['clave'] === 'parcial') : ?>
            <p class="nota nota--advertencia mt-md" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
                <span>La factura está marcada como PUE pero tiene abonos parciales. Revisa el método del CFDI o termina de pagar el saldo.</span>
            </p>
        <?php elseif ($requiereComplemento && !$complementoRecibido && $estadoPago['pagado'] > 0) : ?>
            <p class="nota nota--advertencia mt-md" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
                <span>La factura es <strong><?= $metodo === 'POR' || $metodo === '' ? 'de método por definir' : 'PPD' ?></strong> y ya tiene
                    <?= $estadoPago['clave'] === 'pagado' ? 'el pago completo' : 'abonos' ?>: falta el <strong>complemento de pago</strong>.
                    Carga el XML que emitió el proveedor para vincularlo con el abono.</span>
            </p>
        <?php elseif ($requiereComplemento && !$complementoRecibido && !$esDeCompra) : ?>
            <p class="nota nota--info mt-md" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">schedule</span>
                <span>La factura es <strong><?= $metodo === 'POR' || $metodo === '' ? 'de método por definir' : 'PPD' ?></strong>.
                    Cuando pagues, el proveedor debe emitir el complemento de pago. Puedes registrarlo aquí en cuanto llegue.</span>
            </p>
        <?php endif; ?>

        <div class="encabezado-pagina__acciones mt-md">
            <?php if ($puedeAbonar) : ?>
                <a class="btn btn--primario" href="/admin/contabilidad/gastos/pagar?id=<?= $id ?>"
                   data-modal-form="tpl-pagar-gasto"
                   data-modal-sobre-titulo="Gasto: <?= escaparValores($gasto['beneficiario']) ?>"
                   data-modal-titulo="Registrar abono"
                   data-modal-confirmar="Guardar abono">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">payments</span>
                    <span>Registrar abono</span>
                </a>
            <?php endif; ?>
            <?php if ($requiereComplemento && (string) $gasto['estado'] === 'vigente') : ?>
                <a class="btn btn--secundario" href="/admin/compras/complemento?gasto=<?= $id ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">receipt_long</span>
                    <span><?= $complementoRecibido ? 'Cargar otro complemento' : 'Leer complemento (XML)' ?></span>
                </a>
            <?php endif; ?>
            <a class="btn btn--secundario" href="#documentos">Ver factura y documentos</a>
            <?php if (!$conFactura && (string) $gasto['estado'] === 'vigente' && !$cerrado) : ?>
                <a class="btn btn--secundario" href="/admin/contabilidad/gastos/editar?id=<?= $id ?>">Marcar con factura</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($pagos) : ?>
        <h3 class="titulo-6 mt-lg mb-sm">Abonos registrados</h3>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Abonos del gasto</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Importe</th>
                    <th scope="col">Método</th><th scope="col">Referencia</th><th scope="col">Estado</th>
                    <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($pagos as $pg) : $vigente = (string) $pg['estado'] === 'vigente'; ?>
                    <tr<?= $vigente ? '' : ' style="opacity:.6"' ?>>
                        <td><?= date('d/m/Y', strtotime($pg['fecha'])) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda((float) $pg['importe']) ?></td>
                        <td><?= escaparValores((string) ($pg['metodo'] ?? '')) ?></td>
                        <td><?= escaparValores((string) ($pg['referencia'] ?? '')) ?></td>
                        <td><?= $vigente ? '<span class="badge badge--aprobado">Vigente</span>'
                            : '<span class="badge badge--neutro">Cancelado</span><span class="texto-xs texto-secundario"><br>'
                                . escaparValores((string) $pg['motivo_cancelacion']) . '</span>' ?></td>
                        <td class="tabla--numerica">
                            <?php if ($vigente && !$cerrado && !$esDeCompra) : ?>
                                <details>
                                    <summary class="btn btn--texto btn--sm">Cancelar abono</summary>
                                    <form class="formulario mt-sm" action="/admin/contabilidad/gastos/accion" method="post" data-validar
                                          data-confirmacion-sobre="Abono de <?= escaparValores(formatoMoneda((float) $pg['importe'])) ?>"
                                          data-confirmacion="¿Cancelar este abono?"
                                          data-confirmacion-mensaje="El saldo del gasto vuelve a subir. El abono no se borra: queda cancelado con su motivo."
                                          data-confirmacion-boton="Sí, cancelar abono">
                                        <input type="hidden" name="accion" value="cancelar_pago">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="pago" value="<?= (int) $pg['id'] ?>">
                                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo-abono-<?= (int) $pg['id'] ?>">Motivo</label>
                                        <input class="control" type="text" id="motivo-abono-<?= (int) $pg['id'] ?>" name="motivo" maxlength="255" required minlength="5">
                                        <button class="btn btn--peligro btn--sm" type="submit">Cancelar abono</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($complementos) : ?>
        <h3 class="titulo-6 mt-lg mb-sm">Complementos de pago vinculados</h3>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Complementos de pago del gasto</caption>
                <thead><tr><th scope="col">UUID</th><th scope="col">Fecha de pago</th>
                    <th scope="col" class="tabla--numerica">Monto</th><th scope="col">Parcialidad</th>
                    <th scope="col">Estado</th></tr></thead>
                <tbody>
                <?php foreach ($complementos as $c) : ?>
                    <tr>
                        <td><strong><?= escaparValores(substr((string) $c['uuid'], 0, 8)) ?>…</strong>
                            <span class="texto-xs texto-secundario"><br><?= escaparValores((string) $c['uuid']) ?></span></td>
                        <td><?= date('d/m/Y', strtotime((string) $c['fecha_pago'])) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda((float) $c['importe_pagado']) ?></td>
                        <td><?= escaparValores((string) ($c['num_parcialidad'] ?? '')) ?: '—' ?></td>
                        <td><span class="badge <?= (string) $c['docto_estado'] === 'vinculado' ? 'badge--aprobado' : 'badge--pendiente' ?>">
                            <?= (string) $c['docto_estado'] === 'vinculado' ? 'Vinculado' : escaparValores((string) $c['docto_estado']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!$pagos && !$complementos && $conFactura) : ?>
        <p class="texto-sm texto-secundario mt-md">Aún no hay abonos ni complementos registrados para esta factura.</p>
    <?php endif; ?>
</section>
