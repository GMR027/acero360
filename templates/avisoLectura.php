<?php
/**
 * templates/avisoLectura.php — Lo que el motor leyó de una factura cargada con
 * el botón de registro inteligente («Registro Venta Inteligente» o «Registro
 * compra inteligente»).
 *
 * No guarda ni modifica nada: sólo cuenta qué se precargó, qué campos hay que
 * revisar a mano y qué conceptos del documento no se reconocieron.
 *
 * Variables: $propuesta (salida de Lecturas::propuesta()), $nombreDoc ('venta' | 'gasto')
 */

$propuesta = $propuesta ?? null;
$nombreDoc = $nombreDoc ?? 'venta';

if (!$propuesta) {
    return;
}

$fuente = \App\Lecturas::FUENTES[$propuesta['fuente']] ?? $propuesta['fuente'];

/** Campos que salieron con confianza «baja»: hay que revisarlos contra el documento. */
$porRevisar = [];
foreach ($propuesta['campos'] as $clave => $dato) {
    if (($dato['confianza'] ?? '') === 'baja') {
        $porRevisar[] = [
            'etiqueta' => \App\LectorDocumental::CAMPOS[$clave] ?? $clave,
            'valor'    => (string) ($dato['valor'] ?? ''),
        ];
    }
}

$etiqueta = $nombreDoc === 'venta' ? 'de esta venta' : 'de esta compra';
?>
<section class="mb-lg" data-lectura-cargada>
    <div class="nota nota--advertencia mb-md" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">document_scanner</span>
        <span>
            <strong>Datos leídos de «<?= escaparValores($propuesta['nombre']) ?>» (<?= escaparValores($fuente) ?>).</strong>
            Se cargaron <?= number_format(count($propuesta['lineas'])) ?> renglón(es) con producto equivalente.
            Revisa el formulario <?= escaparValores($etiqueta) ?> antes de guardarlo: nada se ha registrado todavía.
        </span>
    </div>

    <?php if ($propuesta['sin_equivalente']) : ?>
        <div class="tarjeta mb-md">
            <h2 class="titulo-5 mb-md">
                <span class="material-symbols-outlined" aria-hidden="true">rule</span>
                Conceptos sin producto equivalente (<?= number_format(count($propuesta['sin_equivalente'])) ?>)
            </h2>
            <p class="campo__ayuda mb-md">
                Estos renglones del documento no coinciden con tu catálogo, así que no entraron a la
                <?= escaparValores($nombreDoc) ?>. Búscalos a mano con el botón de búsqueda, o agrega el
                producto al catálogo y vuelve a cargar la factura.
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
                        <?php foreach ($propuesta['sin_equivalente'] as $concepto) : ?>
                            <tr>
                                <td data-label="Descripción"><?= escaparValores($concepto['descripcion']) ?>
                                    <?php if (!empty($concepto['nota'])) : ?>
                                        <br><span class="texto-secundario"><?= escaparValores($concepto['nota']) ?></span>
                                    <?php endif; ?></td>
                                <td class="tabla--numerica cifra" data-label="Cantidad"><?= escaparValores(number_format($concepto['cantidad'], 2)) ?></td>
                                <td class="tabla--numerica cifra" data-label="Precio unitario"><?= $concepto['valor_unitario'] > 0 ? formatoMoneda($concepto['valor_unitario']) : '—' ?></td>
                                <td class="tabla--numerica cifra" data-label="Importe"><?= $concepto['importe'] > 0 ? formatoMoneda($concepto['importe']) : '—' ?></td>
                                <td class="tabla--centro" data-label="">
                                    <button type="button" class="btn btn--secundario btn--sm"
                                            data-cargar-busqueda="<?= escaparValores($concepto['descripcion']) ?>"
                                            title="Escribe esta descripción en el buscador del catálogo">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                                        <span>Buscar en el catálogo</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($porRevisar) : ?>
        <details class="mb-md">
            <summary class="boton-enlace" style="cursor:pointer">
                <strong>Datos que conviene revisar (<?= number_format(count($porRevisar)) ?>)</strong>
                — salieron con poca confianza
            </summary>
            <ul class="lista-simple mt-md">
                <?php foreach ($porRevisar as $campo) : ?>
                    <li>
                        <strong><?= escaparValores($campo['etiqueta']) ?>:</strong>
                        <?= $campo['valor'] !== '' ? escaparValores($campo['valor']) : '<em>sin detectar</em>' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <?php foreach ($propuesta['avisos'] as $aviso) : ?>
        <p class="nota nota--advertencia mb-md" role="note">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
            <span><?= escaparValores($aviso) ?></span>
        </p>
    <?php endforeach; ?>
</section>
