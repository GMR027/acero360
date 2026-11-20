<?php
/**
 * templates/formCobroIngreso.php — Formulario para anotar un cobro (abono) de una venta.
 *
 * Es el mismo formulario que vive dentro de «Facturación y cobro» (sección
 * «Pago») y el que abre el botón «Cobrar» de los listados en una ventana
 * modal. Compartirlo evita dos capturas distintas del mismo dato.
 *
 * Variables: $ingreso (fila de Contabilidad::listarIngresos o buscarIngreso),
 *            $retorno (URL interna a la que se regresa después de guardar),
 *            $sufijo (opcional: sufijo de los id, para que varias copias en la
 *                     misma página —una por fila del listado— no choquen),
 *            $conAcciones (opcional: dibuja el botón de envío; el modal usa el
 *                          pie del diálogo y lo apaga).
 */
$ingreso = $ingreso ?? [];
$retorno = (string) ($retorno ?? '');
$sufijo  = (string) ($sufijo ?? '');
$conAcciones = (bool) ($conAcciones ?? true);

/** id único del campo dentro de esta copia del formulario. */
$campo = static fn (string $nombre): string => 'cobro-' . $nombre . $sufijo;

$saldo = (float) ($ingreso['saldo'] ?? 0);
$sugerido = \App\Contabilidad::metodoSugerido($ingreso);
?>
<form class="formulario" action="/admin/contabilidad/ingresos/accion" method="post" novalidate>
    <input type="hidden" name="id" value="<?= (int) ($ingreso['id'] ?? 0) ?>">
    <input type="hidden" name="accion" value="cobro">
    <input type="hidden" name="volver" value="<?= escaparValores($retorno) ?>">
    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
    <div class="formulario__rejilla">
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="El día en que recibiste el dinero." for="<?= $campo('fecha') ?>">¿Cuándo te pagaron?</label>
            <input class="control" type="date" id="<?= $campo('fecha') ?>" name="fecha" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Lo que el cliente te pagó en esta ocasión. Puedes anotar varios pagos." for="<?= $campo('importe') ?>">¿Cuánto te pagaron?</label>
            <input class="control control--moneda" type="number" id="<?= $campo('importe') ?>" name="importe" required min="0.01" step="0.01" inputmode="decimal"
                   max="<?= number_format($saldo, 2, '.', '') ?>" value="<?= number_format($saldo, 2, '.', '') ?>">
            <span class="campo__ayuda">Saldo pendiente: <?= formatoMoneda($saldo) ?></span>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" data-ayuda="Efectivo, transferencia, etc." for="<?= $campo('metodo') ?>">¿Cómo te pagaron?</label>
            <select class="control" id="<?= $campo('metodo') ?>" name="metodo">
                <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?>
                    <option<?= $m === $sugerido ? ' selected' : '' ?>><?= escaparValores($m) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="campo__ayuda">Sugerida: <?= escaparValores($sugerido) ?> (<?= \App\Contabilidad::llevaIva($ingreso) ? 'con' : 'sin' ?> IVA). Cámbiala si te pagaron de otra forma.</span>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="<?= $campo('ref') ?>">Referencia (opcional)</label>
            <input class="control" type="text" id="<?= $campo('ref') ?>" name="referencia" maxlength="80">
        </div>
    </div>
    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Anotar pago recibido</button>
        </div>
    <?php endif; ?>
</form>
