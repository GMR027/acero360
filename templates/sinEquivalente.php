<?php

/**
 * templates/sinEquivalente.php — «Conceptos sin producto equivalente» de una
 * factura leída con el registro inteligente.
 *
 * Lo comparten el formulario de gasto (templates/avisoLectura.php) y la revisión
 * de Compras Inteligentes: las dos pantallas ofrecen buscar el concepto en el
 * catálogo propio y, cuando el emisor ya es un proveedor con catálogo, agregarlo
 * directo a la ficha de ese proveedor (sin recargar: ver src/js/modulos/inteligente.js).
 *
 * Variables:
 *   $conceptos       — lista `sin_equivalente` de CoincidenciasFactura (obligatorio)
 *   $nombreDoc       — 'venta' | 'gasto' | 'compra' (sólo cambia los textos)
 *   $proveedorId     — int; 0 = sin proveedor reconocido y no se ofrece el alta
 *   $proveedorNombre — string; nombre del proveedor, para el texto del botón
 */

$conceptos       = $conceptos ?? [];
$nombreDoc       = $nombreDoc ?? 'gasto';
$proveedorId     = (int) ($proveedorId ?? 0);
$proveedorNombre = (string) ($proveedorNombre ?? '');

if (!$conceptos) {
    return;
}

/* Sólo se ofrece el alta si el emisor ya está en el directorio y está activo:
 * un proveedor desactivado no admite productos nuevos (lo rechaza el servidor). */
$sePuedeAgregar = $proveedorId > 0 && $proveedorNombre !== '';

/** Artículo del documento según su género («el gasto», no «la gasto»). */
$etiquetaDoc = ['venta' => 'la venta', 'gasto' => 'el gasto', 'compra' => 'la compra'][$nombreDoc] ?? 'el documento';
?>
<div class="tarjeta mb-md">
    <h2 class="titulo-5 mb-md">
        <span class="material-symbols-outlined" aria-hidden="true">rule</span>
        Conceptos sin producto equivalente (<?= number_format(count($conceptos)) ?>)
    </h2>
    <p class="campo__ayuda mb-md">
        Estos renglones del documento no coinciden con tu catálogo<?php if ($nombreDoc !== 'compra') : ?>,
        así que no entraron a <?= escaparValores($etiquetaDoc) ?><?php endif; ?>. Búscalos a mano con el botón
        de búsqueda, o agrega el producto al catálogo y vuelve a cargar la factura.
        <?php if ($sePuedeAgregar) : ?>
            Como el emisor ya es tu proveedor <strong><?= escaparValores($proveedorNombre) ?></strong>, también puedes
            agregar el concepto directo a <strong>su</strong> catálogo: la próxima vez que leas una factura de esa
            persona, ese renglón ya se reconocerá solo.
        <?php endif; ?>
    </p>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Conceptos de la factura sin producto en el catálogo</caption>
            <thead>
                <tr>
                    <th scope="col">Descripción del documento</th>
                    <th scope="col" class="tabla--numerica">Cantidad</th>
                    <th scope="col" class="tabla--numerica">Precio unitario</th>
                    <th scope="col" class="tabla--numerica">Importe</th>
                    <th scope="col" class="tabla--centro"><span class="solo-lectores">Acción</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conceptos as $concepto) : ?>
                    <tr>
                        <td data-label="Descripción"><?= escaparValores($concepto['descripcion']) ?>
                            <?php if (!empty($concepto['nota'])) : ?>
                                <br><span class="texto-secundario"><?= escaparValores($concepto['nota']) ?></span>
                            <?php endif; ?></td>
                        <td class="tabla--numerica cifra" data-label="Cantidad"><?= escaparValores(number_format($concepto['cantidad'], 2)) ?></td>
                        <td class="tabla--numerica cifra" data-label="Precio unitario"><?= $concepto['valor_unitario'] > 0 ? formatoMoneda($concepto['valor_unitario']) : '—' ?></td>
                        <td class="tabla--numerica cifra" data-label="Importe"><?= $concepto['importe'] > 0 ? formatoMoneda($concepto['importe']) : '—' ?></td>
                        <td class="tabla--centro" data-label="">
                            <div class="btn-grupo" data-acciones-concepto>
                                <button type="button" class="btn btn--secundario btn--sm"
                                        data-cargar-busqueda="<?= escaparValores($concepto['descripcion']) ?>"
                                        title="Escribe esta descripción en el buscador del catálogo">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                                    <span>Buscar en el catálogo</span>
                                </button>
                                <?php if ($sePuedeAgregar) : ?>
                                    <!-- Lo maneja src/js/modulos/inteligente.js: da de alta el concepto en el
                                         catálogo del proveedor SIN recargar la página (recargar perdería lo
                                         capturado en el formulario del gasto). -->
                                    <button type="button" class="btn btn--secundario btn--sm"
                                            data-agregar-proveedor
                                            data-proveedor-id="<?= $proveedorId ?>"
                                            data-proveedor-nombre="<?= escaparValores($proveedorNombre) ?>"
                                            data-descripcion="<?= escaparValores($concepto['descripcion']) ?>"
                                            data-sku="<?= escaparValores((string) ($concepto['sku'] ?? '')) ?>"
                                            data-nota="<?= escaparValores((string) ($concepto['nota'] ?? '')) ?>"
                                            data-costo="<?= escaparValores((string) ($concepto['valor_unitario'] ?? 0)) ?>"
                                            title="Agregar este concepto al catálogo de <?= escaparValores($proveedorNombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_shopping_cart</span>
                                        <span>Agregar al proveedor</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
