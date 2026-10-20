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
    <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Describe en pocas palabras qué compraste o pagaste." for="concepto">¿En qué fue el gasto?</label>
        <input class="control" type="text" id="concepto" name="concepto" maxlength="255" required value="<?= $v('concepto') ?>"
               placeholder="Ej. Lámina calibre 18, gasolina, renta de bodega"></div>

    <?php $tipoBen = (string) ($gasto['beneficiario_tipo'] ?? (!empty($gasto['proveedor_id']) ? 'proveedor' : '')); ?>
    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="¿A quién le pagaste? Solo elige «Proveedor» si ya lo tienes en tu directorio; si no, usa «Estación de servicio» u «Otro»." for="beneficiario_tipo">Pagado a</label>
        <select class="control" id="beneficiario_tipo" name="beneficiario_tipo" data-beneficiario-tipo required>
            <option value="">— Elige —</option>
            <?php foreach (\App\Gastos::BENEFICIARIOS as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $tipoBen === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo" data-ben-proveedor<?= $tipoBen === 'proveedor' ? '' : ' hidden' ?>><label class="campo__etiqueta campo__etiqueta--obligatorio" for="proveedor_id">Proveedor</label>
        <select class="control" id="proveedor_id" name="proveedor_id">
            <option value="">— Elige un proveedor —</option>
            <?php foreach ($proveedores as $p) : ?>
                <option value="<?= (int) $p['id'] ?>"<?= (int) ($gasto['proveedor_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['nombre'] ?? $p['etiqueta']) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo" data-ben-texto<?= in_array($tipoBen, ['estacion', 'otro'], true) ? '' : ' hidden' ?>><label class="campo__etiqueta" for="beneficiario" data-ben-etiqueta>Descripción</label>
        <input class="control" type="text" id="beneficiario" name="beneficiario" maxlength="150" value="<?= $tipoBen === 'proveedor' ? '' : $v('beneficiario') ?>"
               placeholder="Ej. Gasolinera del centro, el señor de las piedras" autocomplete="off">
        <span class="campo__ayuda" data-ben-ayuda>Escribe a quién le pagaste (por ejemplo: la ferretería de la esquina).</span></div>

    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="El día en que se hizo el gasto (el del ticket o la factura)." for="fecha">¿Cuándo fue?</label>
        <input class="control" type="date" id="fecha" name="fecha" required value="<?= $v('fecha', date('Y-m-d')) ?>"></div>

    <div class="campo"><label class="campo__etiqueta" data-ayuda="Elige el tipo de gasto. Si escribes «gasolina», «CFE», «Telmex»… la plataforma lo sugiere sola." for="categoria_id">¿Qué tipo de gasto fue?</label>
        <select class="control" id="categoria_id" name="categoria_id" data-sugerir-categoria>
            <option value="">— Elige un tipo —</option>
            <?php foreach ($categorias as $c) : ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) ($gasto['categoria_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= escaparValores($c['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Todos cuentan como deducibles; el tipo solo sirve para ordenarlos.</span></div>

    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Lo que dice el ticket o la factura al final, con IVA incluido. La plataforma separa el IVA sola." for="total">¿Cuánto pagaste en total?</label>
        <input class="control control--moneda" type="number" id="total" name="total" min="0.01" step="0.01" inputmode="decimal"
               value="<?= $gasto && !empty($gasto['total']) ? $v('total') : '' ?>" required placeholder="0.00">
        <span class="campo__ayuda">Lo que dice el ticket o la factura, con IVA incluido.</span></div>

    <div class="campo"><label class="campo__etiqueta" data-ayuda="Casi todo lleva 16 % de IVA. Si el ticket no lo desglosa o no lo lleva, elige «No tiene IVA»." for="iva_tasa">¿Ese total incluye IVA?</label>
        <select class="control" id="iva_tasa" name="iva_tasa">
            <option value="16"<?= (string) ($gasto['iva_tasa'] ?? '16') === '16' || (string) ($gasto['iva_tasa'] ?? '16') === '16.00' ? ' selected' : '' ?>>Sí, 16 % (lo normal)</option>
            <option value="0"<?= (float) ($gasto['iva_tasa'] ?? 16) === 0.0 ? ' selected' : '' ?>>No tiene IVA</option>
            <option value="8"<?= (float) ($gasto['iva_tasa'] ?? 16) === 8.0 ? ' selected' : '' ?>>Sí, 8 % (zona fronteriza)</option>
        </select>
        <span class="campo__ayuda">Si no sabes, déjalo en «Sí, 16 %».</span></div>

    <div class="campo"><label class="campo__etiqueta" data-ayuda="Elige «Ya lo pagué» si el dinero ya salió. Solo lo pagado cuenta para tus impuestos." for="estado_pago">¿Ya lo pagaste?</label>
        <select class="control" id="estado_pago" name="estado_pago">
            <option value="pagado"<?= ($gasto['estado_pago'] ?? 'pendiente') === 'pagado' ? ' selected' : '' ?>>Sí, ya lo pagué</option>
            <option value="pendiente"<?= ($gasto['estado_pago'] ?? 'pendiente') === 'pendiente' ? ' selected' : '' ?>>No, lo debo todavía</option>
        </select></div>

    <?php $factura = array_key_exists('comprobante_fiscal', $gasto) && $gasto['comprobante_fiscal'] !== '' && $gasto['comprobante_fiscal'] !== null ? (string) (int) $gasto['comprobante_fiscal'] : ''; ?>
    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Responde según lo que te dieron. Solo un gasto con factura (CFDI) puede restarse de tus impuestos." for="comprobante_fiscal">¿Tiene factura (CFDI)?</label>
        <select class="control" id="comprobante_fiscal" name="comprobante_fiscal" data-factura required>
            <option value="">— Elige —</option>
            <option value="1"<?= $factura === '1' ? ' selected' : '' ?>>Con factura o CFDI</option>
            <option value="0"<?= $factura === '0' ? ' selected' : '' ?>>Sin factura o CFDI</option>
        </select>
        <span class="campo__ayuda">Una foto del ticket no es una factura: aunque la adjuntes, el gasto no será deducible.</span></div>

    <?php if (count($empresas) > 1) : ?>
        <div class="campo"><label class="campo__etiqueta" data-ayuda="Elige el negocio al que corresponde. «Para todos» si sirve a ambos." for="empresa_id">¿Para cuál negocio fue?</label>
            <select class="control" id="empresa_id" name="empresa_id" data-bloque-empresa>
                <option value="">Para todos / no importa</option>
                <?php foreach ($empresas as $e) : ?>
                    <option value="<?= (int) $e->id ?>"<?= (int) ($gasto['empresa_id'] ?? 0) === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option>
                <?php endforeach; ?>
            </select></div>
    <?php endif; ?>
</div>

<?php if (!empty($permiteArchivos)) : ?>
    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" data-ayuda="Sube aquí la factura (PDF y XML) o la foto del ticket. Puedes elegir varios archivos a la vez." for="doc-nuevo">Archivo de la factura o comprobante (opcional)</label>
        <input class="control" type="file" id="doc-nuevo" name="archivo[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
        <span class="campo__ayuda" data-ayuda-archivo>Sube el XML (de él se leen el folio fiscal y el total si los dejas vacíos), el PDF o la imagen. También podrás agregarlos después.</span>
    </div>
<?php endif; ?>

<details class="mt-lg"<?= $errores || !empty($gasto['compartido']) ? ' open' : '' ?>>
    <summary class="boton-enlace" style="cursor:pointer"><strong>Más detalles (opcional)</strong> — forma de pago, folio fiscal, notas</summary>
    <div class="formulario__rejilla mt-md">
        <div class="campo"><label class="campo__etiqueta" data-ayuda="Efectivo, transferencia, tarjeta… solo para tu control." for="metodo_pago">¿Cómo lo pagaste?</label>
            <select class="control" id="metodo_pago" name="metodo_pago">
                <option value="">—</option>
                <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?>
                    <option<?= ($gasto['metodo_pago'] ?? '') === $m ? ' selected' : '' ?>><?= escaparValores($m) ?></option>
                <?php endforeach; ?>
            </select></div>

        <div class="campo"><label class="campo__etiqueta" for="fecha_pago">Fecha en que lo pagaste</label>
            <input class="control" type="date" id="fecha_pago" name="fecha_pago" value="<?= $v('fecha_pago') ?>">
            <span class="campo__ayuda">Si la dejas vacía y ya está pagado, se usa la fecha del gasto.</span></div>

        <div class="campo"><label class="campo__etiqueta" data-ayuda="El folio único de la factura. Si subes el XML se llena solo." for="uuid_fiscal">Folio fiscal de la factura (UUID)</label>
            <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= $v('uuid_fiscal') ?>">
            <span class="campo__ayuda">Si subes el XML de la factura, se llena solo.</span></div>

        <div class="campo"><label class="campo__etiqueta" data-ayuda="Déjalo en «Gasto» salvo que tu contador te diga otra cosa." for="naturaleza">Clasificación contable</label>
            <select class="control" id="naturaleza" name="naturaleza">
                <?php foreach (\App\Gastos::NATURALEZAS as $clave => $texto) : ?>
                    <option value="<?= $clave ?>"<?= ($gasto['naturaleza'] ?? 'gasto') === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="campo__ayuda">Normalmente «Gasto». No lo cambies si no estás seguro.</span></div>

        <input type="hidden" name="revision_fiscal" value="<?= $v('revision_fiscal', 'por_validar') ?>">

        <fieldset class="campo campo--completo">
            <legend class="campo__etiqueta">¿Se reparte entre varios negocios?</legend>
            <label class="comprobacion" for="compartido">
                <input type="checkbox" id="compartido" name="compartido" value="1" <?= $compartido ? 'checked' : '' ?> data-compartido>
                <span>Sí, repartir este gasto entre mis negocios</span>
            </label>
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

        <div class="campo campo--completo"><label class="campo__etiqueta" for="observaciones">Notas</label>
            <textarea class="control" id="observaciones" name="observaciones" rows="3"><?= $v('observaciones') ?></textarea></div>
    </div>
</details>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= escaparValores($cancelar) ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar gasto</button>
</div>

<script>
/* Reparto entre negocios y vínculo del proveedor elegido en la lista; el servidor valida de todos modos. */
(function () {
  const marca = document.querySelector('[data-compartido]');
  const bloqueReparto = document.querySelector('[data-bloque-reparto]');
  const selNegocio = document.querySelector('[data-bloque-empresa]');
  if (marca) {
    marca.addEventListener('change', function () {
      bloqueReparto.hidden = !marca.checked;
      if (selNegocio) { selNegocio.disabled = marca.checked; }
    });
  }
  const nombre = document.getElementById('beneficiario');
  /* Sugiere el tipo de gasto según lo que se escribe; si la persona ya eligió uno, no lo cambia. */
  const tipo = document.querySelector('[data-sugerir-categoria]');
  const concepto = document.getElementById('concepto');
  if (tipo && concepto && nombre) {
    let manual = tipo.value !== '';
    tipo.addEventListener('change', function () { manual = tipo.value !== ''; });
    const reglas = [
      ['Combustible y transporte', /gasolin|diesel|combustible|caseta|pemex|oxxo gas|uber|taxi/],
      ['Luz, agua y gas', /\bcfe\b|\bluz\b|agua|\bgas\b|conagua|oomsapas/],
      ['Teléfono e internet', /telcel|telmex|izzi|totalplay|internet|tel[eé]fono|megacable|\bat&t\b/],
      ['Ferretería y materiales', /ferreter|home depot|comex|pintura|tornill|material|interceramic|ferremax/],
      ['Papelería y oficina', /papeler|office ?max|oficina|toner|impresi/],
      ['Renta', /renta|arrendamiento/],
      ['Seguros', /seguro|p[oó]liza/],
      ['Donativos', /donativo|teleton|teletón|cruz roja|donaci/],
      ['Alimentos y despensa', /soriana|walmart|costco|super|despensa|caf[eé]|panader|restaurante|comida/],
      ['Retiros del propietario', /retiro|disposici[oó]n/],
      ['Mantenimiento', /mantenimiento|reparaci|servicio t[eé]cnico/],
      ['Honorarios', /honorarios|contador|notario|abogado/],
      ['Sueldos', /sueldo|n[oó]mina|salario/],
    ];
    const sugerir = function () {
      if (manual) { return; }
      const texto = (concepto.value + ' ' + nombre.value).toLowerCase();
      for (let i = 0; i < reglas.length; i += 1) {
        if (reglas[i][1].test(texto)) {
          const opcion = Array.prototype.find.call(tipo.options, function (o) { return o.text === reglas[i][0]; });
          if (opcion) { tipo.value = opcion.value; }
          return;
        }
      }
    };
    concepto.addEventListener('input', sugerir);
    nombre.addEventListener('input', sugerir);
  }
  /* «Pagado a»: el proveedor se elige de la lista; estación u otro llevan una descripción libre. */
  const tipoBen = document.querySelector('[data-beneficiario-tipo]');
  const bloqueProv = document.querySelector('[data-ben-proveedor]');
  const bloqueTexto = document.querySelector('[data-ben-texto]');
  const etiquetaBen = document.querySelector('[data-ben-etiqueta]');
  const ayudaBen = document.querySelector('[data-ben-ayuda]');
  if (tipoBen && bloqueProv && bloqueTexto) {
    const alternar = function () {
      const t = tipoBen.value;
      bloqueProv.hidden = t !== 'proveedor';
      bloqueTexto.hidden = t !== 'estacion' && t !== 'otro';
      if (etiquetaBen) { etiquetaBen.textContent = t === 'estacion' ? 'Nombre de la estación (opcional)' : 'Descripción'; }
      if (ayudaBen) { ayudaBen.textContent = t === 'estacion' ? 'Si lo dejas vacío se guarda como «Estación de servicio».' : 'Escribe a quién le pagaste (por ejemplo: la ferretería de la esquina).'; }
    };
    tipoBen.addEventListener('change', alternar);
    alternar();
  }
})();
</script>
