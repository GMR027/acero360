<?php
/**
 * templates/formularioGasto.php — alta y edición de un gasto.
 * Variables: $gasto (array), $errores, $proveedores, $categorias, $empresas, $cancelar (URL),
 *            $reglasCategoria (opcional: CoincidenciasFactura::REGLAS_CATEGORIA, con la que
 *            el navegador sugiere el tipo de gasto — el mismo listado que usa la lectura inteligente)
 */
$errores = $errores ?? [];
$reglasCategoria = $reglasCategoria ?? [];
/* Sólo el alta de un gasto ofrece registrar ahí mismo un proveedor que aún no
 * existe en el directorio (en la edición no: el gasto ya tiene lo suyo). */
$permiteAltaProveedor = !empty($permiteAltaProveedor);
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

<p class="texto-xs texto-secundario mb-md" data-leyenda-pendientes hidden>
    Los campos con <strong>borde rojo</strong> están por llenar; los de <strong>borde punteado</strong> son recomendados.
</p>

<div class="formulario__rejilla">
    <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Describe en pocas palabras qué compraste o pagaste." for="concepto">¿En qué fue el gasto?</label>
        <input class="control" type="text" id="concepto" name="concepto" maxlength="255" required value="<?= $v('concepto') ?>"
               placeholder="Ej. Lámina calibre 18, gasolina, renta de bodega"></div>

    <?php $tipoBen = (string) ($gasto['beneficiario_tipo'] ?? (!empty($gasto['proveedor_id']) ? 'proveedor' : '')); ?>
    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="¿A quién le pagaste? Escribe para buscar en tu directorio por nombre o RFC; si todavía no lo tienes, regístralo con el botón que aparece debajo." for="beneficiario_tipo">Pagado a</label>
        <select class="control" id="beneficiario_tipo" name="beneficiario_tipo" data-beneficiario-tipo required>
            <option value="">— Elige —</option>
            <?php foreach (\App\Gastos::BENEFICIARIOS as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $tipoBen === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select></div>

    <div class="campo" data-ben-proveedor<?= $tipoBen === 'proveedor' ? '' : ' hidden' ?>><label class="campo__etiqueta campo__etiqueta--obligatorio" for="proveedor_id">Proveedor</label>
        <?php if ($permiteAltaProveedor) : ?>
            <!-- Botón que sólo aparece cuando lo escrito no coincide con ningún proveedor:
                 lleva el texto capturado a /admin/proveedores/crear y de ahí se vuelve
                 aquí con el gasto precargado. Lo maneja gastoProveedor.js.
                 Va ENCIMA del campo: la lista desplegable del buscador se abre hacia
                 abajo y taparía el botón. -->
            <button class="btn btn--secundario btn--sm mt-sm mb-sm" type="button" data-registrar-proveedor hidden></button>
        <?php endif; ?>
        <!-- data-buscador: src/js/modulos/selectorBusqueda.js convierte este <select> en
             un campo de búsqueda con lista filtrada por NOMBRE y RFC (los dos datos que
             también se muestran en cada opción). Sin JavaScript queda el selector normal:
             el <select> real se mantiene en el DOM y viaja en el envío. -->
        <select class="control" id="proveedor_id" name="proveedor_id"
                data-buscador
                data-obligatorio-si="#beneficiario_tipo=proveedor"
                data-buscador-entidad="proveedor"
                data-buscador-placeholder="Escribe para buscar por nombre o RFC…">
            <option value="">— Elige un proveedor —</option>
            <?php foreach ($proveedores as $p) : ?>
                <option value="<?= (int) $p['id'] ?>"<?= (int) ($gasto['proveedor_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>
                        data-rfc="<?= escaparValores((string) ($p['rfc'] ?? '')) ?>"><?= escaparValores((string) ($p['etiqueta'] ?? ($p['nombre'] ?? ''))) ?></option>
            <?php endforeach; ?>
        </select>
        <!-- RFC del proveedor elegido (lo completa src/js/modulos/gastoProveedor.js). -->
        <span class="campo__ayuda" data-proveedor-rfc hidden></span>
    </div>

    <div class="campo" data-ben-texto<?= in_array($tipoBen, ['estacion', 'otro'], true) ? '' : ' hidden' ?>><label class="campo__etiqueta" for="beneficiario" data-ben-etiqueta>Descripción</label>
        <input class="control" type="text" id="beneficiario" name="beneficiario" maxlength="150" value="<?= $tipoBen === 'proveedor' ? '' : $v('beneficiario') ?>"
               data-obligatorio-si="#beneficiario_tipo=otro"
               placeholder="Ej. Gasolinera del centro, el señor de las piedras" autocomplete="off">
        <span class="campo__ayuda" data-ben-ayuda>Escribe a quién le pagaste (por ejemplo: la ferretería de la esquina).</span></div>

    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="El día en que se hizo el gasto (el del ticket o la factura)." for="fecha">¿Cuándo fue?</label>
        <input class="control" type="date" id="fecha" name="fecha" required value="<?= $v('fecha', date('Y-m-d')) ?>"></div>

    <div class="campo"><label class="campo__etiqueta" data-ayuda="Elige el tipo de gasto. Si escribes «gasolina», «CFE», «Telmex»… la plataforma lo sugiere sola." for="categoria_id">¿Qué tipo de gasto fue?</label>
        <select class="control" id="categoria_id" name="categoria_id" data-sugerir-categoria
                data-recomendado
                data-reglas="<?= escaparValores(json_encode(
                    array_map(
                        static fn (string $nombre, string $patron): array => [$nombre, $patron],
                        array_keys($reglasCategoria),
                        array_values($reglasCategoria)
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )) ?>">
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
    <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Responde según lo que te dieron. La estimación resta todo gasto pagado; el CFDI es el respaldo que tu contador necesita para deducirlo." for="comprobante_fiscal">¿Tiene factura (CFDI)?</label>
        <select class="control" id="comprobante_fiscal" name="comprobante_fiscal" data-factura required>
            <option value="">— Elige —</option>
            <option value="1"<?= $factura === '1' ? ' selected' : '' ?>>Con factura o CFDI</option>
            <option value="0"<?= $factura === '0' ? ' selected' : '' ?>>Sin factura o CFDI</option>
        </select>
        <span class="campo__ayuda">Una foto del ticket no es una factura: aunque la adjuntes, el gasto no será deducible.</span></div>

    <?php
    /* Los cuatro datos del CFDI: sólo existen si el gasto lleva factura (los oculta y
       limpia el script). Vienen del documento —la lectura inteligente o el XML que
       subas en el mismo formulario— y se autocompletan solos. */
    $usoCfdi = strtoupper(trim((string) ($gasto['uso_cfdi'] ?? '')));
    $metodoCfdi = strtoupper(trim((string) ($gasto['metodo_pago_sat'] ?? '')));
    if (!isset(\App\Gastos::METODOS_CFDI[$metodoCfdi])) {
        $metodoCfdi = 'POR';   // sin dato guardado: la factura está «por definir»
    }
    $formaCfdi = trim((string) ($gasto['forma_pago_sat'] ?? ''));
    if ($formaCfdi !== '' && !isset(\App\VentasFiscal::FORMAS_PAGO[$formaCfdi])) {
        $formaCfdi = '';   // clave fuera del catálogo: se captura de nuevo
    }
    $tipoCfdi = strtoupper(trim((string) ($gasto['tipo_cfdi'] ?? '')));
    if ($tipoCfdi !== '' && !isset(\App\Contabilidad::TIPOS_CFDI[$tipoCfdi])) {
        $tipoCfdi = '';
    }
    ?>
    <div class="campo" data-cfdi<?= $factura === '1' ? '' : ' hidden' ?>><label class="campo__etiqueta" data-ayuda="Para qué se usó la factura, según el catálogo del SAT." for="uso_cfdi">Uso del CFDI</label>
        <select class="control" id="uso_cfdi" name="uso_cfdi">
            <option value="">— Elige el uso —</option>
            <?php foreach (\App\Gastos::USOS_CFDI as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $usoCfdi === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Lo más común es «G01 · Adquisición de mercancías» o «G03 · Gastos en general»; si no lo sabes, déjalo vacío.</span></div>

    <div class="campo" data-cfdi<?= $factura === '1' ? '' : ' hidden' ?>><label class="campo__etiqueta" data-ayuda="Lo que dice la factura sobre cómo se pagará: de una sola vez, en parcialidades, o todavía sin definir." for="metodo_pago_sat">Método de pago del CFDI</label>
        <select class="control" id="metodo_pago_sat" name="metodo_pago_sat">
            <?php foreach (\App\Gastos::METODOS_CFDI as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $metodoCfdi === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Si eliges «PPD · Pago en parcialidades» o «Por definir», el Centro de seguimiento lo vigila hasta que quede claro.</span></div>

    <div class="campo" data-cfdi<?= $factura === '1' ? '' : ' hidden' ?>><label class="campo__etiqueta" data-ayuda="Con qué se pagó, tal como lo declara la factura (efectivo, transferencia, tarjeta…)." for="forma_pago_sat">Forma de pago del CFDI</label>
        <select class="control" id="forma_pago_sat" name="forma_pago_sat">
            <option value="">— Sin definir —</option>
            <?php foreach (\App\VentasFiscal::FORMAS_PAGO as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $formaCfdi === $clave ? ' selected' : '' ?>><?= escaparValores($clave . ' · ' . $texto) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">La clave SAT que trae el comprobante: 01 efectivo, 03 transferencia, 04 tarjeta de crédito…</span></div>

    <div class="campo" data-cfdi<?= $factura === '1' ? '' : ' hidden' ?>><label class="campo__etiqueta" data-ayuda="Qué clase de comprobante es: una factura normal es «Ingreso»." for="tipo_cfdi">Tipo de comprobante (CFDI)</label>
        <select class="control" id="tipo_cfdi" name="tipo_cfdi">
            <option value="">— Elige el tipo —</option>
            <?php foreach (\App\Contabilidad::TIPOS_CFDI as $clave => $texto) : ?>
                <option value="<?= $clave ?>"<?= $tipoCfdi === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Una factura de compra normal es «I · Ingreso»; una nota de crédito es «E · Egreso».</span></div>

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
    <!-- Con JavaScript el archivo se elige en la ventana modal (el mismo modal__panel que
         «Registro Venta Inteligente») y el campo de abajo desaparece; sin JavaScript queda
         en línea, tal cual. El input conserva form="form-gasto" para enviarse con el
         formulario aunque el módulo lo mueva dentro del diálogo. -->
    <div class="campo campo--completo mt-lg">
        <span class="campo__etiqueta">Archivo de la factura o comprobante (opcional)</span>
        <button class="btn btn--secundario" type="button" hidden
                data-archivo-modal="doc-nuevo"
                data-archivo-sobre="Alta de gasto"
                data-archivo-titulo="Archivo de la factura o comprobante">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">upload_file</span>
            <span>Elegir archivos</span>
        </button>
        <span class="campo__ayuda" data-archivo-resumen hidden></span>
    </div>
    <div class="campo campo--completo" data-archivo-linea>
        <label class="campo__etiqueta" data-ayuda="Sube aquí la factura (PDF y XML) o la foto del ticket. Puedes elegir varios archivos a la vez." for="doc-nuevo">Archivo de la factura o comprobante (opcional)</label>
        <input class="control" type="file" id="doc-nuevo" name="archivo[]" form="form-gasto" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
        <span class="campo__ayuda" data-ayuda-archivo>Sube el XML (de él se leen el folio fiscal y el total si los dejas vacíos), el PDF o la imagen. También podrás agregarlos después.</span>
    </div>
<?php endif; ?>

<details class="mt-lg"<?= $errores || !empty($gasto['compartido']) ? ' open' : '' ?>>
    <summary class="boton-enlace" style="cursor:pointer"><strong>Más detalles (opcional)</strong> — folio fiscal, reparto entre negocios y notas</summary>
    <div class="formulario__rejilla mt-md">
        <div class="campo"><label class="campo__etiqueta" data-ayuda="El folio único de la factura. Si subes el XML se llena solo." for="uuid_fiscal">Folio fiscal de la factura (UUID)</label>
            <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= $v('uuid_fiscal') ?>" data-recomendado-si="#comprobante_fiscal=1">
            <span class="campo__ayuda">Si subes el XML de la factura, se llena solo.</span></div>

        <input type="hidden" name="revision_fiscal" value="<?= $v('revision_fiscal', 'por_validar') ?>">

        <!-- Datos del CFDI que se leen del documento (los trae la lectura inteligente y los
             guarda el servidor al crear el gasto; en la edición no se modifican, por eso van ocultos).
             Los cuatro datos visibles del CFDI —uso, método, forma y tipo de comprobante— van
             arriba, en el bloque que sólo aparece cuando el gasto tiene factura. -->
        <input type="hidden" name="folio_comercial" value="<?= $v('folio_comercial') ?>">
        <input type="hidden" name="rfc_emisor" value="<?= $v('rfc_emisor') ?>">
        <input type="hidden" name="rfc_receptor" value="<?= $v('rfc_receptor') ?>">
        <input type="hidden" name="moneda" value="<?= $v('moneda', 'MXN') ?>">

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
    /* Las reglas llegan del servidor (CoincidenciasFactura::REGLAS_CATEGORIA): es el mismo
       listado que usa la lectura inteligente, así que el formulario y la factura dicen lo mismo. */
    let reglas = [];
    try {
      reglas = JSON.parse(tipo.getAttribute('data-reglas') || '[]').map(function (par) {
        return [par[0], new RegExp(par[1], 'i')];
      });
    } catch (error) {
      reglas = [];
    }
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
  /* Uso del CFDI y método de pago: sólo existen si el gasto lleva factura. */
  const facturaSel = document.querySelector('[data-factura]');
  const camposCfdi = document.querySelectorAll('[data-cfdi]');
  if (facturaSel && camposCfdi.length) {
    const alternarCfdi = function () {
      const conFactura = facturaSel.value === '1';
      camposCfdi.forEach(function (campo) { campo.hidden = !conFactura; });
    };
    facturaSel.addEventListener('change', alternarCfdi);
    alternarCfdi();
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
