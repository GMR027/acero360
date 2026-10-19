<?php
/**
 * templates/formularioOrdenCompra.php — alta y edición de órdenes de compra.
 *
 * Variables: $orden (array), $lineas (array de partidas), $proveedores,
 *            $catalogo (Productos::paraCotizador), $errores
 */
$errores = $errores ?? [];
$lineas = $lineas ?: [['producto_id' => 0, 'cantidad' => 1, 'costo' => '0.00', 'descripcion' => '']];
?>
<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

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
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha_solicitud">Fecha de solicitud</label>
        <input class="control" type="date" id="fecha_solicitud" name="fecha_solicitud" required
               value="<?= escaparValores($orden['fecha_solicitud'] ?? date('Y-m-d')) ?>">
    </div>
</div>

<h2 class="titulo-5 mb-md">Productos solicitados</h2>
<p class="campo__ayuda mb-md">Elige el producto comercial terminado que corresponde a lo que entregará el proveedor
    (p. ej. «Registro número 3»); las unidades recibidas se suman a su inventario.</p>

<div class="tabla-contenedor">
    <table class="tabla" data-partidas>
        <thead>
            <tr>
                <th scope="col">Producto</th>
                <th scope="col">Nota del proveedor</th>
                <th scope="col" class="tabla--numerica">Cantidad</th>
                <th scope="col" class="tabla--numerica">Costo unitario</th>
                <th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col"><span class="solo-lectores">Quitar</span></th>
            </tr>
        </thead>
        <tbody data-partidas-cuerpo>
            <?php foreach ($lineas as $l) : ?>
                <tr data-partida>
                    <td>
                        <select class="control" name="producto_id[]" aria-label="Producto" required>
                            <option value="">— Producto —</option>
                            <?php foreach ($catalogo as $prod) : ?>
                                <option value="<?= (int) $prod['id'] ?>" data-costo="<?= escaparValores((string) ($prod['costo'] ?? '')) ?>"
                                    <?= (int) $l['producto_id'] === $prod['id'] ? 'selected' : '' ?>>
                                    <?= escaparValores(($prod['sku'] ? $prod['sku'] . ' · ' : '') . $prod['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input class="control" type="text" name="descripcion[]" maxlength="200" aria-label="Nota del proveedor"
                               placeholder="Ej. sin pintar" value="<?= escaparValores($l['descripcion'] ?? '') ?>"></td>
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
        <tfoot>
            <tr><td colspan="4" class="tabla--numerica"><strong>Total esperado</strong></td>
                <td class="tabla--numerica cifra"><strong data-total>$0.00</strong></td><td></td></tr>
        </tfoot>
    </table>
</div>
<button class="btn btn--secundario mt-md" type="button" data-agregar-partida>
    <span class="material-symbols-outlined" aria-hidden="true">add</span><span>Agregar producto</span>
</button>

<div class="campo campo--completo mt-lg">
    <label class="campo__etiqueta" for="observaciones">Observaciones</label>
    <textarea class="control" id="observaciones" name="observaciones" rows="3"><?= escaparValores($orden['observaciones'] ?? '') ?></textarea>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/compras">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar orden</button>
</div>
