<?php
/**
 * templates/formPagoGasto.php — Formulario para registrar un abono de un gasto.
 *
 * Compartido por la página suelta /admin/contabilidad/gastos/pagar y por el
 * modal del detalle del gasto (mismo action, campos y token en los dos lados).
 *
 * El importe viene precargado con el saldo: si la persona no lo cambia, se paga
 * todo. Si escribe una cantidad menor, queda registrado un pago parcial y el
 * gasto sigue pendiente hasta cubrir el total.
 *
 * No trae botones de acción por defecto: el modal usa el pie del diálogo y la
 * página suelta los dibuja con $conAcciones = true.
 *
 * Variables: $gasto (array de Gastos::buscarID), $token (string, CSRF),
 *            $volver (string, opcional), $conAcciones (bool, opcional),
 *            $hrefCancelar (string, opcional).
 */
$gasto = $gasto ?? [];
$volver = (string) ($volver ?? '');
$conAcciones = (bool) ($conAcciones ?? false);
$hrefCancelar = (string) ($hrefCancelar ?? '');
$etiqueta = 'pago-gasto-' . (int) ($gasto['id'] ?? 0);

$conFactura = (int) ($gasto['comprobante_fiscal'] ?? 0) === 1;
$metodoActual = (string) ($gasto['metodo_pago'] ?? '');
$metodoCfdiActual = strtoupper(trim((string) ($gasto['metodo_pago_sat'] ?? '')));
$formaActual = \App\VentasFiscal::normalizarFormaPago((string) ($gasto['forma_pago_sat'] ?? ''));
$fechaPago = (string) ($gasto['fecha_pago'] ?? '') ?: date('Y-m-d');
$estadoPago = \App\Gastos::estadoPagoGasto($gasto);
$saldo = (float) ($estadoPago['saldo'] / 100);
$total = (float) ($gasto['total'] ?? 0);
$pagado = (float) ($estadoPago['pagado'] / 100);
$esPpd = $conFactura && in_array($metodoCfdiActual, ['PPD', 'POR'], true);
?>
<form class="formulario" action="/admin/contabilidad/gastos/accion" method="post" data-validar novalidate>
    <input type="hidden" name="accion" value="pagar">
    <input type="hidden" name="id" value="<?= (int) ($gasto['id'] ?? 0) ?>">
    <?php if ($volver !== '') : ?>
        <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= escaparValores($token) ?>">
    <input type="hidden" name="pago_token" value="<?= bin2hex(random_bytes(16)) ?>">

    <div class="nota nota--info mb-md" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">payments</span>
        <span>
            Total <strong class="cifra"><?= formatoMoneda($total) ?></strong>
            · abonado <strong class="cifra"><?= formatoMoneda($pagado) ?></strong>
            · saldo <strong class="cifra"><?= formatoMoneda($saldo) ?></strong>
        </span>
    </div>

    <?php if ($esPpd) : ?>
        <p class="nota nota--advertencia mb-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
            <span>Esta factura es <strong><?= $metodoCfdiActual === 'POR' ? 'de método por definir' : 'PPD (pago en parcialidades)' ?></strong>.
                Cada abono queda registrado aquí y el proveedor debe emitir su complemento de pago.</span>
        </p>
    <?php endif; ?>

    <div class="formulario__rejilla">
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha-<?= $etiqueta ?>">Fecha del abono</label>
            <input class="control" type="date" id="fecha-<?= $etiqueta ?>" name="fecha_pago" required
                   max="<?= date('Y-m-d') ?>" value="<?= escaparValores($fechaPago) ?>">
            <span class="campo__ayuda">La fecha en que salió el dinero.</span>
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="importe-<?= $etiqueta ?>">Importe del abono</label>
            <input class="control" type="number" id="importe-<?= $etiqueta ?>" name="importe"
                   min="0.01" max="<?= number_format($saldo, 2, '.', '') ?>" step="0.01" required
                   value="<?= number_format($saldo, 2, '.', '') ?>">
            <span class="campo__ayuda">Viene el saldo completo. Si pagas una parte, escribe el importe y quedará como pago parcial.</span>
        </div>

        <div class="campo">
            <label class="campo__etiqueta" for="referencia-<?= $etiqueta ?>">Referencia</label>
            <input class="control" type="text" id="referencia-<?= $etiqueta ?>" name="referencia" maxlength="80"
                   placeholder="Transferencia, cheque, folio…">
            <span class="campo__ayuda">Opcional: ayuda a identificar el movimiento.</span>
        </div>

        <div class="campo">
            <label class="campo__etiqueta" for="metodo-<?= $etiqueta ?>">Método de pago</label>
            <select class="control" id="metodo-<?= $etiqueta ?>" name="metodo_pago">
                <option value="">Sin especificar</option>
                <?php foreach (\App\Contabilidad::METODOS_PAGO as $metodo) : ?>
                    <option value="<?= escaparValores($metodo) ?>"<?= $metodo === $metodoActual ? ' selected' : '' ?>><?= escaparValores($metodo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($conFactura) : ?>
            <div class="campo">
                <label class="campo__etiqueta" for="forma-<?= $etiqueta ?>">Forma de pago del CFDI</label>
                <select class="control" id="forma-<?= $etiqueta ?>" name="forma_pago_sat">
                    <option value="">Sin especificar</option>
                    <?php foreach (\App\VentasFiscal::FORMAS_PAGO as $clave => $texto) : ?>
                        <option value="<?= escaparValores($clave) ?>"<?= $clave === $formaActual ? ' selected' : '' ?>><?= escaparValores($clave . ' · ' . $texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label class="campo__etiqueta" for="metodo-cfdi-<?= $etiqueta ?>">Método de pago del CFDI</label>
                <select class="control" id="metodo-cfdi-<?= $etiqueta ?>" name="metodo_pago_sat">
                    <option value="">Sin especificar</option>
                    <?php foreach (\App\Gastos::METODOS_CFDI as $clave => $texto) : ?>
                        <option value="<?= escaparValores($clave) ?>"<?= $clave === $metodoCfdiActual ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="campo__ayuda">Si la factura es PPD, después podrás vincular su complemento de pago desde el mismo gasto.</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones">
            <?php if ($hrefCancelar !== '') : ?>
                <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <?php endif; ?>
            <button class="btn btn--primario" type="submit">Guardar abono</button>
        </div>
    <?php endif; ?>
</form>
