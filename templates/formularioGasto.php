<?php
/**
 * templates/formularioGasto.php — alta y edición de un gasto.
 * Variables: $gasto (array), $errores, $proveedores, $categorias, $empresas, $cancelar (URL)
 */
$errores = $errores ?? [];
$reparto = [];
foreach ($gasto['reparto'] ?? [] as $r) {
    $reparto[$r['empresa_id']] = $r['porcentaje'];
}
$v = static fn (string $campo, $porDefecto = '') => escaparValores((string) ($gasto[$campo] ?? $porDefecto));
$compartido = !empty($gasto['compartido']);
$numEmpresas = max(1, count($empresas));
?>
<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

<div class="formulario__rejilla">
    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha</label>
        <input class="control" type="date" id="fecha" name="fecha" required value="<?= $v('fecha', date('Y-m-d')) ?>"></div>

    <div class="campo"><label class="campo__etiqueta" for="proveedor_id">Proveedor del directorio</label>
        <select class="control" id="proveedor_id" name="proveedor_id">
            <option value="">— Otro / sin proveedor —</option>
            <?php foreach ($proveedores as $p) : ?>
                <option value="<?= (int) $p['id'] ?>"<?= (int) ($gasto['proveedor_id'] ?? 0) === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="beneficiario">Proveedor o beneficiario</label>
        <input class="control" type="text" id="beneficiario" name="beneficiario" maxlength="150" required value="<?= $v('beneficiario') ?>"
               placeholder="Se completa con el proveedor elegido"></div>

    <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="concepto">Concepto</label>
        <input class="control" type="text" id="concepto" name="concepto" maxlength="255" required value="<?= $v('concepto') ?>"></div>

    <div class="campo"><label class="campo__etiqueta" for="categoria_id">Categoría</label>
        <select class="control" id="categoria_id" name="categoria_id">
            <option value="">— Sin categoría —</option>
            <?php foreach ($categorias as $c) : ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) ($gasto['categoria_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= escaparValores($c['nombre']) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo"><label class="campo__etiqueta" for="naturaleza">Naturaleza</label>
        <select class="control" id="naturaleza" name="naturaleza">
            <?php foreach (\App\Gastos::NATURALEZAS as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= ($gasto['naturaleza'] ?? 'gasto') === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="subtotal">Subtotal (antes de IVA)</label>
        <input class="control control--moneda" type="number" id="subtotal" name="subtotal" required min="0.01" step="0.01" inputmode="decimal" value="<?= $v('subtotal') ?>"></div>

    <div class="campo"><label class="campo__etiqueta" for="iva_tasa">Tasa de IVA (%)</label>
        <input class="control" type="number" id="iva_tasa" name="iva_tasa" min="0" max="30" step="0.01" list="tasas" value="<?= $v('iva_tasa', '16') ?>">
        <datalist id="tasas"><option value="16"><option value="8"><option value="0"></datalist>
        <span class="campo__ayuda">El IVA y el total se calculan al guardar.</span></div>

    <fieldset class="campo campo--completo">
        <legend class="campo__etiqueta">Negocio relacionado</legend>
        <label class="comprobacion" for="compartido">
            <input type="checkbox" id="compartido" name="compartido" value="1" <?= $compartido ? 'checked' : '' ?> data-compartido>
            <span><strong>Gasto compartido por los negocios</strong><br><span class="texto-xs texto-secundario">Se registra una sola vez; el reparto solo sirve para reportes por negocio y no cambia el total.</span></span>
        </label>
        <div data-bloque-empresa<?= $compartido ? ' hidden' : '' ?>>
            <label class="campo__etiqueta" for="empresa_id">Negocio</label>
            <select class="control" id="empresa_id" name="empresa_id">
                <option value="">— General (sin negocio) —</option>
                <?php foreach ($empresas as $e) : ?>
                    <option value="<?= (int) $e->id ?>"<?= (int) ($gasto['empresa_id'] ?? 0) === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div data-bloque-reparto<?= $compartido ? '' : ' hidden' ?>>
            <p class="campo__ayuda">Porcentaje de cada negocio (debe sumar 100):</p>
            <?php foreach ($empresas as $e) : ?>
                <div class="campo">
                    <label class="campo__etiqueta" for="reparto_<?= (int) $e->id ?>"><?= escaparValores($e->nombre) ?> (%)</label>
                    <input class="control" type="number" id="reparto_<?= (int) $e->id ?>" name="reparto[<?= (int) $e->id ?>]" min="0" max="100" step="0.01"
                           value="<?= escaparValores((string) ($reparto[(int) $e->id] ?? ($compartido ? 0 : round(100 / $numEmpresas, 2)))) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="campo"><label class="campo__etiqueta" for="estado_pago">Estado de pago</label>
        <select class="control" id="estado_pago" name="estado_pago">
            <option value="pendiente"<?= ($gasto['estado_pago'] ?? 'pendiente') === 'pendiente' ? ' selected' : '' ?>>Pendiente</option>
            <option value="pagado"<?= ($gasto['estado_pago'] ?? '') === 'pagado' ? ' selected' : '' ?>>Pagado</option>
        </select></div>
    <div class="campo"><label class="campo__etiqueta" for="fecha_pago">Fecha de pago</label>
        <input class="control" type="date" id="fecha_pago" name="fecha_pago" value="<?= $v('fecha_pago') ?>"></div>
    <div class="campo"><label class="campo__etiqueta" for="metodo_pago">Método de pago</label>
        <select class="control" id="metodo_pago" name="metodo_pago">
            <option value="">—</option>
            <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?>
                <option<?= ($gasto['metodo_pago'] ?? '') === $m ? ' selected' : '' ?>><?= escaparValores($m) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo campo--completo">
        <label class="comprobacion" for="comprobante_fiscal">
            <input type="checkbox" id="comprobante_fiscal" name="comprobante_fiscal" value="1" <?= !empty($gasto['comprobante_fiscal']) ? 'checked' : '' ?>>
            <span><strong>Cuenta con comprobante fiscal (CFDI)</strong><br><span class="texto-xs texto-secundario">Es un indicador tuyo: no valida el CFDI ni vuelve deducible el gasto.</span></span>
        </label>
    </div>
    <div class="campo"><label class="campo__etiqueta" for="uuid_fiscal">UUID (folio fiscal)</label>
        <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= $v('uuid_fiscal') ?>"></div>
    <div class="campo"><label class="campo__etiqueta" for="revision_fiscal">Revisión fiscal</label>
        <select class="control" id="revision_fiscal" name="revision_fiscal">
            <?php foreach (\App\Gastos::REVISION as $clave => $d) : ?>
                <option value="<?= $clave ?>"<?= ($gasto['revision_fiscal'] ?? 'por_validar') === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Deducibilidad y acreditamiento se validan con el contador.</span></div>
    <div class="campo campo--completo"><label class="campo__etiqueta" for="observaciones">Observaciones</label>
        <textarea class="control" id="observaciones" name="observaciones" rows="3"><?= $v('observaciones') ?></textarea></div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= escaparValores($cancelar) ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar gasto</button>
</div>

<script>
/* Alterna entre «un negocio» y «reparto entre negocios»; el servidor valida de todos modos. */
(function () {
  const marca = document.querySelector('[data-compartido]');
  const bloqueEmpresa = document.querySelector('[data-bloque-empresa]');
  const bloqueReparto = document.querySelector('[data-bloque-reparto]');
  if (!marca) { return; }
  marca.addEventListener('change', function () {
    bloqueEmpresa.hidden = marca.checked;
    bloqueReparto.hidden = !marca.checked;
  });
})();
</script>
