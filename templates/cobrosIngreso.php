<?php
/**
 * templates/cobrosIngreso.php — Cobros de una venta (su ingreso): estado, conmutador, historial y pago parcial.
 *
 * Variables: $ingreso  (Contabilidad::buscarIngreso / ingresoDeVenta)
 *            $retorno  (URL interna a la que se regresa tras un POST)
 *            $interna  (bool: se pega dentro de otra tarjeta en lugar de ser su propia tarjeta)
 *
 * Se usa dentro del detalle de la venta (sección única «Facturación y cobro») y, como respaldo,
 * en el detalle del ingreso de los registros que no tienen venta asociada.
 */

$cobros   = \App\Contabilidad::cobrosDeIngreso((int) $ingreso['id']);
$cerrado  = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$editable = $ingreso['estado'] === 'vigente' && !$cerrado;
$etiqueta = \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro']);
$interna  = $interna ?? false;

/* En modo tarjeta suelta el bloque lleva su propio relleno; dentro de otra tarjeta no. */
$relleno   = $interna ? '' : ' style="padding:1rem"';
$tituloTag = $interna ? 'h3' : 'h2';
$tituloAtr = $interna ? 'class="titulo-5 mb-md"' : 'class="titulo-5" style="padding:1rem 1rem 0"';
$cajaAtr   = $interna ? '' : ' class="tarjeta tarjeta--sin-relleno mb-lg"';
?>
<?php if ($interna) : ?><div id="cobros"><?php else : ?><section id="cobros"<?= $cajaAtr ?>><?php endif; ?>

    <<?= $tituloTag ?> <?= $tituloAtr ?>>Pago
        <span class="badge <?= $etiqueta['clase'] ?>"><?= escaparValores($etiqueta['etiqueta']) ?></span>
    </<?= $tituloTag ?>>

    <p class="texto-sm mb-md">
        Cada cobro es un dinero recibido (fecha, importe y referencia). Si cobraste en parcialidades, el SAT pide
        un <strong>complemento de pago</strong> que los respalda:
        <a href="/admin/ventas/complemento?ingreso=<?= (int) $ingreso['id'] ?>">cargar o ver complementos (XML)</a>.
    </p>

    <?php if ($editable) : ?>
        <form action="/admin/contabilidad/ingresos/accion" method="post"<?= $interna ? ' class="mb-md"' : ' style="padding:1rem"' ?>>
            <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
            <input type="hidden" name="accion" value="cobro_alterna">
            <input type="hidden" name="volver" value="<?= escaparValores($retorno) ?>">
            <?php if ($ingreso['estado_cobro'] === 'cobrado') : ?>
                <button class="btn btn--secundario" type="submit">Marcar como sin pagar</button>
            <?php elseif ((float) $ingreso['saldo'] > 0) : ?>
                <button class="btn btn--primario" type="submit">Ya me pagaron todo (<?= formatoMoneda($ingreso['saldo']) ?>)</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

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
                            <td><?= $c['estado'] === 'cancelado' ? '<span class="badge badge--error">Cancelado</span> ' . escaparValores((string) $c['motivo_cancelacion']) : '<span class="badge badge--aprobado">Vigente</span>' ?><?php if (!empty($c['complemento_uuid'])) : ?>
                                    <span class="badge badge--info" title="Complemento <?= escaparValores($c['complemento_uuid']) ?>">Complemento <?= escaparValores(substr((string) $c['complemento_uuid'], 0, 8)) ?>…</span>
                                <?php endif; ?></td>
                            <td class="tabla--numerica">
                                <?php if ($c['estado'] === 'vigente') : ?>
                                    <form action="/admin/contabilidad/ingresos/accion" method="post" data-validar
                                          data-confirmacion-sobre="Cobro"
                                          data-confirmacion="¿Cancelar este cobro?"
                                          data-confirmacion-mensaje="El cobro se conserva como cancelado y el saldo del ingreso vuelve a aumentar."
                                          data-confirmacion-boton="Sí, cancelar">
                                        <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>"><input type="hidden" name="accion" value="cobro_cancelar">
                                        <input type="hidden" name="volver" value="<?= escaparValores($retorno) ?>">
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
        <p class="texto-secundario"<?= $relleno ?>>Aún no hay cobros registrados.</p>
    <?php endif; ?>

    <?php if ($editable && (float) $ingreso['saldo'] > 0) : ?>
        <details<?= $interna ? '' : ' style="padding:0 1rem 1rem"' ?>><summary class="texto-sm">Anotar un pago parcial</summary>
        <form class="formulario mt-sm" action="/admin/contabilidad/ingresos/accion" method="post" novalidate>
            <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>"><input type="hidden" name="accion" value="cobro">
            <input type="hidden" name="volver" value="<?= escaparValores($retorno) ?>">
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
        </form></details>
    <?php endif; ?>

<?php if ($interna) : ?></div><?php else : ?></section><?php endif; ?>
