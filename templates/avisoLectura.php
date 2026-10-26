<?php
/**
 * templates/avisoLectura.php — Lo que el motor leyó de una factura cargada con
 * el botón de registro inteligente («Registro Venta Inteligente» o «Registro
 * compra inteligente»).
 *
 * No guarda ni modifica nada: sólo cuenta qué se precargó, qué campos hay que
 * revisar a mano y qué conceptos del documento no se reconocieron.
 *
 * Variables: $propuesta (salida de Lecturas::propuesta()), $nombreDoc ('venta' | 'gasto'),
 *            $proveedorId y $proveedorNombre (opcionales: el proveedor ya elegido en
 *            el formulario; con ellos se ofrece agregar cada concepto sin equivalente
 *            directo a su catálogo — ver templates/sinEquivalente.php)
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
        <?php /* La tabla es compartida con Compras Inteligentes: aquí el proveedor viene del
                 propio documento (o del que ya eligió la persona en «Pagado a»). */ ?>
        <?php template('sinEquivalente', [
            'conceptos'       => $propuesta['sin_equivalente'],
            'nombreDoc'       => $nombreDoc,
            'proveedorId'     => (int) ($proveedorId ?? 0),
            'proveedorNombre' => (string) ($proveedorNombre ?? ($propuesta['proveedor']['nombre'] ?? '')),
        ]); ?>
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
