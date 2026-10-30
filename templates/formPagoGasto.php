<?php
/**
 * templates/formPagoGasto.php — Formulario para registrar el pago de un gasto.
 *
 * Compartido por la página suelta /admin/contabilidad/gastos/pagar y por el
 * modal del detalle del gasto (mismo action, campos y token en los dos lados).
 *
 * No trae botones de acción por defecto: el modal usa el pie del diálogo y la
 * página suelta los dibuja con $conAcciones = true.
 *
 * Variables: $gasto (array de Gastos::buscarID), $token (string),
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
?>
<form class="formulario" action="/admin/contabilidad/gastos/accion" method="post" data-validar novalidate>
    <input type="hidden" name="accion" value="pagar">
    <input type="hidden" name="id" value="<?= (int) ($gasto['id'] ?? 0) ?>">
    <?php if ($volver !== '') : ?>
        <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= escaparValores($token) ?>">

    <div class="formulario__rejilla">
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha-<?= $etiqueta ?>">Fecha de pago</label>
            <input class="control" type="date" id="fecha-<?= $etiqueta ?>" name="fecha_pago" required
                   max="<?= date('Y-m-d') ?>" value="<?= escaparValores($fechaPago) ?>">
            <span class="campo__ayuda">La fecha en que salió el dinero.</span>
        </div>

        <div class="campo">
            <span class="campo__etiqueta">Importe del pago</span>
            <span class="cifra"><strong><?= formatoMoneda((float) ($gasto['total'] ?? 0)) ?></strong></span>
            <span class="campo__ayuda">Se registra el pago por el total del gasto.</span>
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
                <span class="campo__ayuda">Si la factura es PPD, después podrás adjuntar su complemento de pago en «Facturas y comprobantes».</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones">
            <?php if ($hrefCancelar !== '') : ?>
                <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <?php endif; ?>
            <button class="btn btn--primario" type="submit">Guardar pago</button>
        </div>
    <?php endif; ?>
</form>
