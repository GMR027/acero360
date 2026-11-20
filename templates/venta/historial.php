<?php
/**
 * templates/venta/historial.php — Historial de la entrega dentro del expediente.
 *
 * Variables: $historial (IncidenciasEntrega::historial).
 */
?>
<section class="tarjeta mb-lg" id="historial" style="scroll-margin-top:5rem">
    <h2 class="titulo-4">Historial de cambios de la entrega</h2>
    <?php if (!$historial) : ?>
        <p class="texto-secundario">Aún no hay movimientos: las entregas, devoluciones, cambios, incidencias y correcciones de factura aparecerán aquí.</p>
    <?php else : ?>
        <ol class="seguimiento-timeline">
            <?php foreach ($historial as $linea) : ?>
                <li class="seguimiento-timeline__item">
                    <span class="badge <?= $linea['clase'] ?>"><?= escaparValores($linea['tipo']) ?></span>
                    <div class="seguimiento-timeline__cuerpo">
                        <strong><?= escaparValores($linea['titulo']) ?></strong>
                        <?php if ($linea['descripcion'] !== '') : ?><span class="texto-secundario"><br><?= nl2br(escaparValores($linea['descripcion'])) ?></span><?php endif; ?>
                        <span class="texto-xs texto-secundario"><br><?= date('d/m/Y H:i', strtotime($linea['fecha'])) ?><?= $linea['usuario'] ? ' · ' . escaparValores($linea['usuario']) : '' ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
