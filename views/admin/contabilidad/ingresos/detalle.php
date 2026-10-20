<?php
/**
 * views/admin/contabilidad/ingresos/detalle.php — Un ingreso: facturación
 * explícita, cobros, documentos y enlace a la operación original.
 */

soloAdmin();

$id      = (int) ($_GET['id'] ?? 0);
$ingreso = \App\Contabilidad::buscarIngreso($id);

if (!$ingreso) {
    paginaNoEncontrada();
}

$cobros    = \App\Contabilidad::cobrosDeIngreso($id);
$cerrado   = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$fact      = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']];
$cobro     = \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro']);
$retorno   = '/admin/contabilidad/ingresos/detalle?id=' . $id;
$editable  = $ingreso['estado'] === 'vigente' && !$cerrado;
$periodo   = ['anio' => (int) substr($ingreso['fecha'], 0, 4), 'mes' => (int) substr($ingreso['fecha'], 5, 2), 'empresa' => 0];

template('header', ['titulo' => 'Ingreso ' . $ingreso['folio'], 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'ingresos', 'periodo' => $periodo]);
?>

<section class="tarjeta mb-lg">
    <p>
        <span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
        <span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span>
        <?= $ingreso['estado'] === 'cancelado' ? '<span class="badge badge--error">Cancelado</span>' : '' ?>
        <?= $cerrado ? '<span class="badge badge--neutro">Periodo cerrado</span>' : '' ?>
    </p>
    <p class="mt-md"><?= escaparValores($ingreso['cliente_empresa'] ?: $ingreso['cliente_nombre']) ?>
        · <?= escaparValores($ingreso['empresa_nombre']) ?> · <?= date('d/m/Y', strtotime($ingreso['fecha'])) ?></p>
    <dl class="cotizador-detalle__cliente mt-md">
        <div><dt>Subtotal</dt><dd><?= formatoMoneda($ingreso['subtotal']) ?></dd></div>
        <div><dt>Descuentos</dt><dd><?= formatoMoneda($ingreso['descuento']) ?></dd></div>
        <div><dt>IVA</dt><dd><?= formatoMoneda($ingreso['iva']) ?></dd></div>
        <div><dt>Total</dt><dd><strong><?= formatoMoneda($ingreso['total']) ?></strong></dd></div>
        <div><dt>Cobrado</dt><dd><?= formatoMoneda($ingreso['cobrado']) ?></dd></div>
        <div><dt>Saldo pendiente</dt><dd><?= formatoMoneda($ingreso['saldo']) ?></dd></div>
    </dl>
    <?php if ($ingreso['venta_id']) : ?>
        <p class="mt-md"><a class="btn btn--secundario btn--sm" href="/admin/ventas/detalle?id=<?= (int) $ingreso['venta_id'] ?>">Abrir la venta original</a>
            <?php if ($ingreso['cotizacion_id']) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/cotizaciones/detalle?id=<?= (int) $ingreso['cotizacion_id'] ?>">Ver su cotización</a>
            <?php endif; ?></p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">¿Se facturó esta venta?</h2>
    <p class="campo__ayuda mb-md">Es un dato explícito: una venta sin factura adjunta no se considera «no facturada» hasta que tú lo indiques.</p>
    <form class="formulario" action="/admin/contabilidad/ingresos/accion" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="facturacion">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" data-ayuda="Facturada si ya existe la factura; No facturada si no se emitió; Pendiente si aún falta confirmarlo." for="estado_facturacion">¿Se le dio factura al cliente?</label>
                <select class="control" id="estado_facturacion" name="estado_facturacion" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach (\App\Contabilidad::FACTURACION as $clave => $d) : ?>
                        <option value="<?= $clave ?>"<?= $ingreso['estado_facturacion'] === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="factura_folio">Folio de la factura (si hay)</label>
                <input class="control" type="text" id="factura_folio" name="factura_folio" maxlength="40" value="<?= escaparValores((string) $ingreso['factura_folio']) ?>" <?= $editable ? '' : 'disabled' ?>>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="uuid_fiscal">Folio fiscal UUID (si hay)</label>
                <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= escaparValores((string) $ingreso['uuid_fiscal']) ?>" <?= $editable ? '' : 'disabled' ?>>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="fecha_facturacion">Fecha de la factura (si hay)</label>
                <input class="control" type="date" id="fecha_facturacion" name="fecha_facturacion" value="<?= escaparValores((string) $ingreso['fecha_facturacion']) ?>" <?= $editable ? '' : 'disabled' ?>>
            </div>
        </div>
        <?php if ($editable) : ?>
            <?php if (!\App\Contabilidad::cfdiDeIngreso($id)) : ?>
                <label class="comprobacion mt-md" for="confirma_manual"><input type="checkbox" id="confirma_manual" name="confirma_manual" value="1">
                    Sin CFDI adjunto: confirmo manualmente que ya se facturó (queda registrado)</label>
            <?php endif; ?>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar</button></div>
        <?php endif; ?>
    </form>
</section>

<section id="cobros" class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pagos recibidos del cliente</h2>
    <?php if ($cobros) : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Cobros registrados</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col">Método</th>
                    <th scope="col">Referencia</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($cobros as $c) : ?>
                        <tr<?= $c['estado'] === 'cancelado' ? ' style="opacity:.6"' : '' ?>>
                            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($c['importe']) ?></td>
                            <td><?= escaparValores((string) $c['metodo']) ?></td>
                            <td><?= escaparValores((string) $c['referencia']) ?></td>
                            <td><?= $c['estado'] === 'cancelado' ? '<span class="badge badge--error">Cancelado</span> ' . escaparValores((string) $c['motivo_cancelacion']) : '<span class="badge badge--aprobado">Vigente</span>' ?></td>
                            <td class="tabla--numerica">
                                <?php if ($c['estado'] === 'vigente') : ?>
                                    <form action="/admin/contabilidad/ingresos/accion" method="post" data-validar
                                          data-confirmacion-sobre="Cobro"
                                          data-confirmacion="¿Cancelar este cobro?"
                                          data-confirmacion-mensaje="El cobro se conserva como cancelado y el saldo del ingreso vuelve a aumentar."
                                          data-confirmacion-boton="Sí, cancelar">
                                        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cobro_cancelar">
                                        <input type="hidden" name="cobro_id" value="<?= (int) $c['id'] ?>">
                                        <label class="solo-lectores" for="mc-<?= (int) $c['id'] ?>">Motivo</label>
                                        <input class="control control--compacto" type="text" id="mc-<?= (int) $c['id'] ?>" name="motivo" placeholder="Motivo" maxlength="200" required minlength="5">
                                        <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay cobros registrados.</p>
    <?php endif; ?>

    <?php if ($editable && (float) $ingreso['saldo'] > 0) : ?>
        <form class="formulario" style="padding:1rem" action="/admin/contabilidad/ingresos/accion" method="post" novalidate>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cobro">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <div class="formulario__rejilla">
                <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="El día en que recibiste el dinero." for="cobro-fecha">¿Cuándo te pagaron?</label>
                    <input class="control" type="date" id="cobro-fecha" name="fecha" required value="<?= date('Y-m-d') ?>"></div>
                <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Lo que el cliente te pagó en esta ocasión. Puedes anotar varios pagos." for="cobro-importe">¿Cuánto te pagaron?</label>
                    <input class="control control--moneda" type="number" id="cobro-importe" name="importe" required min="0.01" step="0.01" inputmode="decimal"
                           max="<?= number_format((float) $ingreso['saldo'], 2, '.', '') ?>" value="<?= number_format((float) $ingreso['saldo'], 2, '.', '') ?>">
                    <span class="campo__ayuda">Saldo pendiente: <?= formatoMoneda($ingreso['saldo']) ?></span></div>
                <div class="campo"><label class="campo__etiqueta" data-ayuda="Efectivo, transferencia, etc." for="cobro-metodo">¿Cómo te pagaron?</label>
                    <select class="control" id="cobro-metodo" name="metodo">
                        <?php $sugerido = \App\Contabilidad::metodoSugerido($ingreso); ?>
                        <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?><option<?= $m === $sugerido ? ' selected' : '' ?>><?= escaparValores($m) ?></option><?php endforeach; ?>
                    </select>
                    <span class="campo__ayuda">Sugerida: <?= escaparValores($sugerido) ?> (<?= \App\Contabilidad::llevaIva($ingreso) ? 'con' : 'sin' ?> IVA). Cámbiala si te pagaron de otra forma.</span></div>
                <div class="campo"><label class="campo__etiqueta" for="cobro-ref">Referencia (opcional)</label>
                    <input class="control" type="text" id="cobro-ref" name="referencia" maxlength="80"></div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Anotar pago recibido</button></div>
        </form>
    <?php endif; ?>
</section>

<?php template('documentosOperacion', ['entidad' => 'ingreso', 'entidadId' => $id, 'retorno' => $retorno, 'bloqueado' => $cerrado || $ingreso['estado'] === 'cancelado']); ?>

<?php template('footer'); ?>
