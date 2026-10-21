<?php
/**
 * templates/formularioOrdenCompra.php — alta y edición de compras (un solo formulario para ambos tipos).
 *
 * Variables: $orden (array), $lineas (array de partidas), $proveedores, $catalogo (Productos::paraCotizador),
 *            $categorias (id => nombre), $empresas (Empresas::paraSelector), $materiales, $errores
 *
 * Hay dos tablas de partidas, una por tipo; solo la del tipo elegido se envía (la otra queda deshabilitada).
 */
$errores = $errores ?? [];
$tipo = $orden['tipo'] ?? 'semiterminado';
$lineas = $lineas ?: [['producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'cantidad' => 1, 'costo' => '0.00', 'descripcion' => '', 'unidad' => '', 'controla' => 0]];
$vacia = ['producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'cantidad' => 1, 'costo' => '0.00', 'descripcion' => '', 'unidad' => '', 'controla' => 0];
$estadoCfdi = in_array($orden['estado_cfdi'] ?? '', ['pendiente_cfdi', 'no_facturada'], true) ? $orden['estado_cfdi'] : 'pendiente_cfdi';
$cantidadTexto = static fn ($c) => rtrim(rtrim(number_format((float) $c, 3, '.', ''), '0'), '.') ?: '1';
?>
<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

<fieldset class="campo campo--completo mb-lg">
    <legend class="campo__etiqueta campo__etiqueta--obligatorio">¿Qué tipo de compra es?</legend>
    <label class="comprobacion" for="tipo-semiterminado">
        <input type="radio" id="tipo-semiterminado" name="tipo" value="semiterminado" data-tipo-compra<?= $tipo === 'semiterminado' ? ' checked' : '' ?>>
        <span><strong>Producto semiterminado para venta</strong><br>
            <span class="texto-xs texto-secundario">Lo entrega el proveedor y aquí se termina antes de venderlo. Se relaciona con un producto del catálogo; al recibirlo queda «por terminar», no disponible para venta.</span></span>
    </label>
    <label class="comprobacion" for="tipo-insumo">
        <input type="radio" id="tipo-insumo" name="tipo" value="insumo" data-tipo-compra<?= $tipo === 'insumo' ? ' checked' : '' ?>>
        <span><strong>Material, insumo o consumible</strong><br>
            <span class="texto-xs texto-secundario">Pintura, thinner, discos, guantes, soldadura, varilla, lámina… No necesita producto del catálogo; si lo eliges, puedes controlarlo en inventario como material.</span></span>
    </label>
</fieldset>

<div class="formulario__rejilla">
    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="proveedor_id">Proveedor</label>
        <select class="control" id="proveedor_id" name="proveedor_id" required>
            <option value="">— Elige un proveedor —</option>
            <?php foreach ($proveedores as $p) : ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) ($orden['proveedor_id'] ?? 0) === $p['id'] ? 'selected' : '' ?>>
                    <?= escaparValores($p['etiqueta']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha_solicitud">Fecha de la compra</label>
        <input class="control" type="date" id="fecha_solicitud" name="fecha_solicitud" required
               value="<?= escaparValores($orden['fecha_solicitud'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="campo">
        <label class="campo__etiqueta" data-ayuda="Tu folio interno. Si lo dejas vacío, la plataforma asigna el siguiente (OC-año-0001)." for="folio">Folio interno</label>
        <input class="control" type="text" id="folio" name="folio" maxlength="20" autocomplete="off"
               value="<?= escaparValores($orden['folio'] ?? '') ?>" placeholder="Automático">
    </div>
    <div class="campo">
        <label class="campo__etiqueta" data-ayuda="El pedido, la remisión o el folio que te dio el proveedor, si existe." for="referencia">Pedido o folio del proveedor</label>
        <input class="control" type="text" id="referencia" name="referencia" maxlength="60" autocomplete="off"
               value="<?= escaparValores($orden['referencia'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div class="campo">
        <label class="campo__etiqueta" for="categoria_id">Categoría de compra</label>
        <select class="control" id="categoria_id" name="categoria_id">
            <option value="0">— Sin categoría —</option>
            <?php foreach ($categorias as $cid => $cnombre) : ?>
                <option value="<?= (int) $cid ?>" <?= (int) ($orden['categoria_id'] ?? 0) === (int) $cid ? 'selected' : '' ?>><?= escaparValores($cnombre) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (count($empresas) > 1) : ?>
        <div class="campo">
            <label class="campo__etiqueta" data-ayuda="El negocio al que corresponde la compra; define la entidad fiscal de su gasto." for="empresa_id">¿Para cuál negocio fue?</label>
            <select class="control" id="empresa_id" name="empresa_id">
                <option value="0">— General —</option>
                <?php foreach ($empresas as $e) : ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) ($orden['empresa_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>><?= escaparValores($e['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="campo">
        <label class="campo__etiqueta" for="estado_cfdi">Factura</label>
        <select class="control" id="estado_cfdi" name="estado_cfdi">
            <option value="pendiente_cfdi" <?= $estadoCfdi === 'pendiente_cfdi' ? 'selected' : '' ?>>CFDI pendiente (aún no la recibo)</option>
            <option value="no_facturada" <?= $estadoCfdi === 'no_facturada' ? 'selected' : '' ?>>Sin factura (no se facturó)</option>
        </select>
        <span class="campo__ayuda">«Con CFDI» se marca solo al adjuntar el XML en el detalle de la compra.</span>
    </div>
    <div class="campo">
        <label class="campo__etiqueta" data-ayuda="Solo si lo conoces (viene en la factura o la nota). No se calcula ni se supone: vacío significa «no especificado»." for="iva">IVA de la compra</label>
        <input class="control control--moneda" type="number" id="iva" name="iva" min="0" step="0.01" inputmode="decimal"
               value="<?= escaparValores($orden['iva'] ?? '') ?>" placeholder="Opcional">
        <span class="campo__ayuda">En pesos, ya calculado. Se suma al total de la compra.</span>
    </div>
</div>

<?php
/* ---- Tabla de semiterminados ------------------------------------------------------------------ */
$filasSemi = $tipo === 'semiterminado' ? $lineas : [$vacia];
?>
<section data-bloque-tipo="semiterminado"<?= $tipo === 'semiterminado' ? '' : ' hidden' ?>>
    <h2 class="titulo-5 mb-md">Productos semiterminados</h2>
    <p class="campo__ayuda mb-md">Escribe cómo los nombra el proveedor y elige a qué producto de tu catálogo corresponden.
        Si todavía no sabes a cuál, déjalo sin relacionar y defínelo después: no se podrá recibir hasta relacionarlo.</p>
    <div class="tabla-contenedor">
        <table class="tabla" data-partidas>
            <caption class="solo-lectores">Productos semiterminados de la compra</caption>
            <thead><tr>
                <th scope="col">Nombre del proveedor</th>
                <th scope="col">Producto del catálogo</th>
                <th scope="col" class="tabla--numerica">Cantidad</th>
                <th scope="col" class="tabla--numerica">Costo unitario</th>
                <th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col"><span class="solo-lectores">Quitar</span></th>
            </tr></thead>
            <tbody data-partidas-cuerpo>
                <?php foreach ($filasSemi as $l) : ?>
                    <tr data-partida>
                        <td><input class="control" type="text" name="descripcion[]" maxlength="200" aria-label="Nombre del proveedor"
                                   placeholder="Ej. Canastilla sin pintar" value="<?= escaparValores($l['descripcion'] ?? '') ?>"></td>
                        <td>
                            <select class="control" name="producto_id[]" aria-label="Producto del catálogo">
                                <option value="0">— Relacionar después —</option>
                                <?php foreach ($catalogo as $prod) : ?>
                                    <option value="<?= (int) $prod['id'] ?>" data-costo-catalogo="<?= escaparValores((string) ($prod['costo'] ?? '')) ?>"
                                        <?= (int) ($l['producto_id'] ?? 0) === $prod['id'] ? 'selected' : '' ?>>
                                        <?= escaparValores(($prod['sku'] ? $prod['sku'] . ' · ' : '') . $prod['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="control" type="number" name="cantidad[]" min="1" step="1" inputmode="numeric" required
                                   aria-label="Cantidad" value="<?= (int) $l['cantidad'] ?>" data-cantidad></td>
                        <td><input class="control control--moneda" type="number" name="costo[]" min="0" step="0.01" inputmode="decimal"
                                   required aria-label="Costo unitario" value="<?= escaparValores(number_format((float) $l['costo'], 2, '.', '')) ?>" data-costo></td>
                        <td class="tabla--numerica cifra" data-importe>$0.00</td>
                        <td><button class="btn btn--peligro btn--sm btn--icono" type="button" data-quitar-partida aria-label="Quitar partida">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4" class="tabla--numerica"><strong>Subtotal</strong></td>
                <td class="tabla--numerica cifra"><strong data-total>$0.00</strong></td><td></td></tr></tfoot>
        </table>
    </div>
    <button class="btn btn--secundario mt-md" type="button" data-agregar-partida>
        <span class="material-symbols-outlined" aria-hidden="true">add</span><span>Agregar producto</span>
    </button>
</section>

<?php
/* ---- Tabla de insumos -------------------------------------------------------------------------- */
$filasInsumo = $tipo === 'insumo' ? $lineas : [$vacia];
?>
<section data-bloque-tipo="insumo"<?= $tipo === 'insumo' ? '' : ' hidden' ?>>
    <h2 class="titulo-5 mb-md">Materiales e insumos</h2>
    <p class="campo__ayuda mb-md">Describe lo que compraste. Los consumibles (pintura, discos, guantes…) solo se registran;
        si algo debe llevar existencias (varilla, lámina…) elige «Controlar en inventario» y escribe el nombre del material.
        Un material nunca se convierte en producto para venta.</p>
    <datalist id="lista-materiales">
        <?php foreach ($materiales as $m) : ?><option value="<?= escaparValores($m['nombre']) ?>"><?php endforeach; ?>
    </datalist>
    <div class="tabla-contenedor">
        <table class="tabla" data-partidas>
            <caption class="solo-lectores">Materiales e insumos de la compra</caption>
            <thead><tr>
                <th scope="col">Descripción</th>
                <th scope="col">Cantidad y unidad</th>
                <th scope="col" class="tabla--numerica">Costo unitario</th>
                <th scope="col">Inventario</th>
                <th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col"><span class="solo-lectores">Quitar</span></th>
            </tr></thead>
            <tbody data-partidas-cuerpo>
                <?php foreach ($filasInsumo as $l) : ?>
                    <tr data-partida>
                        <td><input class="control" type="text" name="descripcion[]" maxlength="200" aria-label="Descripción" required
                                   placeholder="Ej. Pintura esmalte negro" value="<?= escaparValores($l['descripcion'] ?? '') ?>"></td>
                        <td>
                            <input class="control" type="number" name="cantidad[]" min="0.001" step="0.001" inputmode="decimal" required
                                   aria-label="Cantidad" value="<?= escaparValores($cantidadTexto($l['cantidad'])) ?>" data-cantidad>
                            <input class="control mt-sm" type="text" name="unidad[]" maxlength="20" aria-label="Unidad de medida"
                                   placeholder="pza, kg, L, m…" value="<?= escaparValores($l['unidad'] ?? '') ?>">
                        </td>
                        <td><input class="control control--moneda" type="number" name="costo[]" min="0" step="0.01" inputmode="decimal"
                                   required aria-label="Costo unitario" value="<?= escaparValores(number_format((float) $l['costo'], 2, '.', '')) ?>" data-costo></td>
                        <td>
                            <select class="control" name="controla[]" aria-label="Control de inventario" data-controla>
                                <option value="0"<?= empty($l['controla']) ? ' selected' : '' ?>>Consumible (sin inventario)</option>
                                <option value="1"<?= !empty($l['controla']) ? ' selected' : '' ?>>Controlar en inventario</option>
                            </select>
                            <input class="control mt-sm" type="text" name="material_nuevo[]" maxlength="150" list="lista-materiales"
                                   aria-label="Nombre del material" placeholder="Nombre del material" data-material
                                   value="<?= escaparValores($l['material_nuevo'] ?? '') ?>"<?= empty($l['controla']) ? ' hidden' : '' ?>>
                        </td>
                        <td class="tabla--numerica cifra" data-importe>$0.00</td>
                        <td><button class="btn btn--peligro btn--sm btn--icono" type="button" data-quitar-partida aria-label="Quitar partida">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4" class="tabla--numerica"><strong>Subtotal</strong></td>
                <td class="tabla--numerica cifra"><strong data-total>$0.00</strong></td><td></td></tr></tfoot>
        </table>
    </div>
    <button class="btn btn--secundario mt-md" type="button" data-agregar-partida>
        <span class="material-symbols-outlined" aria-hidden="true">add</span><span>Agregar partida</span>
    </button>
</section>

<div class="campo campo--completo mt-lg">
    <label class="campo__etiqueta" for="observaciones">Observaciones</label>
    <textarea class="control" id="observaciones" name="observaciones" rows="3"><?= escaparValores($orden['observaciones'] ?? '') ?></textarea>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/compras/pedidos">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar compra</button>
</div>
