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
$catalogos = $catalogos ?? [];
$tipo = $orden['tipo'] ?? 'semiterminado';
$lineas = $lineas ?: [['producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'cantidad' => 1, 'costo' => '0.00', 'descripcion' => '', 'unidad' => '', 'controla' => 0, 'aplica_iva' => 1, 'proveedor_producto_id' => 0]];
$vacia = ['producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'cantidad' => 1, 'costo' => '0.00', 'descripcion' => '', 'unidad' => '', 'controla' => 0, 'aplica_iva' => 1, 'proveedor_producto_id' => 0];
// Tasa de IVA: la de la compra si ya tiene una; en una compra nueva, la del catálogo fiscal activo (si no hay, vacía: IVA a mano).
$tasaIva = isset($orden['id']) ? ($orden['iva_tasa'] ?? '') : (\App\OrdenesCompra::tasaIvaVigente((string) ($orden['fecha_solicitud'] ?? date('Y-m-d'))) ?? '');
$tasaIva = $tasaIva !== '' ? rtrim(rtrim(number_format((float) $tasaIva, 2, '.', ''), '0'), '.') : '';
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
        <label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Día en que generas la solicitud (hoy por defecto). La fecha de recepción se captura en cada recepción y la de la factura sale de su CFDI." for="fecha_solicitud">Fecha de la solicitud</label>
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
        <label class="campo__etiqueta" data-ayuda="Porcentaje que se aplica a las partidas marcadas «Con IVA». Viene del catálogo fiscal activo; si no hay uno, déjalo vacío y captura el IVA de la factura." for="iva_tasa">Tasa de IVA (%)</label>
        <input class="control" type="number" id="iva_tasa" name="iva_tasa" min="0" max="30" step="0.01" inputmode="decimal"
               value="<?= escaparValores($tasaIva) ?>" placeholder="Sin tasa">
        <span class="campo__ayuda"><?= $tasaIva !== '' && !isset($orden['id']) ? 'Tasa general del catálogo fiscal activo.' : 'Sin tasa, el IVA se captura a mano.' ?></span>
    </div>
    <div class="campo">
        <label class="campo__etiqueta" data-ayuda="Se calcula con la base de las partidas «Con IVA» y la tasa; puedes corregirlo para que coincida con la factura. Vacío significa «no especificado»." for="iva">IVA de la compra</label>
        <input class="control control--moneda" type="number" id="iva" name="iva" min="0" step="0.01" inputmode="decimal"
               value="<?= escaparValores($orden['iva'] ?? '') ?>" placeholder="Automático">
        <button class="btn btn--texto btn--sm" type="button" data-calcular-iva>Recalcular IVA</button>
        <span class="campo__ayuda">En pesos. Se suma al total de la compra. Base gravable: <strong class="cifra" data-base-gravable>$0.00</strong>.</span>
    </div>
</div>

<?php
/* ---- Tabla de semiterminados ------------------------------------------------------------------ */
$filasSemi = $tipo === 'semiterminado' ? $lineas : [$vacia];
$catalogoProveedor = $catalogos[(int) ($orden['proveedor_id'] ?? 0)] ?? [];
?>
<script type="application/json" id="catalogo-proveedores"><?= json_encode($catalogos ?? new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<section data-bloque-tipo="semiterminado"<?= $tipo === 'semiterminado' ? '' : ' hidden' ?>>
    <h2 class="titulo-5 mb-md">Productos semiterminados</h2>
    <p class="campo__ayuda mb-md">Elige al proveedor y luego sus productos del catálogo: el nombre, el código y el costo se llenan solos y el costo queda bloqueado
        (puedes cambiarlo con «Cambiar costo»). ¿No está en el catálogo? Elige «Otro» y escríbelo, o
        <a href="/admin/compras/catalogo/crear" target="_blank" rel="noopener">agrégalo al catálogo</a>.</p>
    <div class="tabla-contenedor">
        <table class="tabla" data-partidas>
            <caption class="solo-lectores">Productos semiterminados de la compra</caption>
            <thead><tr>
                <th scope="col">Producto del proveedor</th>
                <th scope="col">Producto propio</th>
                <th scope="col" class="tabla--numerica">Cantidad</th>
                <th scope="col" class="tabla--numerica">Costo unitario</th>
                <th scope="col">IVA</th>
                <th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col"><span class="solo-lectores">Quitar</span></th>
            </tr></thead>
            <tbody data-partidas-cuerpo>
                <?php foreach ($filasSemi as $l) :
                    $delCatalogo = (int) ($l['proveedor_producto_id'] ?? 0) > 0;
                    $modificado = $delCatalogo && ($l['costo_catalogo'] ?? null) !== null && abs((float) $l['costo'] - (float) $l['costo_catalogo']) > 0.004;
                    ?>
                    <tr data-partida>
                        <td>
                            <input class="control mb-sm" type="search" aria-label="Buscar en el catálogo por nombre o código" placeholder="Buscar por nombre o código" autocomplete="off" data-buscar-catalogo>
                            <select class="control" name="proveedor_producto_id[]" aria-label="Producto del catálogo del proveedor" data-catalogo>
                                <option value="0">— Otro / fuera del catálogo —</option>
                                <?php foreach ($catalogoProveedor as $it) : ?>
                                    <option value="<?= (int) $it['id'] ?>" <?= (int) ($l['proveedor_producto_id'] ?? 0) === $it['id'] ? 'selected' : '' ?>>
                                        <?= escaparValores(($it['codigo'] !== '' ? $it['codigo'] . ' · ' : '') . $it['nombre'] . ($it['activo'] ? '' : ' (inactivo)')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input class="control mt-sm" type="text" name="descripcion[]" maxlength="200" aria-label="Nombre del producto del proveedor"
                                   placeholder="Nombre como lo llama el proveedor" value="<?= escaparValores($l['descripcion'] ?? '') ?>"<?= $delCatalogo ? ' readonly' : '' ?> data-descripcion>
                            <span class="texto-xs texto-secundario" data-codigo><?= $delCatalogo && ($l['codigo'] ?? '') !== '' ? 'Código ' . escaparValores($l['codigo']) : '' ?></span>
                        </td>
                        <td>
                            <select class="control" name="producto_id[]" aria-label="Producto propio al que corresponde">
                                <option value="0">— Relacionar después —</option>
                                <?php foreach ($catalogo as $prod) : ?>
                                    <option value="<?= (int) $prod['id'] ?>" <?= (int) ($l['producto_id'] ?? 0) === $prod['id'] ? 'selected' : '' ?>>
                                        <?= escaparValores(($prod['sku'] ? $prod['sku'] . ' · ' : '') . $prod['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="control" type="number" name="cantidad[]" min="1" step="1" inputmode="numeric" required
                                   aria-label="Cantidad" value="<?= (int) $l['cantidad'] ?>" data-cantidad></td>
                        <td>
                            <input class="control control--moneda" type="number" name="costo[]" min="0" step="0.01" inputmode="decimal"
                                   required aria-label="Costo unitario" value="<?= escaparValores(number_format((float) $l['costo'], 2, '.', '')) ?>"
                                   <?= $delCatalogo && !$modificado ? 'readonly' : '' ?> data-costo>
                            <button class="btn btn--texto btn--sm" type="button" data-cambiar-costo<?= $delCatalogo ? '' : ' hidden' ?>>Cambiar costo</button>
                            <span class="texto-xs texto-secundario" data-aviso-costo><?= $modificado ? 'Catálogo: ' . escaparValores(number_format((float) $l['costo_catalogo'], 2)) : '' ?></span>
                        </td>
                        <td><select class="control" name="aplica_iva[]" aria-label="Aplica IVA" data-aplica-iva>
                                <option value="1"<?= !isset($l['aplica_iva']) || !empty($l['aplica_iva']) ? ' selected' : '' ?>>Con IVA</option>
                                <option value="0"<?= isset($l['aplica_iva']) && empty($l['aplica_iva']) ? ' selected' : '' ?>>Sin IVA</option>
                            </select></td>
                        <td class="tabla--numerica cifra" data-importe>$0.00</td>
                        <td><button class="btn btn--peligro btn--sm btn--icono" type="button" data-quitar-partida aria-label="Quitar partida">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5" class="tabla--numerica"><strong>Subtotal</strong></td>
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
                <th scope="col">IVA</th>
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
                        <td><select class="control" name="aplica_iva[]" aria-label="Aplica IVA" data-aplica-iva>
                                <option value="1"<?= !isset($l['aplica_iva']) || !empty($l['aplica_iva']) ? ' selected' : '' ?>>Con IVA</option>
                                <option value="0"<?= isset($l['aplica_iva']) && empty($l['aplica_iva']) ? ' selected' : '' ?>>Sin IVA</option>
                            </select></td>
                        <td class="tabla--numerica cifra" data-importe>$0.00</td>
                        <td><button class="btn btn--peligro btn--sm btn--icono" type="button" data-quitar-partida aria-label="Quitar partida">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5" class="tabla--numerica"><strong>Subtotal</strong></td>
                <td class="tabla--numerica cifra"><strong data-total>$0.00</strong></td><td></td></tr></tfoot>
        </table>
    </div>
    <button class="btn btn--secundario mt-md" type="button" data-agregar-partida>
        <span class="material-symbols-outlined" aria-hidden="true">add</span><span>Agregar partida</span>
    </button>
</section>

<p class="mt-lg texto-lg" aria-live="polite">Total de la compra: <strong class="cifra" data-total-compra>$0.00</strong>
    <span class="texto-sm texto-secundario">(subtotal + IVA)</span></p>

<div class="campo campo--completo mt-lg">
    <label class="campo__etiqueta" for="observaciones">Observaciones</label>
    <textarea class="control" id="observaciones" name="observaciones" rows="3"><?= escaparValores($orden['observaciones'] ?? '') ?></textarea>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/compras/pedidos">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar compra</button>
</div>
